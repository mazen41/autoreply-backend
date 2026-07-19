<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('updated_at', '>=', now()->subDays(30))->count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
            'total_revenue' => (float) Subscription::sum('amount_paid'),
            'total_channels' => Channel::count(),
            'total_messages' => Message::count(),
            'inbound_messages' => Message::where('direction', 'inbound')->count(),
            'ai_replies' => Message::where('is_ai', true)->count(),
            'total_conversations' => Conversation::count(),
            'users_this_month' => User::where('created_at', '>=', now()->startOfMonth())->count(),
            'revenue_this_month' => (float) Subscription::where('created_at', '>=', now()->startOfMonth())->sum('amount_paid'),
            'messages_today' => Message::whereDate('created_at', today())->count(),
            'conversations_today' => Conversation::whereDate('created_at', today())->count(),
        ];

        return response()->json([
            'stats' => $stats,
            'recent_users' => User::latest()->take(6)->get(['id', 'name', 'email', 'created_at', 'updated_at', 'is_admin']),
            'recent_subscriptions' => Subscription::with('user:id,name,email,is_admin,created_at', 'package')->latest()->take(6)->get(),
            'recent_activity' => Message::with(['conversation.channel:id,type,page_name,status'])
                ->latest()
                ->take(8)
                ->get(['id', 'conversation_id', 'content', 'direction', 'is_ai', 'created_at']),
            'ai_settings' => [
                'provider' => config('services.ai.provider', 'gemini'),
                'fallback_provider' => config('services.ai.fallback_provider', 'claude'),
                'gemini_configured' => (bool) config('services.gemini.api_key'),
                'claude_configured' => (bool) config('services.claude.api_key'),
            ],
        ]);
    }

    public function users(Request $request)
    {
        $query = User::query()
            ->with(['subscription.package'])
            ->withCount('channels')
            ->select('users.*')
            ->selectSub(function ($query) {
                $query->from('messages')
                    ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
                    ->join('channels', 'conversations.channel_id', '=', 'channels.id')
                    ->whereColumn('channels.user_id', 'users.id')
                    ->selectRaw('count(*)');
            }, 'messages_count');

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_admin') && $request->is_admin !== null && $request->is_admin !== '') {
            $query->where('is_admin', filter_var($request->is_admin, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function showUser($id)
    {
        return response()->json(User::with(['subscription.package', 'channels'])->findOrFail($id));
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'is_admin' => 'sometimes|boolean',
        ]);
        $user->update($request->only(['name', 'email', 'is_admin']));
        return response()->json($user);
    }

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot delete yourself'], 403);
        }
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }

    public function toggleAdmin($id)
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot change your own admin status'], 403);
        }
        $user->update(['is_admin' => !$user->is_admin]);
        return response()->json(['message' => 'Admin status updated', 'user' => $user]);
    }

    public function packages(Request $request)
    {
        return response()->json(Package::orderBy('sort_order')->get());
    }

    public function showPackage($id)
    {
        return response()->json(Package::withCount('subscriptions')->findOrFail($id));
    }

    public function createPackage(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255', 'name_ar' => 'required|string|max:255',
            'description' => 'nullable|string', 'description_ar' => 'nullable|string',
            'price_monthly' => 'required|numeric|min:0', 'price_yearly' => 'required|numeric|min:0',
            'ai_replies_limit' => 'required|integer', 'channels_limit' => 'required|integer',
            'tools_limit' => 'required|integer', 'blog_posts_limit' => 'required|integer',
            'features' => 'nullable|array', 'features_ar' => 'nullable|array',
            'is_popular' => 'sometimes|boolean', 'is_active' => 'sometimes|boolean', 'sort_order' => 'sometimes|integer',
        ]);
        return response()->json(Package::create($request->all()), 201);
    }

    public function updatePackage(Request $request, $id)
    {
        $package = Package::findOrFail($id);
        $request->validate([
            'name' => 'sometimes|string|max:255', 'name_ar' => 'sometimes|string|max:255',
            'description' => 'nullable|string', 'description_ar' => 'nullable|string',
            'price_monthly' => 'sometimes|numeric|min:0', 'price_yearly' => 'sometimes|numeric|min:0',
            'ai_replies_limit' => 'sometimes|integer', 'channels_limit' => 'sometimes|integer',
            'tools_limit' => 'sometimes|integer', 'blog_posts_limit' => 'sometimes|integer',
            'features' => 'nullable|array', 'features_ar' => 'nullable|array',
            'is_popular' => 'sometimes|boolean', 'is_active' => 'sometimes|boolean', 'sort_order' => 'sometimes|integer',
        ]);
        $package->update($request->all());
        return response()->json($package);
    }

    public function deletePackage($id)
    {
        $package = Package::findOrFail($id);
        if ($package->subscriptions()->exists()) {
            return response()->json(['message' => 'Cannot delete package with active subscriptions'], 403);
        }
        $package->delete();
        return response()->json(['message' => 'Package deleted successfully']);
    }

    public function subscriptions(Request $request)
    {
        $query = Subscription::with('user', 'package');
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")->orWhere('email', 'like', "%{$request->search}%");
            });
        }
        return response()->json($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function showSubscription($id)
    {
        return response()->json(Subscription::with('user', 'package')->findOrFail($id));
    }

    public function updateSubscription(Request $request, $id)
    {
        $subscription = Subscription::findOrFail($id);
        $request->validate(['status' => 'sometimes|in:active,cancelled,expired,trial', 'ends_at' => 'sometimes|date']);
        $subscription->update($request->only(['status', 'ends_at']));
        return response()->json($subscription);
    }

    public function settings()
    {
        return response()->json([
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'ai_provider' => config('services.ai.provider', 'gemini'),
            'ai_fallback_provider' => config('services.ai.fallback_provider', 'claude'),
            'ai_temperature' => (float) config('services.ai.temperature', 0.7),
            'ai_max_tokens' => (int) config('services.ai.max_tokens', 500),
            'ai_timeout' => (int) config('services.ai.timeout', 30),
            'ai_retries' => (int) config('services.ai.retries', 3),
            'ai_streaming' => (bool) config('services.ai.streaming', false),
            'gemini_api_key' => config('services.gemini.api_key') ? '***' : null,
            'gemini_model' => config('services.gemini.model', 'gemini-2.5-flash'),
            'gemini_configured' => (bool) config('services.gemini.api_key'),
            'claude_api_key' => config('services.claude.api_key') ? '***' : null,
            'claude_model' => config('services.claude.model', 'claude-haiku-4-5-20251001'),
            'claude_configured' => (bool) config('services.claude.api_key'),
            'meta_app_id' => config('services.meta.app_id'),
            'meta_app_secret' => config('services.meta.app_secret') ? '***' : null,
            'google_client_id' => config('services.google.client_id'),
            'google_client_secret' => config('services.google.client_secret') ? '***' : null,
            'pusher_app_id' => config('broadcasting.connections.pusher.app_id'),
            'pusher_app_key' => config('broadcasting.connections.pusher.key'),
            'pusher_secret' => config('broadcasting.connections.pusher.secret') ? '***' : null,
            'pusher_cluster' => config('broadcasting.connections.pusher.options.cluster'),
            'pusher_host' => config('broadcasting.connections.pusher.options.host'),
            'moyasar_publishable_key' => config('services.moyasar.publishable_key'),
            'moyasar_secret_key' => config('services.moyasar.secret_key') ? '***' : null,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'app_name' => 'sometimes|string|max:255',
            'ai_provider' => 'sometimes|in:gemini,claude',
            'ai_fallback_provider' => 'sometimes|in:gemini,claude',
            'ai_temperature' => 'sometimes|numeric|min:0|max:2',
            'ai_max_tokens' => 'sometimes|integer|min:50|max:8000',
            'ai_timeout' => 'sometimes|integer|min:5|max:120',
            'ai_retries' => 'sometimes|integer|min:0|max:5',
            'ai_streaming' => 'sometimes|boolean',
            'gemini_api_key' => 'nullable|string', 'gemini_model' => 'sometimes|string|max:120',
            'claude_api_key' => 'nullable|string', 'claude_model' => 'sometimes|string|max:120',
            'meta_app_id' => 'nullable|string', 'meta_app_secret' => 'nullable|string',
            'google_client_id' => 'nullable|string', 'google_client_secret' => 'nullable|string',
            'pusher_app_id' => 'nullable|string', 'pusher_app_key' => 'nullable|string', 'pusher_secret' => 'nullable|string',
            'pusher_cluster' => 'nullable|string', 'pusher_host' => 'nullable|string',
            'moyasar_publishable_key' => 'nullable|string', 'moyasar_secret_key' => 'nullable|string',
        ]);

        $map = [
            'app_name' => 'APP_NAME', 'ai_provider' => 'AI_PROVIDER', 'ai_fallback_provider' => 'AI_FALLBACK_PROVIDER',
            'ai_temperature' => 'AI_TEMPERATURE', 'ai_max_tokens' => 'AI_MAX_TOKENS', 'ai_timeout' => 'AI_TIMEOUT',
            'ai_retries' => 'AI_RETRIES', 'ai_streaming' => 'AI_STREAMING',
            'gemini_api_key' => 'GEMINI_API_KEY', 'gemini_model' => 'GEMINI_MODEL',
            'claude_api_key' => 'ANTHROPIC_API_KEY', 'claude_model' => 'CLAUDE_MODEL',
            'meta_app_id' => 'META_APP_ID', 'meta_app_secret' => 'META_APP_SECRET',
            'google_client_id' => 'GOOGLE_CLIENT_ID', 'google_client_secret' => 'GOOGLE_CLIENT_SECRET',
            'pusher_app_id' => 'PUSHER_APP_ID', 'pusher_app_key' => 'PUSHER_APP_KEY', 'pusher_secret' => 'PUSHER_SECRET',
            'pusher_cluster' => 'PUSHER_CLUSTER', 'pusher_host' => 'PUSHER_HOST',
            'moyasar_publishable_key' => 'MOYASAR_PUBLISHABLE_KEY', 'moyasar_secret_key' => 'MOYASAR_SECRET_KEY',
        ];

        foreach ($map as $field => $envKey) {
            if (!$request->has($field)) continue;
            $value = $request->input($field);
            if ($value === null || $value === '' || $value === '***') continue;
            if (is_bool($value)) $value = $value ? 'true' : 'false';
            $this->setEnvValue($envKey, (string) $value);
        }

        Artisan::call('config:clear');
        return response()->json(['message' => 'Settings updated successfully']);
    }

    protected function setEnvValue(string $key, string $value): void
    {
        $envFile = app()->environmentFilePath();
        $contents = file_exists($envFile) ? file_get_contents($envFile) : '';
        $escaped = $this->quoteEnvValue($value);

        if (preg_match("/^{$key}=.*$/m", $contents)) {
            $contents = preg_replace("/^{$key}=.*$/m", "{$key}={$escaped}", $contents);
        } else {
            $contents = rtrim($contents) . PHP_EOL . "{$key}={$escaped}" . PHP_EOL;
        }

        file_put_contents($envFile, $contents);
    }

    protected function quoteEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"|\'|=/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }
}
