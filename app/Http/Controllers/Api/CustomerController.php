<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerMergeRecord;
use App\Models\SocialCommentEvent;
use App\Models\CustomerNote;
use App\Models\Channel;
use App\Models\CommerceOrder;
use App\Models\ContactConsent;
use App\Models\TeamMember;
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

        $query = Customer::query()->where('business_profile_id', $business->id)->whereNull('merged_into_customer_id');

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

        $customers = $query->with('assignee:id,name')->with('identities:id,customer_id,platform,external_id')->withCount('conversations')
            ->withMax('conversations', 'last_message_at')
            ->orderBy('created_at', 'desc')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        $customers->getCollection()->each(function (Customer $customer): void {
            $canDisplayPhone = $this->hasDirectWhatsAppIdentity($customer);
            $customer->setAttribute('phone_is_displayable', $canDisplayPhone);
            if (!$canDisplayPhone) {
                $customer->setAttribute('phone', null);
            }
            $customer->makeHidden('identities');
        });

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
                'assignee:id,name,email',
                'mergedInto:id,name,email',
                'mergeRecordsAsTarget' => fn ($query) => $query->whereNull('reversed_at')->with('source:id,name,email')->orderByDesc('id'),
            ])
            ->findOrFail($id);

        $customer->setAttribute('phone_is_displayable', $this->hasDirectWhatsAppIdentity($customer));

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

        if (!$customer->phone_is_displayable) {
            $customer->setAttribute('phone', null);
        }
        $customer->makeHidden('identities');

        return response()->json([
            'customer' => $customer,
            'orders' => $orders,
            'consents' => $consents,
            'comments' => $comments,
        ]);
    }

    /** A phone is a callable phone only when this contact has a direct WhatsApp identity. */
    private function hasDirectWhatsAppIdentity(Customer $customer): bool
    {
        if (!is_string($customer->phone) || trim($customer->phone) === '') {
            return false;
        }

        return $customer->identities->contains(function ($identity): bool {
            if (strtolower((string) $identity->platform) !== 'whatsapp') {
                return false;
            }

            $externalId = trim((string) $identity->external_id);
            if ($externalId === '' || preg_match('/@(g\.us|broadcast|lid)$/i', $externalId) === 1) {
                return false;
            }

            $phoneIdentity = preg_replace('/@s\.whatsapp\.net$/i', '', $externalId);
            return preg_match('/^\+?[0-9]{7,15}$/', $phoneIdentity) === 1;
        });
    }

    /**
     * Store an internal note for a customer.
     */
    public function storeNote(Request $request, $customerId)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');

        $customer = Customer::where('business_profile_id', $business->id)->findOrFail($customerId);
        $this->assertNotMerged($customer);

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
        $this->assertNotMerged($customer);

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
        $this->assertNotMerged($customer);
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
            'assigned_to' => 'nullable|integer',
        ]);

        if (array_key_exists('assigned_to', $data)) {
            $this->validateAssignee($business->id, (int) $business->user_id, $data['assigned_to']);
        }

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
        $customer->fill(array_intersect_key($data, array_flip(['name', 'email', 'phone', 'avatar', 'tags', 'lead_score', 'assigned_to'])));
        $customer->custom_fields = $customFields;
        $customer->save();

        AuditLoggerService::log('customer.profile_updated', $customer, [
            'updated_fields' => array_values(array_intersect(array_keys($data), ['name', 'email', 'phone', 'avatar', 'tags', 'lead_score', 'lifecycle_stage', 'language', 'source', 'assigned_to'])),
        ], Auth::id(), (int) $business->id);

        return response()->json(['customer' => $customer->fresh()->load('assignee:id,name,email')]);
    }

    public function assignees(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request, 'read');
        $members = TeamMember::query()->where('business_id', $business->id)->where('is_active', true)
            ->whereIn('role', ['owner', 'manager', 'agent'])->with('user:id,name,email')
            ->get()->map(fn (TeamMember $member) => ['id' => $member->user_id, 'name' => $member->user?->name, 'email' => $member->user?->email, 'role' => $member->role])->values();
        $owner = \App\Models\User::query()->find($business->user_id);
        if ($owner && !$members->contains(fn ($member) => (int) $member['id'] === (int) $owner->id)) {
            $members->prepend(['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'role' => 'owner']);
        }

        return response()->json(['assignees' => $members->values()]);
    }

    private function validateAssignee(int $businessId, int $ownerId, ?int $userId): void
    {
        if ($userId === null || $userId === $ownerId) return;
        TeamMember::query()->where('business_id', $businessId)->where('user_id', $userId)->where('is_active', true)
            ->whereIn('role', ['owner', 'manager', 'agent'])->firstOrFail();
    }

    public function merge(Request $request, int $customerId)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $data = $request->validate(['target_customer_id' => ['required', 'integer']]);
        abort_if((int) $data['target_customer_id'] === $customerId, 422, 'Choose a different contact to merge into.');

        $record = DB::transaction(function () use ($business, $customerId, $data, $request): CustomerMergeRecord {
            $locked = Customer::query()->where('business_profile_id', $business->id)
                ->whereIn('id', [$customerId, (int) $data['target_customer_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked->get($customerId);
            $target = $locked->get((int) $data['target_customer_id']);
            abort_unless($source && $target, 404);
            $this->assertNotMerged($source);
            $this->assertNotMerged($target);
            abort_if($source->mergeRecordsAsTarget()->whereNull('reversed_at')->exists(), 409, 'Undo merges into this contact before merging it elsewhere.');

            $targetBefore = $this->mergeableSnapshot($target);
            $targetValues = $targetBefore;
            foreach (['name', 'email', 'phone', 'avatar', 'assigned_to'] as $field) {
                if (($targetValues[$field] === null || $targetValues[$field] === '') && $source->getAttribute($field) !== null && $source->getAttribute($field) !== '') {
                    $targetValues[$field] = $source->getAttribute($field);
                }
            }
            $targetValues['lead_score'] = max((int) ($targetBefore['lead_score'] ?? 0), (int) ($source->lead_score ?? 0));
            $targetValues['tags'] = array_values(array_unique(array_merge($targetBefore['tags'] ?? [], $source->tags ?? [])));
            $targetValues['custom_fields'] = array_replace($source->custom_fields ?? [], $targetBefore['custom_fields'] ?? []);
            $target->fill($targetValues)->save();
            $targetAfter = $this->mergeableSnapshot($target->fresh());

            $conversationIds = \App\Models\Conversation::query()->where('business_id', $business->id)
                ->where('customer_id', $source->id)->orderBy('id')->pluck('id')->all();
            if ($conversationIds) \App\Models\Conversation::query()->whereIn('id', $conversationIds)->update(['customer_id' => $target->id]);

            $noteIds = CustomerNote::query()->where('customer_id', $source->id)->orderBy('id')->pluck('id')->all();
            if ($noteIds) CustomerNote::query()->whereIn('id', $noteIds)->update(['customer_id' => $target->id]);

            $identityIds = \App\Models\CustomerIdentity::query()->where('business_profile_id', $business->id)
                ->where('customer_id', $source->id)->orderBy('id')->pluck('id')->all();
            if ($identityIds) \App\Models\CustomerIdentity::query()->whereIn('id', $identityIds)->update(['customer_id' => $target->id]);

            $source->update(['merged_into_customer_id' => $target->id]);
            $merge = CustomerMergeRecord::create([
                'business_profile_id' => $business->id,
                'source_customer_id' => $source->id,
                'target_customer_id' => $target->id,
                'performed_by' => $request->user()->id,
                'target_before' => $targetBefore,
                'target_after' => $targetAfter,
                'conversation_ids' => $conversationIds,
                'note_ids' => $noteIds,
                'identity_ids' => $identityIds,
            ]);
            AuditLoggerService::log('customer.merged', $merge, [
                'source_customer_id' => $source->id, 'target_customer_id' => $target->id,
                'moved_conversations' => count($conversationIds), 'moved_notes' => count($noteIds), 'moved_identities' => count($identityIds),
            ], Auth::id(), (int) $business->id);

            return $merge;
        });

        return response()->json([
            'message' => 'Contacts merged. You can undo this merge from the target contact profile.',
            'merge' => $record->load('source:id,name,email', 'target:id,name,email'),
        ], 201);
    }

    public function unmerge(Request $request, int $mergeId)
    {
        $business = $this->getResolvedBusinessProfile($request, 'write');
        $record = DB::transaction(function () use ($business, $mergeId, $request): CustomerMergeRecord {
            $merge = CustomerMergeRecord::query()->where('business_profile_id', $business->id)->lockForUpdate()->findOrFail($mergeId);
            abort_if($merge->reversed_at, 409, 'This contact merge has already been undone.');
            $source = Customer::query()->where('business_profile_id', $business->id)->lockForUpdate()->findOrFail($merge->source_customer_id);
            $target = Customer::query()->where('business_profile_id', $business->id)->lockForUpdate()->findOrFail($merge->target_customer_id);
            abort_unless((int) $source->merged_into_customer_id === (int) $target->id, 409, 'The contacts have changed since this merge.');

            $this->restoreTargetFieldsIfUnchanged($target, $merge->target_before, $merge->target_after);
            if ($merge->conversation_ids) \App\Models\Conversation::query()->where('business_id', $business->id)->where('customer_id', $target->id)->whereIn('id', $merge->conversation_ids)->update(['customer_id' => $source->id]);
            if ($merge->note_ids) CustomerNote::query()->where('customer_id', $target->id)->whereIn('id', $merge->note_ids)->update(['customer_id' => $source->id]);
            if ($merge->identity_ids) \App\Models\CustomerIdentity::query()->where('business_profile_id', $business->id)->where('customer_id', $target->id)->whereIn('id', $merge->identity_ids)->update(['customer_id' => $source->id]);
            $source->update(['merged_into_customer_id' => null]);
            $merge->update(['reversed_at' => now()]);
            AuditLoggerService::log('customer.merge_reversed', $merge, [
                'source_customer_id' => $source->id, 'target_customer_id' => $target->id,
            ], Auth::id(), (int) $business->id);

            return $merge;
        });

        return response()->json(['message' => 'Contact merge undone.', 'merge' => $record->fresh()]);
    }

    private function mergeableSnapshot(Customer $customer): array
    {
        return [
            'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone,
            'avatar' => $customer->avatar, 'assigned_to' => $customer->assigned_to,
            'lead_score' => $customer->lead_score, 'tags' => $customer->tags ?? [],
            'custom_fields' => $customer->custom_fields ?? [],
        ];
    }

    private function restoreTargetFieldsIfUnchanged(Customer $target, array $before, array $after): void
    {
        $restore = [];
        foreach ($after as $field => $mergedValue) {
            $current = $target->getAttribute($field);
            if ($current == $mergedValue) $restore[$field] = $before[$field] ?? null;
        }
        if ($restore) $target->fill($restore)->save();
    }

    private function assertNotMerged(Customer $customer): void
    {
        abort_if($customer->merged_into_customer_id, 409, 'This contact was merged into another profile.');
    }

    public function export(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request, 'read');
        $query = Customer::query()->where('business_profile_id', $business->id)->whereNull('merged_into_customer_id')->orderBy('id');
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
        $rows = $query->with('identities:id,customer_id,platform,external_id')->limit(10000)->get();
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
                $phone = $this->hasDirectWhatsAppIdentity($customer) ? $customer->phone : '';
                fputcsv($out, array_map($safe, [$customer->id, $customer->name, $customer->email, $phone, $fields['lifecycle_stage'] ?? '', $fields['language'] ?? '', $fields['source'] ?? '', $customer->lead_score, implode('|', $customer->tags ?? []), $customer->created_at?->toIso8601String()]));
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
