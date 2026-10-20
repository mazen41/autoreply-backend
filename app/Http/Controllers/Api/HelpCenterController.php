<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\HelpCenterArticle;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HelpCenterController extends Controller
{
    public function publicIndex(Request $request, int $businessId)
    {
        $business = BusinessProfile::query()->findOrFail($businessId);
        $search = trim((string) $request->query('q', ''));
        $articles = HelpCenterArticle::query()->where('business_id', $business->id)->where('status', 'published')
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches->where('title', 'like', '%' . $search . '%')
                ->orWhere('excerpt', 'like', '%' . $search . '%')->orWhere('content', 'like', '%' . $search . '%')
                ->orWhere('category', 'like', '%' . $search . '%')))
            ->orderBy('category')->orderBy('title')->limit(100)
            ->get(['id', 'title', 'slug', 'category', 'excerpt', 'published_at']);
        return response()->json(['business' => ['name' => $business->business_name ?: $business->name], 'articles' => $articles]);
    }

    public function publicShow(int $businessId, string $slug)
    {
        $business = BusinessProfile::query()->findOrFail($businessId);
        $article = HelpCenterArticle::query()->where('business_id', $business->id)->where('status', 'published')->where('slug', $slug)
            ->firstOrFail(['title', 'slug', 'category', 'excerpt', 'content', 'published_at']);
        return response()->json(['business' => ['name' => $business->business_name ?: $business->name], 'article' => $article]);
    }

    public function index(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read');
        return response()->json(['articles' => HelpCenterArticle::query()->where('business_id', $business->id)->latest()->get()]);
    }

    public function store(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'write');
        $data = $this->validateArticle($request, $business->id);
        $data['slug'] = ($data['slug'] ?? '') ?: $this->availableSlug($business->id, $data['title']);
        $data['status'] = $data['status'] ?? 'draft';
        $data['published_at'] = $data['status'] === 'published' ? now() : null;
        $article = HelpCenterArticle::create($data + ['business_id' => $business->id, 'created_by' => $request->user()->id]);
        return response()->json(['article' => $article], 201);
    }

    public function update(Request $request, int $articleId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'write');
        $article = HelpCenterArticle::query()->where('business_id', $business->id)->findOrFail($articleId);
        $data = $this->validateArticle($request, $business->id, $article->id);
        if (array_key_exists('title', $data) && empty($data['slug'])) $data['slug'] = $this->availableSlug($business->id, $data['title'], $article->id);
        if (isset($data['status'])) $data['published_at'] = $data['status'] === 'published' ? ($article->published_at ?? now()) : null;
        $article->update($data);
        return response()->json(['article' => $article->fresh()]);
    }

    public function destroy(Request $request, int $articleId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'write');
        HelpCenterArticle::query()->where('business_id', $business->id)->findOrFail($articleId)->delete();
        return response()->json(['success' => true]);
    }

    private function validateArticle(Request $request, int $businessId, ?int $ignoreId = null): array
    {
        $required = $ignoreId ? 'sometimes' : 'required';
        return $request->validate([
            'title' => [$required, 'string', 'max:191'],
            'slug' => ['nullable', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('help_center_articles', 'slug')->where('business_id', $businessId)->ignore($ignoreId)],
            'category' => [$required, 'string', 'max:100'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => [$required, 'string', 'max:50000'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ]);
    }

    private function availableSlug(int $businessId, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'article';
        $slug = $base;
        $suffix = 2;
        while (HelpCenterArticle::query()->where('business_id', $businessId)->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base . '-' . $suffix++;
        }
        return $slug;
    }
}
