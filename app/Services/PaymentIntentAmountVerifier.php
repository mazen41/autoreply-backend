<?php

namespace App\Services;

use App\Models\PaymentIntent;
use Illuminate\Support\Facades\Log;

class PaymentIntentAmountVerifier
{
    public function verify(PaymentIntent $intent, ?int $actualMinor, ?string $actualCurrency, string $provider): bool
    {
        $expectedMinor = $intent->expected_amount_minor;
        $expectedCurrency = strtoupper(trim((string) $intent->expected_currency));
        $actualCurrency = $actualCurrency !== null ? strtoupper(trim($actualCurrency)) : '';

        $reason = null;
        if ($expectedMinor === null || $actualMinor === null || $actualMinor !== (int) $expectedMinor) {
            $reason = 'amount_mismatch';
        } elseif ($expectedCurrency === '' || $actualCurrency === '' || $actualCurrency !== $expectedCurrency) {
            $reason = $actualCurrency === '' ? 'currency_unavailable' : 'currency_mismatch';
        }

        if ($reason === null) {
            return true;
        }

        $intent->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);

        Log::alert('Payment activation rejected because amount or currency did not match intent', [
            'payment_intent_id' => $intent->id,
            'provider' => $provider,
            'reason' => $reason,
            'expected_amount_minor' => $expectedMinor,
            'actual_amount_minor' => $actualMinor,
            'expected_currency' => $expectedCurrency ?: null,
            'actual_currency' => $actualCurrency ?: null,
        ]);

        return false;
    }
}
