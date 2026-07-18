<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $category = $request->query('category');
        
        $query = Post::where('status', 'published')
            ->orderBy('published_at', 'desc');
        
        if ($category && $category !== 'all') {
            $query->where('category', $category);
        }
        
        $posts = $query->paginate(20);
        
        return response()->json([
            'data' => $posts->map(function ($post) {
                return [
                    'id' => $post->id,
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'excerpt' => $post->excerpt,
                    'category' => $post->category,
                    'published_at' => $post->published_at,
                    'featured_image_url' => $post->featured_image_url,
                ];
            }),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    public function show($slug)
    {
        $post = Post::where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();
        
        return response()->json($post);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'excerpt' => 'nullable|string',
            'category' => 'nullable|string',
            'tags' => 'nullable|array',
            'meta_description' => 'nullable|string',
            'featured_image_url' => 'nullable|string',
        ]);

        $slug = Str::slug($validated['title']);
        
        // Ensure unique slug
        $originalSlug = $slug;
        $counter = 1;
        while (Post::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        $post = Post::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'body' => $validated['body'],
            'excerpt' => $validated['excerpt'] ?? Str::limit(strip_tags($validated['body']), 200),
            'status' => 'draft',
            'category' => $validated['category'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'featured_image_url' => $validated['featured_image_url'] ?? null,
        ]);

        Log::info('Blog post created as draft', ['post_id' => $post->id, 'title' => $post->title]);

        return response()->json($post, 201);
    }

    public function publish($id)
    {
        $post = Post::findOrFail($id);
        
        $post->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        Log::info('Blog post published', ['post_id' => $post->id, 'title' => $post->title]);

        return response()->json($post);
    }

    public function reject($id)
    {
        $post = Post::findOrFail($id);
        
        $title = $post->title;
        $post->delete();

        Log::info('Blog post rejected/deleted', ['post_id' => $id, 'title' => $title]);

        return response()->json(['message' => 'Draft deleted']);
    }

    public function approveWebhook(Request $request, $id)
    {
        $secret = env('BLOG_APPROVAL_SECRET');
        if (!$secret) {
            return response('❌ BLOG_APPROVAL_SECRET not configured', 500);
        }

        $token = $request->query('token');
        
        if ($token !== $secret) {
            return response('❌ Invalid token', 403);
        }

        $post = Post::findOrFail($id);
        
        $post->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        Log::info('Blog post published via webhook', ['post_id' => $post->id, 'title' => $post->title]);

        return response('<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تم نشر المقال</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #050505; }
        .container { text-align: center; padding: 40px; }
        .emoji { font-size: 80px; margin-bottom: 20px; }
        h1 { color: #C6FF00; margin: 0 0 20px 0; }
        p { color: rgba(255,255,255,0.7); margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="emoji">✅</div>
        <h1>تم نشر المقال بنجاح</h1>
        <p>Article published successfully</p>
    </div>
</body>
</html>');
    }

    public function rejectWebhook(Request $request, $id)
    {
        $secret = env('BLOG_APPROVAL_SECRET');
        if (!$secret) {
            return response('❌ BLOG_APPROVAL_SECRET not configured', 500);
        }

        $token = $request->query('token');
        
        if ($token !== $secret) {
            return response('❌ Invalid token', 403);
        }

        $post = Post::findOrFail($id);
        
        $title = $post->title;
        $post->delete();

        Log::info('Blog post rejected via webhook', ['post_id' => $id, 'title' => $title]);

        return response('<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تم حذف المسودة</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #050505; }
        .container { text-align: center; padding: 40px; }
        .emoji { font-size: 80px; margin-bottom: 20px; }
        h1 { color: #FF7070; margin: 0 0 20px 0; }
        p { color: rgba(255,255,255,0.7); margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="emoji">🗑️</div>
        <h1>تم حذف المسودة</h1>
        <p>Draft deleted successfully</p>
    </div>
</body>
</html>');
    }

    /**
     * Admin-only: Get all posts (including drafts)
     */
    public function adminIndex(Request $request)
    {
        $posts = Post::orderBy('created_at', 'desc')->paginate(20);
        
        return response()->json($posts);
    }
}
