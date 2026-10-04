<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Central per-business customer resolution for every inbound provider
 * (Instagram, Facebook, WhatsApp, Telegram, TikTok, Gmail, WebChat, Salla).
 *
 * Matching order (strongest identity first):
 *   1. phone    — cross-channel key (WhatsApp sender / Salla mobile / checkout flow)
 *   2. email    — Gmail and Salla customers
 *   3. platform identity — the per-platform sender id recorded in
 *      custom_fields.platform_ids.{platform} when no phone/email is known
 *      (Instagram PSID, Telegram chat id, TikTok user id, web-chat session).
 *
 * The platform sender id is ALWAYS recorded on the resolved/created customer
 * (adoptIdentity), so once a customer has been linked through a strong key,
 * later phone-less messages from the same person on that platform resolve to
 * the same record instead of creating duplicates.
 */
class CustomerService
{
    /**
     * Resolve or create the business-scoped Customer for a sender.
     *
     * @param int|null $businessId Business profile scope (never share customers across businesses)
     * @param string   $platform   Channel type (instagram, whatsapp, salla, web_chat, ...)
     * @param string   $senderId   Platform sender identifier
     * @param array    $attributes Optional overrides: name, phone, email
     */
    public function resolve(?int $businessId, string $platform, string $senderId, array $attributes = []): ?Customer
    {
        if (!$businessId) {
            return null;
        }

        $phone = !empty($attributes['phone']) ? $this->normalizePhone($attributes['phone']) : null;
        $email = !empty($attributes['email']) ? $this->normalizeEmail($attributes['email']) : null;
        $name  = $this->cleanName($attributes['name'] ?? null);

        $customer = null;

        if ($phone) {
            $customer = Customer::where('business_profile_id', $businessId)
                ->where(function ($q) use ($phone, $attributes) {
                    $q->where('phone', $phone)
                      ->orWhere('phone', preg_replace('/\D+/', '', (string) $attributes['phone']));
                })
                ->first();
        }

        if (!$customer && $email) {
            $customer = Customer::where('business_profile_id', $businessId)
                ->where('email', $email)
                ->first();
        }

        if (!$customer) {
            $customer = $this->findByPlatformIdentity($businessId, $platform, $senderId);
        }

        if ($customer) {
            // The phone/email being adopted here could not have matched another
            // customer — those matches ran first and found nothing.
            if (!$customer->phone && $phone) {
                $customer->phone = $phone;
            }
            if (!$customer->email && $email) {
                $customer->email = $email;
            }
            if (!$customer->name && $name) {
                $customer->name = $name;
            }
            $this->adoptIdentity($customer, $platform, $senderId);
            if ($customer->isDirty()) {
                $customer->save();
            }
            return $customer;
        }

        $customer = Customer::create([
            'business_profile_id' => $businessId,
            'name'                => $name,
            'phone'               => $phone,
            'email'               => $email,
            'custom_fields'       => ['platform_ids' => [$platform => $senderId]],
        ]);

        Log::info('CustomerService: created customer', [
            'business_profile_id' => $businessId,
            'customer_id'         => $customer->id,
            'platform'            => $platform,
            'matched_by'          => 'created',
        ]);

        return $customer;
    }

    /**
     * Resolve the customer for an inbound Conversation (using the identity data
     * already persisted on it) and link the conversation to it.
     */
    public function attachToConversation(Conversation $conversation, array $attributes = []): ?Customer
    {
        $channel  = $conversation->channel;
        $platform = $channel?->type ?? 'web_chat';
        $senderId = (string) $conversation->sender_id;

        // WhatsApp and Salla conversations key on the customer's phone number
        // (Evolution JID / Salla customer mobile) — that is a real phone identity.
        $phone = $attributes['phone']
            ?? (in_array($platform, ['whatsapp', 'salla']) ? $senderId : null);

        $attributes += [
            'name'  => $this->cleanName($conversation->sender_name),
            'email' => $conversation->sender_email,
            'phone' => $phone,
        ];

        $customer = $this->resolve($conversation->business_id, $platform, $senderId, $attributes);

        if ($customer && $conversation->customer_id !== $customer->id) {
            $conversation->update(['customer_id' => $customer->id]);
        }

        return $customer;
    }

    /**
     * AI-turn enrichment (ProcessAutoReply): link phone-less conversations
     * (e.g. Instagram) to the customer that owns a phone collected during the
     * checkout flow, so the Instagram shopper and the WhatsApp/Salla buyer are
     * one record. Safe against stealing: only adopts a phone no other customer
     * of the business owns.
     */
    public function enrichFromCheckoutState(Conversation $conversation): ?Customer
    {
        $state = is_array($conversation->checkout_state) ? $conversation->checkout_state : [];
        $phone = !empty($state['phone']) ? $this->normalizePhone($state['phone']) : null;
        $name  = $this->cleanName($state['full_name'] ?? null);

        $customer = $conversation->customer_id
            ? Customer::find($conversation->customer_id)
            : null;

        if ($customer) {
            if (!$customer->phone && $phone) {
                $owner = Customer::where('business_profile_id', $conversation->business_id)
                    ->where('phone', $phone)
                    ->where('id', '!=', $customer->id)
                    ->first();
                if (!$owner) {
                    $customer->phone = $phone;
                    $this->adoptIdentity($customer, $conversation->channel?->type ?? 'unknown', (string) $conversation->sender_id);
                    $customer->save();
                }
            }
            if (!$customer->name && $name) {
                $customer->name = $name;
                $customer->save();
            }
            return $customer;
        }

        if (!$phone && !$name) {
            return null;
        }

        // No customer yet — resolve with the strongest identity we have.
        // If a customer owning that phone already exists (WhatsApp/Salla), the
        // conversation links to it instead of creating a duplicate.
        $customer = $this->resolve(
            $conversation->business_id,
            $conversation->channel?->type ?? 'web_chat',
            (string) $conversation->sender_id,
            ['phone' => $phone, 'name' => $name]
        );

        if ($customer && $conversation->customer_id !== $customer->id) {
            $conversation->update(['customer_id' => $customer->id]);
            Log::info('CustomerService: conversation linked to customer from checkout state', [
                'conversation_id' => $conversation->id,
                'customer_id'     => $customer->id,
            ]);
        }

        return $customer;
    }

    public function findByPlatformIdentity(int $businessId, string $platform, string $senderId): ?Customer
    {
        return Customer::where('business_profile_id', $businessId)
            ->where("custom_fields->platform_ids->{$platform}", $senderId)
            ->first();
    }

    /**
     * Record the platform sender id on the customer so future phone-less
     * messages from this platform resolve to the same record.
     */
    public function adoptIdentity(Customer $customer, string $platform, string $senderId): void
    {
        $fields = is_array($customer->custom_fields) ? $customer->custom_fields : [];
        $ids = is_array($fields['platform_ids'] ?? null) ? $fields['platform_ids'] : [];

        if (($ids[$platform] ?? null) === $senderId) {
            return;
        }

        $ids[$platform] = $senderId;
        $fields['platform_ids'] = $ids;
        $customer->custom_fields = $fields;
    }

    /**
     * Canonical phone form: digits only, '00' international prefix and one
     * trunk '0' stripped; bare 9-digit local numbers get the Salla/Saudi '966'
     * country prefix so '0555...', '555...', and '+966555...' all canonicalize
     * identically. Canonicalization is injective — distinct numbers never
     * collide.
     */
    public function normalizePhone(?string $raw): ?string
    {
        if (!$raw) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            $digits = '966' . $digits;
        }

        return $digits;
    }

    public function normalizeEmail(?string $raw): ?string
    {
        return $raw ? mb_strtolower(trim($raw)) : null;
    }

    /**
     * Reject phone-number-shaped or placeholder names ("9665...", "+966...", ".")
     * so platform-provided placeholders never become customer names.
     */
    public function cleanName(?string $raw): ?string
    {
        $name = trim((string) $raw);
        if ($name === '' || $name === '.') {
            return null;
        }
        if (preg_match('/^\+?[0-9][0-9\s\-\(\)]*$/', $name)) {
            return null;
        }
        return $name;
    }
}
