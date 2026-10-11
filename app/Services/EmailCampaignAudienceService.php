<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Support\Str;

class EmailCampaignAudienceService
{
    /**
     * Preview how many unique recipients a given criteria set would produce.
     * Used by the frontend "estimate audience" button before saving.
     */
    public function previewCount(BusinessProfile $business, array $criteria): int
    {
        return $this->previewStats($business, $criteria)['count'];
    }

    public function previewStats(BusinessProfile $business, array $criteria): array
    {
        $candidates = $this->candidateEmails($business, $criteria);
        $excludedAutomated = 0;
        $excludedNoConsent = 0;
        $excludedOptedOut = 0;
        $excludedSuppression = 0;
        $eligible = [];

        foreach ($candidates as $email) {
            if ($this->isAutomatedEmail($email)) {
                $excludedAutomated++;
                continue;
            }
            $decision = app(ConsentGate::class)->check($business, $email, 'email', 'marketing');
            if (!$decision->allowed) {
                if ($decision->reason === 'suppressed') {
                    $excludedSuppression++;
                } elseif ($decision->reason === 'opted_out') {
                    $excludedOptedOut++;
                } else {
                    $excludedNoConsent++;
                }
                continue;
            }
            $eligible[] = $email;
        }

        return [
            'count' => count($eligible),
            'total_matched' => count($candidates),
            'excluded_no_consent' => $excludedNoConsent,
            'excluded_opted_out' => $excludedOptedOut,
            'excluded_suppression' => $excludedSuppression,
            'excluded_automated_sender' => $excludedAutomated,
            'sender' => [
                'name' => (string) config('mail.from.name', ''),
                'address' => (string) config('mail.from.address', ''),
                'configured' => (string) config('mail.from.address', '') !== '',
            ],
        ];
    }

    /**
     * Resolve the final email list for a campaign based on its stored criteria.
     */
    public function resolveCampaignEmails(EmailCampaign $campaign): array
    {
        return $this->resolveEmailsFromCriteria(
            $campaign->business,
            $campaign->audience_criteria ?? []
        );
    }

    /**
     * Core resolver — supports three modes:
     *
     *  manual           → explicit list of email addresses typed by the user
     *  gmail            → all senders from Gmail conversations
     *  contacts         → senders from WhatsApp / Instagram / Facebook /
     *                     Telegram conversations who have a valid email stored
     *                     in sender_email, filtered by optional channel_ids
     *                     and/or last_active_days
     */
    public function resolveEmailsFromCriteria(BusinessProfile $business, array $criteria): array
    {
        $candidates = $this->candidateEmails($business, $criteria);
        return array_values(array_filter($candidates, function (string $email) use ($business) {
            return !$this->isAutomatedEmail($email)
                && app(ConsentGate::class)->check($business, $email, 'email', 'marketing')->allowed;
        }));
    }

    private function candidateEmails(BusinessProfile $business, array $criteria): array
    {
        $mode = $criteria['mode'] ?? 'manual';
        $emails = match ($mode) {
            'gmail' => $this->resolveGmailEmails($business),
            'contacts' => $this->resolveContactEmails($business, $criteria),
            default => $this->resolveManualEmails($criteria['recipients'] ?? []),
        };

        return collect($emails)->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }

    // ── Manual ───────────────────────────────────────────────────────────────

    private function resolveManualEmails(array $raw): array
    {
        return collect($raw)
            ->map(fn ($e) => is_string($e) ? strtolower(trim($e)) : '')
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }

    // ── Gmail ────────────────────────────────────────────────────────────────

    /** Whole local-part tokens that identify clear automated senders. */
    private const AUTOMATED_PATTERNS = [
        'noreply', 'no-reply', 'donotreply', 'do-not-reply',
        'bounce', 'mailer-daemon', 'postmaster',
    ];

    private function isAutomatedEmail(string $email): bool
    {
        [$local] = explode('@', $email, 2);

        // Match complete local-part tokens only; ordinary words containing
        // "auto" or other substrings remain eligible contacts.
        foreach (self::AUTOMATED_PATTERNS as $pattern) {
            if (preg_match('/(?:^|[._+\\-])' . preg_quote($pattern, '/') . '(?:$|[._+\\-])/i', $local)) {
                return true;
            }
        }

        return false;
    }

    private function resolveGmailEmails(BusinessProfile $business): array
    {
        return Conversation::where('business_id', $business->id)
            ->whereHas('channel', fn ($q) => $q->where('type', 'gmail'))
            ->get(['sender_email', 'sender_id'])
            ->flatMap(function ($conv) {
                $candidates = [];
                if ($conv->sender_email && filter_var($conv->sender_email, FILTER_VALIDATE_EMAIL)) {
                    $candidates[] = strtolower(trim($conv->sender_email));
                }
                if (
                    $conv->sender_id
                    && filter_var($conv->sender_id, FILTER_VALIDATE_EMAIL)
                ) {
                    $candidates[] = strtolower(trim($conv->sender_id));
                }
                return $candidates;
            })
            ->unique()->values()->all();
    }

    // ── Contacts (WhatsApp / Insta / FB / Telegram) ───────────────────────

    /**
     * Pulls email addresses from conversations on social/messaging channels.
     *
     * Criteria options:
     *   channel_ids      (array<int>)  — filter to specific channels; empty = all
     *   channel_types    (array<str>)  — e.g. ['whatsapp','instagram']
     *   last_active_days (int)         — only conversations active in last N days
     *   has_email        (bool)        — require sender_email to be present (default true)
     */
    private function resolveContactEmails(BusinessProfile $business, array $criteria): array
    {
        $query = Conversation::where('business_id', $business->id)
            ->whereHas('channel', function ($q) use ($criteria) {
                // Exclude email-based channels (those have sender_id = email already)
                $q->whereNotIn('type', ['gmail']);

                if (!empty($criteria['channel_ids'])) {
                    $q->whereIn('id', $criteria['channel_ids']);
                }

                if (!empty($criteria['channel_types'])) {
                    $q->whereIn('type', $criteria['channel_types']);
                }
            });

        if (!empty($criteria['last_active_days'])) {
            $query->where('last_message_at', '>=', now()->subDays((int) $criteria['last_active_days']));
        }

        // Pull sender_email (stored when available from Facebook/Instagram profiles)
        // AND fall back to sender_id when it looks like a valid email.
        $emails = $query->get(['sender_email', 'sender_id'])
            ->flatMap(function ($conv) {
                $candidates = [];
                if ($conv->sender_email && filter_var($conv->sender_email, FILTER_VALIDATE_EMAIL)) {
                    $candidates[] = strtolower(trim($conv->sender_email));
                }
                // WhatsApp sender_id is a phone number — not an email. Only use
                // sender_id as an email fallback when it actually looks like one.
                if (
                    $conv->sender_id
                    && str_contains($conv->sender_id, '@')
                    && !str_contains($conv->sender_id, '@s.whatsapp.net')
                    && !str_contains($conv->sender_id, '@lid')
                    && filter_var($conv->sender_id, FILTER_VALIDATE_EMAIL)
                ) {
                    $candidates[] = strtolower(trim($conv->sender_id));
                }
                return $candidates;
            })
            ->unique()->values()->all();

        return $emails;
    }

    // ── Available channels helper (for frontend dropdown) ─────────────────

    /**
     * Returns a simplified list of connected channels for the business,
     * used by the frontend to populate the channel filter picker.
     */
    public function availableChannels(BusinessProfile $business): array
    {
        return Channel::where('user_id', $business->user_id)
            ->where('status', 'connected')
            ->get(['id', 'type', 'page_name'])
            ->map(fn ($c) => [
                'id'   => $c->id,
                'type' => $c->type,
                'name' => $c->page_name ?: ucfirst($c->type),
            ])
            ->all();
    }

    // ── Build recipients ──────────────────────────────────────────────────

    public function buildRecipients(EmailCampaign $campaign, bool $refresh = false): void
    {
        if ($refresh) {
            $campaign->recipients()->delete();
            $campaign->update([
                'total_recipients' => 0,
                'delivered_count'  => 0,
                'opened_count'     => 0,
                'clicked_count'    => 0,
                'failed_count'     => 0,
                'error_message'    => null,
            ]);
        } elseif ($campaign->recipients()->exists()) {
            return;
        }

        $emails = $this->resolveCampaignEmails($campaign);

        foreach ($emails as $email) {
            EmailCampaignRecipient::create([
                'email_campaign_id' => $campaign->id,
                'tracking_token'    => Str::random(48),
                'email'             => $email,
                'status'            => 'pending',
            ]);
        }

        $campaign->update(['total_recipients' => count($emails)]);
    }
}
