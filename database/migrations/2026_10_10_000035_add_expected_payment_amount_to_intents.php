<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->unsignedBigInteger('expected_amount_minor')->nullable()->after('status');
            $table->char('expected_currency', 3)->nullable()->after('expected_amount_minor');
            $table->string('failure_reason')->nullable()->after('gateway_response');
        });

        DB::table('payment_intents as intents')
            ->join('packages', 'packages.id', '=', 'intents.package_id')
            ->where('intents.status', 'pending')
            ->whereNull('intents.expected_amount_minor')
            ->select([
                'intents.id', 'intents.payment_gateway', 'intents.billing_cycle',
                'packages.price_monthly', 'packages.price_yearly',
            ])
            ->orderBy('intents.id')
            ->chunk(200, function ($intents): void {
                foreach ($intents as $intent) {
                    $amount = $intent->billing_cycle === 'yearly'
                        ? (float) $intent->price_yearly
                        : (float) $intent->price_monthly;
                    DB::table('payment_intents')->where('id', $intent->id)->update([
                        'expected_amount_minor' => (int) round($amount * 100),
                        'expected_currency' => $intent->payment_gateway === 'myfatoorah'
                            ? 'SAR'
                            : strtoupper((string) env('PAYMOB_CURRENCY', 'EGP')),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropColumn(['expected_amount_minor', 'expected_currency', 'failure_reason']);
        });
    }
};
