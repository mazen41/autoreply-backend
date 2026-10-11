<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExportBusinessCustomerData;
use App\Models\CommerceOrder;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DataRightsRequest;
use App\Models\Suppression;
use App\Models\WhatsAppInstance;
use App\Models\WhatsAppMessage;
use App\Services\ActiveBusiness;
use App\Services\IncomingMediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DataRightsController extends Controller
{
    public function retention(Request $request, int $businessId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read', routeBusinessId: $businessId);
        return response()->json($business->only(['message_retention_days', 'media_retention_days', 'consent_retention_days']));
    }

    public function updateRetention(Request $request, int $businessId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'write', routeBusinessId: $businessId);
        $data = $request->validate([
            'message_retention_days' => ['sometimes', 'integer', 'between:30,3650'],
            'media_retention_days' => ['sometimes', 'integer', 'between:1,3650'],
            'consent_retention_days' => ['sometimes', 'nullable', 'integer', 'between:30,36500'],
        ]);
        $business->fill($data)->save();
        return response()->json($business->fresh()->only(['message_retention_days', 'media_retention_days', 'consent_retention_days']));
    }

    public function customers(Request $request, int $businessId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read', routeBusinessId: $businessId);
        $query = Customer::query()->where('business_profile_id', $business->id);
        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($customers) use ($search): void {
                $customers->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }
        return response()->json($query->select(['id', 'name', 'phone', 'email'])->orderBy('name')->paginate(50));
    }

    public function requests(Request $request, int $businessId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read', routeBusinessId: $businessId);
        return response()->json(DataRightsRequest::query()->where('business_id', $business->id)
            ->orderByDesc('id')->limit(50)->get()->map(fn (DataRightsRequest $item) => $this->presentRequest($item)));
    }

    public function requestExport(Request $request, int $businessId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read', routeBusinessId: $businessId);
        $dataRequest = DataRightsRequest::create([
            'business_id' => $business->id, 'requested_by' => $request->user()->id,
            'type' => 'export', 'status' => 'pending',
        ]);
        ExportBusinessCustomerData::dispatch($dataRequest->id);
        return response()->json($this->presentRequest($dataRequest), 202);
    }

    public function downloadExport(Request $request, int $businessId, int $requestId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read', routeBusinessId: $businessId);
        $dataRequest = DataRightsRequest::query()->where('business_id', $business->id)
            ->where('type', 'export')->findOrFail($requestId);
        abort_unless($dataRequest->status === 'completed' && $dataRequest->archive_path
            && $dataRequest->archive_expires_at?->isFuture()
            && Storage::disk('local')->exists($dataRequest->archive_path), 404, 'Export is unavailable or expired.');

        return Storage::disk('local')->download($dataRequest->archive_path, 'customer-data-export-' . $dataRequest->id . '.zip');
    }

    public function eraseCustomer(Request $request, int $businessId, int $customerId, ActiveBusiness $activeBusiness, IncomingMediaStorage $mediaStorage)
    {
        $business = $activeBusiness->resolve($request, permission: 'delete', routeBusinessId: $businessId);
        $customer = Customer::query()->where('business_profile_id', $business->id)->findOrFail($customerId);
        $request->validate(['confirm' => ['required', 'accepted']]);
        $dataRequest = DataRightsRequest::create([
            'business_id' => $business->id, 'customer_id' => $customer->id,
            'requested_by' => $request->user()->id, 'type' => 'erasure', 'status' => 'processing',
        ]);

        $phone = trim((string) $customer->phone);
        $email = mb_strtolower(trim((string) $customer->email));
        $conversations = Conversation::query()->whereIn('channel_id', Channel::query()->select('id')->where('business_id', $business->id))
            ->where(function ($query) use ($customer, $phone, $email) {
                $query->where('customer_id', $customer->id);
                if ($phone !== '') $query->orWhere('sender_id', $phone);
                if ($email !== '') $query->orWhere('sender_email', $email);
            })->with('channel:id,type')->get();

        $identities = [];
        foreach ($conversations as $conversation) {
            $type = strtolower((string) $conversation->channel?->type);
            $channel = $type === 'gmail' ? 'email' : $type;
            $identifier = $type === 'gmail' ? (string) $conversation->sender_email : (string) $conversation->sender_id;
            if ($identifier !== '') $identities[$channel . '|' . $identifier] = [$channel, $identifier];
        }
        foreach ([['phone', $phone, 'whatsapp'], ['email', $email, 'email']] as [$kind, $identifier, $channel]) {
            if ($identifier !== '') $identities[$channel . '|' . $identifier] = [$channel, $identifier];
        }

        $messageQuery = \App\Models\Message::query()->whereIn('conversation_id', $conversations->pluck('id'));
        $messageCount = (clone $messageQuery)->count();
        $legacyWhatsAppQuery = WhatsAppMessage::query()->whereIn('whatsapp_instance_id', WhatsAppInstance::query()
            ->select('id')->where('business_id', $business->id))
            ->where(function ($query) use ($phone, $email) {
                if ($phone !== '') $query->orWhere('from_phone', $phone)->orWhere('to_phone', $phone);
                if ($email !== '' && $phone === '') $query->whereRaw('1 = 0');
                if ($phone === '' && $email === '') $query->whereRaw('1 = 0');
            });
        $legacyWhatsAppCount = (clone $legacyWhatsAppQuery)->count();
        $mediaRows = (clone $messageQuery)->whereNotNull('media_url')->get(['id', 'media_url', 'metadata']);
        $mediaPaths = [];
        $privateDisk = $mediaStorage->diskName();
        foreach ($mediaRows as $message) {
            $disk = (string) data_get($message->metadata, 'media.storage_disk', $privateDisk);
            $path = (string) $message->media_url;
            if ($mediaStorage->absolutePath($path, $disk) !== null) $mediaPaths[] = [$disk, $path];
        }

        DB::transaction(function () use ($business, $customer, $conversations, $messageQuery, $messageCount, $legacyWhatsAppQuery, $legacyWhatsAppCount, $identities, $phone, $email, $dataRequest) {
            foreach ($identities as [$channel, $identifier]) {
                $normalizer = app(\App\Services\ConsentGate::class);
                $normalized = $normalizer->normalizeIdentifier($identifier, $channel);
                ContactConsent::query()->where('business_id', $business->id)->where('channel', $channel)
                    ->where('contact_identifier', $normalized)->delete();
                Suppression::query()->updateOrCreate([
                    'business_id' => $business->id, 'channel' => $channel,
                    'contact_identifier' => $normalized, 'reason' => 'unsubscribed',
                ], ['source' => 'data_erasure:' . $dataRequest->id, 'suppressed_at' => now()]);
            }
            $messageQuery->delete();
            $legacyWhatsAppQuery->delete();
            Conversation::query()->whereIn('id', $conversations->pluck('id'))->delete();
            $customer->delete();

            if (Schema::hasTable('commerce_orders')) {
                CommerceOrder::query()->where('business_id', $business->id)->where(function ($query) use ($phone, $email) {
                    if ($phone !== '') $query->orWhere('customer_phone', $phone);
                    if ($email !== '') $query->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                })->get()->each(function (CommerceOrder $order): void {
                    $raw = $this->removePersonalOrderFields($order->raw_data ?? []);
                    $order->forceFill(['customer_name' => null, 'customer_email' => null, 'customer_phone' => null, 'shipping_address' => null, 'raw_data' => $raw])->save();
                });
            }
            $dataRequest->update([
                'customer_id' => null, 'status' => 'completed', 'completed_at' => now(),
                'receipt' => [
                    'customer_record_deleted' => true,
                    'conversations_deleted' => $conversations->count(),
                    'messages_deleted' => $messageCount,
                    'legacy_whatsapp_messages_deleted' => $legacyWhatsAppCount,
                    'media_files_deleted' => 0,
                    'consent_records_deleted' => true,
                    'suppression_records_retained' => count($identities),
                    'commerce_order_records_retained_anonymized' => true,
                    'completed_at' => now()->toIso8601String(),
                ],
            ]);
        });

        $deletedMedia = 0;
        $cleanupFailures = 0;
        foreach ($mediaPaths as [$disk, $path]) {
            try {
                if (Storage::disk($disk)->delete($path)) $deletedMedia++;
                else $cleanupFailures++;
            } catch (\Throwable) {
                $cleanupFailures++;
            }
        }
        $freshRequest = $dataRequest->fresh();
        $receipt = $freshRequest->receipt ?? [];
        $receipt['media_files_deleted'] = $deletedMedia;
        $receipt['media_cleanup_failures'] = $cleanupFailures;
        $freshRequest->update(['receipt' => $receipt]);

        return response()->json(['request' => $this->presentRequest($freshRequest->fresh())], 200);
    }

    private function presentRequest(DataRightsRequest $request): array
    {
        return $request->only(['id', 'type', 'status', 'receipt', 'archive_expires_at', 'failure_reason', 'completed_at', 'created_at']);
    }

    private function removePersonalOrderFields(array $data): array
    {
        $sensitiveKeys = ['customer', 'customer_name', 'customer_email', 'customer_phone', 'email', 'phone', 'shipping_address', 'billing_address'];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->removePersonalOrderFields($value);
            }
        }
        return $data;
    }
}
