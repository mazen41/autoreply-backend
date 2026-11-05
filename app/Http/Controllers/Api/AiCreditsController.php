<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBusinessSetting;
use App\Models\AiCreditPackage;
use App\Models\AiCreditPurchase;
use App\Models\AiCreditTransaction;
use App\Models\AiModelPrice;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\BusinessProfile;
use App\Services\ActiveBusiness;
use App\Services\AiCreditManager;
use App\Services\MyFatoorahService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiCreditsController extends Controller
{
    public function __construct(private readonly AiCreditManager $credits) {}

    public function settings(Request $request)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        $settings = AiBusinessSetting::query()->firstOrCreate(['business_id' => $business->id]);
        $enabledModes = $this->credits->settings()->enabled_modes ?: ['auto'];
        return response()->json(['operating_mode' => in_array($settings->operating_mode, $enabledModes, true) ? $settings->operating_mode : 'auto', 'available_modes' => $enabledModes, 'notification_thresholds' => $settings->notification_thresholds ?? [50, 80, 90, 100]]);
    }

    public function updateSettings(Request $request)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'manage_billing');
        $values = $request->validate(['operating_mode' => 'sometimes|in:auto,economy,premium', 'notification_thresholds' => 'sometimes|array', 'notification_thresholds.*' => 'integer|in:50,80,90,100']);
        if (isset($values['operating_mode']) && !in_array($values['operating_mode'], $this->credits->settings()->enabled_modes ?: ['auto'], true)) {
            return response()->json(['message' => 'This AI operating mode is currently unavailable.'], 422);
        }
        $settings = AiBusinessSetting::query()->firstOrCreate(['business_id' => $business->id]);
        $settings->fill($values)->save();
        return response()->json(['success' => true, 'settings' => $settings->only(['operating_mode', 'notification_thresholds'])]);
    }

    public function usage(Request $request)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        $balance = $this->credits->balance($business);
        $since = $this->credits->walletFor($business)->billing_period_starts_at ?? now()->startOfMonth();
        $logs = AiUsageLog::query()->where('business_id', $business->id)->where('created_at', '>=', $since);
        return response()->json($balance + [
            'by_task' => (clone $logs)->where('status', 'succeeded')->select('task_type', DB::raw('SUM(credits_consumed) as credits'), DB::raw('COUNT(*) as requests'))->groupBy('task_type')->orderByDesc('credits')->get(),
            'daily' => (clone $logs)->where('status', 'succeeded')->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(credits_consumed) as credits'), DB::raw('COUNT(*) as requests'))->groupBy(DB::raw('DATE(created_at)'))->orderBy('date')->get(),
        ]);
    }

    public function history(Request $request)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        $rows = AiUsageLog::query()->where('business_id', $business->id)->latest()->paginate(min(100, max(1, (int) $request->input('per_page', 25))));
        $rows->getCollection()->transform(function (AiUsageLog $row) {
            $safe = $row->only(['id', 'operation_id', 'business_id', 'conversation_id', 'task_type', 'provider', 'model_key', 'input_tokens', 'output_tokens', 'cached_tokens', 'provider_cost_usd', 'credits_consumed', 'status', 'latency_ms', 'pricing_version', 'created_at', 'updated_at']);
            $safe['failure_type'] = data_get($row->metadata, 'failure_type');
            $safe['reserved_credits'] = (int) data_get($row->metadata, 'reserved_credits', data_get($row->metadata, 'reservation_quarantined', 0));
            return $safe;
        });
        return response()->json($rows);
    }

    public function transactions(Request $request)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        return response()->json(AiCreditTransaction::query()->where('business_id', $business->id)->latest('created_at')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))));
    }

    public function packages()
    {
        return response()->json(AiCreditPackage::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'credits', 'price_sar']));
    }

    public function purchase(Request $request, MyFatoorahService $gateway)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'manage_billing');
        $validated = $request->validate(['package_id' => 'required|integer|exists:ai_credit_packages,id']);
        $package = AiCreditPackage::query()->whereKey($validated['package_id'])->where('is_active', true)->firstOrFail();
        $user = $request->user();
        $amount = (float) $package->price_sar;
        $purchase = AiCreditPurchase::query()->create(['business_id' => $business->id, 'user_id' => $user->id, 'credit_package_id' => $package->id, 'credits' => $package->credits, 'status' => 'pending', 'expected_amount_minor' => (int) round($amount * 100), 'currency' => 'SAR', 'payment_gateway' => 'myfatoorah']);

        try {
            $paymentMethods = $gateway->initiatePayment($amount, 'SAR');
            $method = collect($paymentMethods['PaymentMethods'] ?? [])->first(fn ($item) => isset($item['PaymentMethodId']) && (int) $item['PaymentMethodId'] > 0);
            if (!$method) throw new \RuntimeException('No MyFatoorah payment method is enabled.');
            $execute = $gateway->executePayment([
                'PaymentMethodId' => (int) $method['PaymentMethodId'],
                'InvoiceValue' => $amount,
                'CustomerName' => $user->name,
                'CustomerEmail' => $user->email,
                'DisplayCurrencyIso' => 'SAR',
                'CallBackUrl' => route('payment.callback'),
                'ErrorUrl' => route('payment.error'),
                'UserDefinedField' => 'ai-credit-purchase:' . $purchase->id,
            ]);
            $url = $execute['PaymentURL'] ?? $execute['InvoiceURL'] ?? null;
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || empty($execute['InvoiceId'])) throw new \RuntimeException('Payment provider returned no valid invoice URL.');
            $purchase->update(['invoice_id' => (string) $execute['InvoiceId'], 'gateway_response' => $execute]);
            return response()->json(['purchase_id' => $purchase->id, 'payment_url' => $url, 'invoice_id' => (string) $execute['InvoiceId']]);
        } catch (\Throwable $exception) {
            $purchase->update(['status' => 'failed']);
            Log::error('AI credit purchase invoice creation failed', ['purchase_id' => $purchase->id, 'exception_type' => get_class($exception)]);
            return response()->json(['message' => 'Could not start credit purchase. Please try again.'], 502);
        }
    }

    public function fulfillVerifiedPurchase(array $paymentData): bool
    {
        $invoiceId = (string) ($paymentData['InvoiceId'] ?? '');
        $purchase = AiCreditPurchase::query()->where('invoice_id', $invoiceId)->where('payment_gateway', 'myfatoorah')->first();
        if (!$purchase) return false;
        if (strtoupper((string) ($paymentData['InvoiceStatus'] ?? '')) !== 'PAID') return true;
        $transaction = collect($paymentData['InvoiceTransactions'] ?? [])->first();
        $currency = $paymentData['CurrencyIso'] ?? $paymentData['DisplayCurrencyIso'] ?? $paymentData['DisplayCurrencyIsoAlpha'] ?? (is_array($transaction) ? ($transaction['Currency'] ?? $transaction['PaidCurrency'] ?? null) : null);
        $amountMinor = isset($paymentData['InvoiceValue']) && is_numeric($paymentData['InvoiceValue']) ? (int) round((float) $paymentData['InvoiceValue'] * 100) : null;
        if ($amountMinor !== (int) $purchase->expected_amount_minor || strtoupper((string) $currency) !== strtoupper($purchase->currency)) {
            Log::warning('AI credit purchase payment amount/currency mismatch', ['purchase_id' => $purchase->id, 'currency_match' => strtoupper((string) $currency) === strtoupper($purchase->currency), 'amount_match' => $amountMinor === (int) $purchase->expected_amount_minor]);
            return true;
        }

        DB::transaction(function () use ($purchase, $transaction, $paymentData): void {
            $locked = AiCreditPurchase::query()->lockForUpdate()->find($purchase->id);
            if (!$locked || $locked->status === 'completed') return;
            $business = BusinessProfile::query()->findOrFail($locked->business_id);
            $locked->update(['status' => 'completed', 'payment_id' => is_array($transaction) ? (string) ($transaction['TransactionId'] ?? '') : null, 'gateway_response' => $paymentData, 'fulfilled_at' => now()]);
            $this->credits->addPurchasedCredits($business, (int) $locked->credits, 'credit-purchase:' . $locked->id, ['purchase_id' => $locked->id, 'invoice_id' => $locked->invoice_id, 'payment_id' => $locked->payment_id]);
        });
        return true;
    }

    public function adminOverview()
    {
        $since = now()->startOfMonth();
        return response()->json([
            'requests' => AiUsageLog::query()->where('created_at', '>=', $since)->count(),
            'successful_requests' => AiUsageLog::query()->where('created_at', '>=', $since)->where('status', 'succeeded')->count(),
            'failed_requests' => AiUsageLog::query()->where('created_at', '>=', $since)->whereIn('status', ['failed', 'needs_reconciliation'])->count(),
            'reconciliation_required' => AiUsageLog::query()->where('status', 'needs_reconciliation')->count(),
            'provider_cost_usd' => (string) AiUsageLog::query()->where('created_at', '>=', $since)->sum('provider_cost_usd'),
            'credits_consumed' => (int) AiUsageLog::query()->where('created_at', '>=', $since)->sum('credits_consumed'),
            'credit_purchase_revenue_sar' => (string) AiCreditPurchase::query()->where('status', 'completed')->where('created_at', '>=', $since)->sum(DB::raw('expected_amount_minor / 100')),
            'by_provider' => AiUsageLog::query()->where('created_at', '>=', $since)->select('provider', DB::raw('COUNT(*) as requests'), DB::raw('SUM(provider_cost_usd) as cost_usd'), DB::raw('SUM(credits_consumed) as credits'))->groupBy('provider')->get(),
            'by_model' => AiUsageLog::query()->where('created_at', '>=', $since)->select('provider', 'model_key', DB::raw('COUNT(*) as requests'), DB::raw('SUM(provider_cost_usd) as cost_usd'), DB::raw('SUM(credits_consumed) as credits'))->groupBy('provider', 'model_key')->orderByDesc('cost_usd')->get(),
            'provider_health' => DB::getSchemaBuilder()->hasTable('ai_provider_health')
                ? DB::table('ai_provider_health')->select('provider', 'model_key', 'consecutive_failures', 'last_error_code', 'last_failure_at', 'last_success_at', 'available_after')->orderBy('provider')->orderBy('model_key')->get()
                : [],
        ]);
    }

    public function adminModels()
    {
        return response()->json(AiModelPrice::query()->orderBy('provider')->orderBy('display_name')->get());
    }

    public function adminProviders()
    {
        $providers = AiProvider::query()->orderBy('priority')->orderBy('display_name')->get();
        return response()->json($providers->map(fn (AiProvider $provider) => [
            'id' => $provider->id,
            'slug' => $provider->slug,
            'display_name' => $provider->display_name,
            'adapter' => $provider->adapter,
            'is_active' => (bool) $provider->is_active,
            'priority' => $provider->priority,
            'timeout_seconds' => $provider->timeout_seconds,
            'configured' => app(\App\Services\AiProviderConfiguration::class)->isAvailable($provider->slug),
            'key_set' => filled(data_get($provider->credentials, 'api_key')),
        ]));
    }

    public function saveAdminProvider(Request $request, ?int $id = null)
    {
        $provider = $id ? AiProvider::query()->findOrFail($id) : new AiProvider();
        $values = $request->validate([
            'slug' => ($id ? 'sometimes' : 'required') . '|string|in:openai,claude,gemini,groq|unique:ai_providers,slug' . ($id ? ',' . $id : ''),
            'display_name' => ($id ? 'sometimes' : 'required') . '|required|string|max:100',
            'adapter' => ($id ? 'sometimes' : 'required') . '|required|string|in:openai,openai-compatible,anthropic,gemini',
            'api_key' => 'sometimes|nullable|string|max:4096',
            'is_active' => 'sometimes|boolean',
            'priority' => 'sometimes|integer|min:0|max:10000',
            'timeout_seconds' => 'sometimes|integer|min:1|max:180',
        ]);
        $apiKey = $values['api_key'] ?? null;
        unset($values['api_key']);
        $provider->fill($values);
        if (is_string($apiKey) && trim($apiKey) !== '') {
            $credentials = $provider->credentials ?? [];
            $credentials['api_key'] = trim($apiKey);
            $provider->credentials = $credentials;
        }
        $provider->save();
        return response()->json(['success' => true, 'provider' => [
            'id' => $provider->id, 'slug' => $provider->slug, 'display_name' => $provider->display_name,
            'adapter' => $provider->adapter, 'is_active' => (bool) $provider->is_active,
            'priority' => $provider->priority, 'timeout_seconds' => $provider->timeout_seconds,
            'configured' => app(\App\Services\AiProviderConfiguration::class)->isAvailable($provider->slug),
            'key_set' => filled(data_get($provider->credentials, 'api_key')),
        ]], $id ? 200 : 201);
    }

    public function deleteAdminProvider(int $id)
    {
        $provider = AiProvider::query()->findOrFail($id);
        $provider->update(['is_active' => false]);
        return response()->json(['success' => true]);
    }

    public function testAdminProvider(int $id)
    {
        $provider = AiProvider::query()->findOrFail($id);
        $key = app(\App\Services\AiProviderConfiguration::class)->key($provider->slug);
        if ($key === '') {
            return response()->json(['success' => false, 'message' => 'No active API key is configured for this provider.'], 422);
        }

        $request = match ($provider->slug) {
            'openai' => \Illuminate\Support\Facades\Http::withToken($key)->timeout(12)->get('https://api.openai.com/v1/models'),
            'claude' => \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(12)->get('https://api.anthropic.com/v1/models'),
            'gemini' => \Illuminate\Support\Facades\Http::timeout(12)->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $key]),
            'groq' => \Illuminate\Support\Facades\Http::withToken($key)->timeout(12)->get('https://api.groq.com/openai/v1/models'),
            default => null,
        };

        if (!$request) {
            return response()->json(['success' => false, 'message' => 'This provider adapter is not supported.'], 422);
        }
        if (!$request->successful()) {
            Log::warning('AI provider administrator connection check failed', ['provider' => $provider->slug, 'http_status' => $request->status()]);
            return response()->json(['success' => false, 'http_status' => $request->status(), 'message' => 'The provider rejected the configured credentials or is unavailable.'], 422);
        }

        return response()->json(['success' => true, 'message' => 'Provider credentials accepted.']);
    }

    public function createAdminModel(Request $request)
    {
        $validated = $this->validateModel($request, true);
        $model = AiModelPrice::query()->create($validated);
        return response()->json($model, 201);
    }

    public function updateAdminModel(Request $request, int $id)
    {
        $model = AiModelPrice::query()->findOrFail($id);
        $values = $this->validateModel($request, false);
        if (collect($values)->keys()->intersect(['input_price_per_million', 'output_price_per_million', 'cached_input_price_per_million', 'image_price_per_unit', 'audio_price_per_minute', 'audio_output_price_per_million_chars', 'pricing_version', 'effective_at'])->isNotEmpty()) {
            DB::table('ai_model_price_history')->insert([
                'model_price_id' => $model->id, 'provider' => $model->provider, 'model_key' => $model->model_key,
                'input_price_per_million' => $model->input_price_per_million, 'output_price_per_million' => $model->output_price_per_million,
                'cached_input_price_per_million' => $model->cached_input_price_per_million, 'image_price_per_unit' => $model->image_price_per_unit,
                'audio_price_per_minute' => $model->audio_price_per_minute, 'pricing_version' => $model->pricing_version,
                'audio_output_price_per_million_chars' => $model->audio_output_price_per_million_chars,
                'effective_from' => $model->effective_at, 'effective_until' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $model->update($values);
        return response()->json($model->fresh());
    }

    public function deleteAdminModel(int $id)
    {
        $model = AiModelPrice::query()->findOrFail($id);
        $model->update(['is_active' => false]);
        return response()->json(['success' => true]);
    }

    public function adminCreditPackages()
    {
        return response()->json(AiCreditPackage::query()->orderBy('sort_order')->get());
    }

    public function saveAdminCreditPackage(Request $request, ?int $id = null)
    {
        $values = $request->validate([
            'name' => 'required|string|max:80|unique:ai_credit_packages,name' . ($id ? ',' . $id : ''),
            'credits' => 'required|integer|min:1|max:1000000000',
            'price_sar' => 'required|numeric|min:0.01|max:1000000',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:10000',
        ]);
        $package = $id ? AiCreditPackage::query()->findOrFail($id) : new AiCreditPackage();
        $package->fill($values)->save();
        return response()->json($package->fresh(), $id ? 200 : 201);
    }

    public function deleteAdminCreditPackage(int $id)
    {
        $package = AiCreditPackage::query()->findOrFail($id);
        if (AiCreditPurchase::query()->where('credit_package_id', $id)->exists()) $package->update(['is_active' => false]);
        else $package->delete();
        return response()->json(['success' => true]);
    }

    private function validateModel(Request $request, bool $required): array
    {
        $prefix = $required ? 'required' : 'sometimes';
        return $request->validate([
            'provider' => "$prefix|string|in:openai,claude,gemini,groq",
            'model_key' => "$prefix|string|max:180",
            'display_name' => "$prefix|string|max:180",
            'input_price_per_million' => "$prefix|numeric|min:0|max:1000000",
            'output_price_per_million' => "$prefix|numeric|min:0|max:1000000",
            'cached_input_price_per_million' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'image_price_per_unit' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'audio_price_per_minute' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'audio_output_price_per_million_chars' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'capabilities' => 'sometimes|array',
            'max_context_tokens' => 'sometimes|nullable|integer|min:1',
            'max_output_tokens' => 'sometimes|nullable|integer|min:1',
            'pricing_version' => "$prefix|string|max:80",
            'effective_at' => 'sometimes|nullable|date',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    public function adminRouting()
    {
        $rules = DB::table('ai_routing_rules as rules')->join('ai_model_prices as models', 'models.id', '=', 'rules.primary_model_id')->select('rules.*', 'models.provider', 'models.model_key', 'models.display_name')->orderBy('task_type')->orderBy('operating_mode')->get();
        return response()->json($rules->map(function ($rule) {
            $rule->fallback_model_ids = is_string($rule->fallback_model_ids) ? (json_decode($rule->fallback_model_ids, true) ?: []) : ($rule->fallback_model_ids ?: []);
            return $rule;
        }));
    }

    public function saveAdminRouting(Request $request)
    {
        $data = $request->validate(['task_type' => 'required|string|max:80', 'operating_mode' => 'required|in:auto,economy,premium', 'primary_model_id' => 'required|integer|exists:ai_model_prices,id', 'fallback_model_ids' => 'sometimes|array', 'fallback_model_ids.*' => 'integer|exists:ai_model_prices,id', 'max_retries' => 'sometimes|integer|min:0|max:3', 'max_latency_ms' => 'sometimes|nullable|integer|min:1', 'is_active' => 'sometimes|boolean']);
        $data['fallback_model_ids'] = json_encode($data['fallback_model_ids'] ?? []);
        DB::table('ai_routing_rules')->updateOrInsert(['task_type' => $data['task_type'], 'operating_mode' => $data['operating_mode']], $data + ['updated_at' => now(), 'created_at' => now()]);
        return response()->json(['success' => true]);
    }

    public function adminCreditSettings()
    {
        $settings = DB::table('ai_credit_settings')->first();
        if ($settings) {
            foreach (['notification_thresholds', 'enabled_modes'] as $field) if (is_string($settings->{$field} ?? null)) $settings->{$field} = json_decode($settings->{$field}, true);
        }
        return response()->json($settings);
    }

    public function updateAdminCreditSettings(Request $request)
    {
        $data = $request->validate(['credits_per_usd' => 'sometimes|integer|min:1|max:100000000', 'cost_multiplier' => 'sometimes|numeric|min:0.01|max:1000', 'reservation_minimum' => 'sometimes|integer|min:1', 'reservation_maximum' => 'sometimes|integer|min:1', 'notification_thresholds' => 'sometimes|array', 'notification_thresholds.*' => 'integer|in:50,80,90,100', 'enabled_modes' => 'sometimes|array|min:1', 'enabled_modes.*' => 'in:auto,economy,premium', 'system_enabled' => 'sometimes|boolean']);
        $current = $this->credits->settings();
        $values = array_merge((array) $current, $data, ['updated_at' => now()]);
        unset($values['id']);
        foreach (['notification_thresholds', 'enabled_modes'] as $field) if (isset($values[$field]) && is_array($values[$field])) $values[$field] = json_encode(array_values(array_unique($values[$field])));
        $minimumReservation = (int) ($data['reservation_minimum'] ?? $current->reservation_minimum);
        $maximumReservation = (int) ($data['reservation_maximum'] ?? $current->reservation_maximum);
        if ($maximumReservation < $minimumReservation) return response()->json(['message' => 'Maximum reservation must be greater than or equal to the minimum.'], 422);
        DB::table('ai_credit_settings')->where('id', 1)->update($values);
        return $this->adminCreditSettings();
    }

    public function adminUsage(Request $request)
    {
        $query = AiUsageLog::query()->with('business:id,name,business_name')->latest();
        if ($request->filled('business_id')) $query->where('business_id', $request->integer('business_id'));
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        $rows = $query->paginate(min(100, max(1, $request->integer('per_page', 50))));
        $rows->getCollection()->transform(fn (AiUsageLog $row) => $row->makeHidden(['response_payload', 'metadata']));
        return response()->json($rows);
    }

    public function reconcileUsage(Request $request, int $id)
    {
        $data = $request->validate([
            'resolution' => 'required|in:release,settle',
            'input_tokens' => 'required_if:resolution,settle|integer|min:0|max:1000000000',
            'output_tokens' => 'required_if:resolution,settle|integer|min:0|max:1000000000',
            'cached_tokens' => 'sometimes|integer|min:0|max:1000000000',
            'audio_seconds' => 'sometimes|integer|min:0|max:86400',
            'image_units' => 'sometimes|integer|min:0|max:100000',
        ]);
        return DB::transaction(function () use ($data, $id) {
            $usage = AiUsageLog::query()->where('status', 'needs_reconciliation')->lockForUpdate()->findOrFail($id);
            $metadata = $usage->metadata ?? [];
            $reserved = (int) ($metadata['reserved_credits'] ?? $metadata['reservation_quarantined'] ?? 0);
            if ($reserved <= 0) return response()->json(['message' => 'The quarantined reservation is missing; reconcile the wallet ledger manually.'], 409);
            $business = BusinessProfile::query()->findOrFail($usage->business_id);

            if ($data['resolution'] === 'release') {
                $this->credits->releaseFailedReservation($usage, $reserved, new \App\Exceptions\AiProviderRequestException('Admin reconciliation', 400, 'Administrator confirmed provider did not bill this request.'));
            } else {
                $this->credits->settle($business, $usage, $reserved, (int) $data['input_tokens'], (int) $data['output_tokens'], (int) ($data['cached_tokens'] ?? 0), (int) ($data['audio_seconds'] ?? 0), (int) ($data['image_units'] ?? 0), (int) ($metadata['audio_output_chars'] ?? 0));
            }

            return response()->json(['success' => true, 'usage' => $usage->fresh()->makeHidden(['response_payload', 'metadata']), 'balance' => $this->credits->balance($business)]);
        });
    }

    public function adminBusinessUsage(int $id)
    {
        $business = BusinessProfile::query()->findOrFail($id);
        $wallet = $this->credits->balance($business);
        return response()->json(['business' => $business->only(['id', 'name', 'business_name', 'user_id']), 'balance' => $wallet,
            'usage_by_task' => AiUsageLog::query()->where('business_id', $id)->select('task_type', DB::raw('COUNT(*) as requests'), DB::raw('SUM(credits_consumed) as credits'), DB::raw('SUM(provider_cost_usd) as provider_cost_usd'))->groupBy('task_type')->get(),
            'usage_by_model' => AiUsageLog::query()->where('business_id', $id)->select('provider', 'model_key', DB::raw('COUNT(*) as requests'), DB::raw('SUM(credits_consumed) as credits'), DB::raw('SUM(provider_cost_usd) as provider_cost_usd'))->groupBy('provider', 'model_key')->get(),
            'purchases' => AiCreditPurchase::query()->where('business_id', $id)->latest()->limit(50)->get(),
            'transactions' => AiCreditTransaction::query()->where('business_id', $id)->latest('created_at')->limit(100)->get(),
            'failed' => AiUsageLog::query()->where('business_id', $id)->whereIn('status', ['failed', 'needs_reconciliation'])->latest()->limit(50)->get()->map(fn (AiUsageLog $row) => $row->makeHidden(['response_payload', 'metadata'])),
        ]);
    }

    public function adjustBusinessCredits(Request $request, int $id)
    {
        $data = $request->validate(['amount' => 'required|integer|between:-100000000,100000000|not_in:0', 'reason' => 'required|string|min:8|max:500']);
        $business = BusinessProfile::query()->findOrFail($id);
        $this->credits->adjust($business, (int) $data['amount'], $data['reason'], (int) $request->user()->id);
        return response()->json(['success' => true, 'balance' => $this->credits->balance($business)]);
    }
}
