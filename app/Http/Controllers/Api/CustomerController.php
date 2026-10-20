<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SocialCommentEvent;
use App\Models\CustomerNote;
use App\Models\Channel;
use App\Models\CommerceOrder;
use App\Models\ContactConsent;
use App\Services\AuditLoggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    /**
     * Resolve the business profile for the current request.
     */
    private function getResolvedBusinessProfile(Request $request, string $permission = 'read'): \App\Models\BusinessProfile
    {
        return app(\App\Services\ActiveBusiness::class)->resolve($request, permission: $permission);
    }

    /**
     * Get paginated list of customers for the authenticated user's business.
     */
    public function index(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request, 'read');

        $query = Customer::query()->where('business_profile_id', $business->id);

        // Search filter
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Tag filter
        if ($request->has('tag') && !empty($request->tag)) {
            $query->whereJsonContains('tags', $request->tag);
        }

        if ($request->filled('lifecycle_stage')) {
            $query->where('custom_fields->lifecycle_stage', $request->string('lifecycle_stage')->toString());
        }
        if ($request->filled('channel_id')) {
            $channel = Channel::query()->where('business_id', $business->id)->findOrFail($request->integer('channel_id'));
            $query->whereHas('conversations', fn ($q) => $q->where('channel_id', $channel->id));
        }
        if ($request->boolean('has_email')) $query->whereNotNull('email')->where('email', '!=', '');
        if ($request->boolean('has_phone')) $query->whereNotNull('phone')->where('phone', '!=', '');

        $customers = $query->withCount('conversations')
            ->withMax('conversations', 'last_message_at')
            ->orderBy('created_at', 'desc')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json($customers);
    }

    /**
     * Get customer details with conversations, notes, and orders.
     */
    public function show(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request, 'read');

        $customer = Customer::where('business_profile_id', $business->id)
            ->with([
                'conversations' => function ($q) {
                    $q->select(['id', 'business_id', 'channel_id', 'customer_id', 'bot_id', 'sender_name', 'subject', 'status', 'last_message_at', 'created_at'])
                        ->with('channel:id,type,page_name,page_id')->orderByDesc('last_message_at')->limit(20);
                },
                'notes' => function ($q) {
                    $q->with('author:id,name')->orderBy('created_at', 'desc')->limit(20);
                },
                'identities' => fn ($q) => $q->with('channel:id,type,page_name,page_id')->orderBy('id'),
            ])
            ->findOrFail($id);

        $identifiers = array_values(array_filter(array_unique([
            $customer->email ? mb_strtolower(trim($customer->email)) : null,
            $customer->phone ?: null,
            $customer->phone ? preg_replace('/\D+/', '', $customer->phone) : null,
        ])));
        $orders = empty($identifiers) ? collect() : CommerceOrder::query()
            ->with('channel:id,type,page_name')
            ->where('business_id', $business->id)
            ->where(function ($q) use ($customer) {
                if ($customer->email) $q->orWhere('customer_email', $customer->email);
                if ($customer->phone) {
                    $digits = preg_replace('/\D+/', '', $customer->phone);
                    $q->orWhere('customer_phone', $customer->phone)->orWhere('customer_phone', $digits);
                }
            })
            ->orderByDesc('ordered_at')->limit(50)
            ->get(['id', 'business_id', 'channel_id', 'external_id', 'order_number', 'status', 'fulfillment_status', 'total', 'currency', 'ordered_at']);

        $consents = empty($identifiers) ? collect() : ContactConsent::query()
            ->where('business_id', $business->id)
            ->whereIn('contact_identifier', $identifiers)
            ->orderByDesc('captured_at')->get(['channel', 'scope', 'status', 'source', 'captured_at']);

        // Comment activity belongs to a contact only when the commenter ID
        // matches a stable identity from that exact connected account.
        $identityRows = $customer->identities;
        $comments = collect();
        if ($identityRows->isNotEmpty()) {
            $comments = SocialCommentEvent::query()
                ->where('business_id', $business->id)
                ->where(function ($query) use ($identityRows): void {
                    foreach ($identityRows as $identity) {
                        $query->orWhere(function ($identityQuery) use ($identity): void {
                            $identityQuery->where('channel_id', $identity->channel_id)
                                ->where('platform', $identity->platform)
                                ->where('commenter_id', $identity->external_id);
                        });
                    }
                })
                ->orderByDesc('platform_created_at')
                ->limit(20)
                ->get(['id', 'channel_id', 'platform', 'post_id', 'comment_id', 'comment_text', 'platform_created_at', 'status', 'public_reply_id']);
        }

        return response()->json([
            'customer' => $customer,
            'orders' => $orders,
            'consents' => $consents,
            'comments' => $comments,
        ]);
    }

    /**
     * Store an internal note for a customer.
     */
    public function storeNote(Request $request, $customerId)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');

        $customer = Customer::where('business_profile_id', $business->id)->findOrFail($customerId);

        $data = $request->validate([
            'content' => 'required|string|max:5000',
            'conversation_id' => 'nullable|integer|exists:conversations,id',
        ]);

        if (!empty($data['conversation_id'])) {
            abort_unless(\App\Models\Conversation::query()
                ->where('business_id', $business->id)
                ->where('customer_id', $customer->id)
                ->whereKey($data['conversation_id'])->exists(), 422, 'The conversation does not belong to this customer.');
        }

        $note = CustomerNote::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'conversation_id' => $data['conversation_id'] ?? null,
            'content' => $data['content'],
        ]);

        // Audit log
        AuditLoggerService::log('customer.note_created', $note, [
            'customer_id' => $customer->id,
        ], Auth::id(), (int) $business->id);

        return response()->json([
            'message' => 'Note created successfully',
            'note' => $note->load('author:id,name'),
        ], 201);
    }

    /**
     * Update customer tags and custom fields.
     */
    public function updateTags(Request $request, $customerId)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');

        $customer = Customer::where('business_profile_id', $business->id)->findOrFail($customerId);

        $request->validate([
            'tags' => 'nullable|array',
            'custom_fields' => 'nullable|array',
            'custom_fields.lifecycle_stage' => 'sometimes|nullable|string|in:new_lead,qualified,customer,lost',
            'custom_fields.language' => 'sometimes|nullable|string|max:20',
            'custom_fields.source' => 'sometimes|nullable|string|max:100',
            'lead_score' => 'nullable|integer|min:0|max:100',
        ]);

        $customFields = $customer->custom_fields ?? [];
        foreach ($request->input('custom_fields', []) as $key => $value) {
            if (!in_array($key, ['lifecycle_stage', 'language', 'source'], true)) {
                return response()->json(['message' => 'This customer field is managed by the connected account and cannot be edited here.'], 422);
            }
            $customFields[$key] = $value;
        }

        $update = $request->only(['tags', 'lead_score']);
        if ($request->exists('custom_fields')) $update['custom_fields'] = $customFields;
        $customer->update($update);

        // Audit log
        AuditLoggerService::log('customer.updated', $customer, [
            'updated_fields' => array_keys($request->only(['tags', 'custom_fields', 'lead_score'])),
        ], Auth::id(), (int) $business->id);

        return response()->json([
            'message' => 'Customer updated successfully',
            'customer' => $customer->fresh(),
        ]);
    }

    public function updateProfile(Request $request, $customerId)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $customer = Customer::query()->where('business_profile_id', $business->id)->findOrFail($customerId);
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:64',
            'avatar' => 'nullable|url|max:2048',
            'tags' => 'nullable|array|max:50',
            'tags.*' => 'string|max:80',
            'lead_score' => 'nullable|integer|min:0|max:100',
            'lifecycle_stage' => 'nullable|string|in:new_lead,qualified,customer,lost',
            'language' => 'nullable|string|max:20',
            'source' => 'nullable|string|max:100',
        ]);

        $phone = $data['phone'] ?? null;
        $email = isset($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        $duplicate = Customer::query()->where('business_profile_id', $business->id)->where('id', '!=', $customer->id)
            ->where(function ($q) use ($phone, $email) {
                if ($email) $q->orWhereRaw('LOWER(email) = ?', [$email]);
                if ($phone) $q->orWhere('phone', $phone);
            })->exists();
        if ($duplicate) return response()->json(['message' => 'That email address or phone already belongs to another contact. Review the profiles before merging.'], 409);

        $customFields = $customer->custom_fields ?? [];
        foreach (['lifecycle_stage', 'language', 'source'] as $field) {
            if (array_key_exists($field, $data)) $customFields[$field] = $data[$field];
        }
        $customer->fill(array_intersect_key($data, array_flip(['name', 'email', 'phone', 'avatar', 'tags', 'lead_score'])));
        $customer->custom_fields = $customFields;
        $customer->save();

        AuditLoggerService::log('customer.profile_updated', $customer, [
            'updated_fields' => array_values(array_intersect(array_keys($data), ['name', 'email', 'phone', 'avatar', 'tags', 'lead_score', 'lifecycle_stage', 'language', 'source'])),
        ], Auth::id(), (int) $business->id);

        return response()->json(['customer' => $customer->fresh()]);
    }

    public function export(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request, 'read');
        $query = Customer::query()->where('business_profile_id', $business->id)->orderBy('id');
        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($request->filled('lifecycle_stage')) $query->where('custom_fields->lifecycle_stage', $request->string('lifecycle_stage')->toString());
        if ($request->filled('tag')) $query->whereJsonContains('tags', $request->string('tag')->toString());
        if ($request->filled('channel_id')) {
            $channel = Channel::query()->where('business_id', $business->id)->findOrFail($request->integer('channel_id'));
            $query->whereHas('conversations', fn ($q) => $q->where('channel_id', $channel->id));
        }
        $rows = $query->limit(10000)->get();
        $filename = 'contacts-' . $business->id . '-' . now()->format('Ymd-His') . '.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $safe = static function ($value): string {
                $text = (string) ($value ?? '');
                return preg_match('/^[=+\-@\t\r]/u', $text) ? "'" . $text : $text;
            };
            fputcsv($out, ['id', 'name', 'email', 'phone', 'lifecycle_stage', 'language', 'source', 'lead_score', 'tags', 'created_at']);
            foreach ($rows as $customer) {
                $fields = $customer->custom_fields ?? [];
                fputcsv($out, array_map($safe, [$customer->id, $customer->name, $customer->email, $customer->phone, $fields['lifecycle_stage'] ?? '', $fields['language'] ?? '', $fields['source'] ?? '', $customer->lead_score, implode('|', $customer->tags ?? []), $customer->created_at?->toIso8601String()]));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:10240', 'confirm' => 'nullable|boolean']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (!$handle) return response()->json(['message' => 'Could not read the uploaded CSV.'], 422);
        $headers = fgetcsv($handle);
        $allowed = ['name', 'email', 'phone', 'lifecycle_stage', 'language', 'source', 'lead_score', 'tags'];
        $headers = array_map(fn ($header) => mb_strtolower(trim((string) $header)), $headers ?: []);
        if (isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        if (!$headers || count($headers) !== count(array_unique($headers)) || array_diff($headers, $allowed) || !in_array('email', $headers, true) && !in_array('phone', $headers, true)) {
            fclose($handle);
            return response()->json(['message' => 'CSV needs supported headers and at least an email or phone column.', 'supported_headers' => $allowed], 422);
        }
        $rows = []; $errors = []; $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if (count($rows) >= 1000) { $errors[] = ['row' => $line, 'error' => 'Maximum 1,000 records per import.']; break; }
            if (count($values) !== count($headers)) { $errors[] = ['row' => $line, 'error' => 'Column count does not match the header.']; continue; }
            $row = array_combine($headers, array_map(fn ($value) => trim((string) $value), $values));
            $validator = validator($row, [
                'name' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:64',
                'lifecycle_stage' => 'nullable|in:new_lead,qualified,customer,lost', 'language' => 'nullable|string|max:20',
                'source' => 'nullable|string|max:100', 'lead_score' => 'nullable|integer|min:0|max:100', 'tags' => 'nullable|string|max:1000',
            ]);
            if ($validator->fails() || empty($row['email']) && empty($row['phone'])) {
                $errors[] = ['row' => $line, 'error' => $validator->errors()->first() ?: 'Email or phone is required.'];
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);
        if ($request->boolean('confirm') && $errors) return response()->json(['message' => 'Fix invalid rows before importing; no contacts were changed.', 'valid_rows' => count($rows), 'errors' => $errors], 422);
        if (!$request->boolean('confirm')) return response()->json(['preview' => true, 'valid_rows' => count($rows), 'invalid_rows' => count($errors), 'errors' => $errors]);

        $created = 0; $skipped = 0;
        DB::transaction(function () use ($rows, $business, &$created, &$skipped) {
            foreach ($rows as $row) {
                $email = !empty($row['email']) ? mb_strtolower(trim($row['email'])) : null;
                $phone = $row['phone'] ?? null;
                $exists = Customer::query()->where('business_profile_id', $business->id)->where(function ($q) use ($email, $phone) {
                    if ($email) $q->orWhereRaw('LOWER(email) = ?', [$email]);
                    if ($phone) $q->orWhere('phone', $phone);
                })->exists();
                if ($exists) { $skipped++; continue; }
                $fields = array_filter(array_intersect_key($row, array_flip(['lifecycle_stage', 'language', 'source'])), fn ($v) => $v !== '');
                Customer::create([
                    'business_profile_id' => $business->id, 'name' => $row['name'] ?: null,
                    'email' => $email, 'phone' => $phone ?: null,
                    'lead_score' => isset($row['lead_score']) ? (int) $row['lead_score'] : 0,
                    'tags' => !empty($row['tags']) ? array_values(array_filter(array_map('trim', explode('|', $row['tags'])))) : [],
                    'custom_fields' => $fields,
                ]);
                $created++;
            }
        });
        AuditLoggerService::log('customer.import_completed', $business, ['created' => $created, 'skipped_existing' => $skipped], Auth::id(), (int) $business->id);
        return response()->json(['created' => $created, 'skipped_existing' => $skipped]);
    }
}
