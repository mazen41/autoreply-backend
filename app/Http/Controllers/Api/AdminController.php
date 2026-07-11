<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    // Dashboard Statistics
    public function dashboard()
    {
        $stats = [
            'total_users' => User::count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
            'total_revenue' => Subscription::sum('amount_paid'),
            'total_channels' => Channel::count(),
            'total_messages' => Message::count(),
            'total_conversations' => Conversation::count(),
            'users_this_month' => User::where('created_at', '>=', now()->startOfMonth())->count(),
            'revenue_this_month' => Subscription::where('created_at', '>=', now()->startOfMonth())->sum('amount_paid'),
        ];

        $recentUsers = User::latest()->take(5)->get(['id', 'name', 'email', 'created_at', 'is_admin']);
        $recentSubscriptions = Subscription::with('user', 'package')->latest()->take(5)->get();

        return response()->json([
            'stats' => $stats,
            'recent_users' => $recentUsers,
            'recent_subscriptions' => $recentSubscriptions,
        ]);
    }

    // Users Management
    public function users(Request $request)
    {
        $query = User::query();

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
        }

        if ($request->is_admin !== null) {
            $query->where('is_admin', $request->is_admin);
        }

        $users = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json($users);
    }

    public function showUser($id)
    {
        $user = User::with(['subscription.package', 'channels'])->findOrFail($id);
        return response()->json($user);
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

        return response()->json([
            'message' => 'Admin status updated',
            'user' => $user
        ]);
    }

    // Packages Management
    public function packages(Request $request)
    {
        $packages = Package::orderBy('sort_order')->get();
        return response()->json($packages);
    }

    public function showPackage($id)
    {
        $package = Package::withCount('subscriptions')->findOrFail($id);
        return response()->json($package);
    }

    public function createPackage(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'description' => 'nullable|string',
            'description_ar' => 'nullable|string',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'ai_replies_limit' => 'required|integer',
            'channels_limit' => 'required|integer',
            'tools_limit' => 'required|integer',
            'blog_posts_limit' => 'required|integer',
            'features' => 'nullable|array',
            'features_ar' => 'nullable|array',
            'is_popular' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $package = Package::create($request->all());

        return response()->json($package, 201);
    }

    public function updatePackage(Request $request, $id)
    {
        $package = Package::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'name_ar' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'description_ar' => 'nullable|string',
            'price_monthly' => 'sometimes|numeric|min:0',
            'price_yearly' => 'sometimes|numeric|min:0',
            'ai_replies_limit' => 'sometimes|integer',
            'channels_limit' => 'sometimes|integer',
            'tools_limit' => 'sometimes|integer',
            'blog_posts_limit' => 'sometimes|integer',
            'features' => 'nullable|array',
            'features_ar' => 'nullable|array',
            'is_popular' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
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

    // Subscriptions/Payments Management
    public function subscriptions(Request $request)
    {
        $query = Subscription::with('user', 'package');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->search) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        $subscriptions = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json($subscriptions);
    }

    public function showSubscription($id)
    {
        $subscription = Subscription::with('user', 'package')->findOrFail($id);
        return response()->json($subscription);
    }

    public function updateSubscription(Request $request, $id)
    {
        $subscription = Subscription::findOrFail($id);

        $request->validate([
            'status' => 'sometimes|in:active,cancelled,expired,trial',
            'ends_at' => 'sometimes|date',
        ]);

        $subscription->update($request->only(['status', 'ends_at']));

        return response()->json($subscription);
    }

    // Settings
    public function settings()
    {
        $settings = [
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'moyasar_publishable_key' => config('services.moyasar.publishable_key'),
            'moyasar_secret_key' => config('services.moyasar.secret_key') ? '***' : null,
        ];

        return response()->json($settings);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'app_name' => 'sometimes|string|max:255',
            'moyasar_publishable_key' => 'sometimes|string',
            'moyasar_secret_key' => 'sometimes|string',
        ]);

        if ($request->has('app_name')) {
            $this->setEnvValue('APP_NAME', $request->app_name);
        }

        if ($request->has('moyasar_publishable_key')) {
            $this->setEnvValue('MOYASAR_PUBLISHABLE_KEY', $request->moyasar_publishable_key);
        }

        if ($request->has('moyasar_secret_key')) {
            $this->setEnvValue('MOYASAR_SECRET_KEY', $request->moyasar_secret_key);
        }

        return response()->json(['message' => 'Settings updated successfully']);
    }

    protected function setEnvValue($key, $value)
    {
        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);

        if (strpos($str, "{$key}=") !== false) {
            $str = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $str);
        } else {
            $str .= "\n{$key}={$value}";
        }

        file_put_contents($envFile, $str);
    }
}
