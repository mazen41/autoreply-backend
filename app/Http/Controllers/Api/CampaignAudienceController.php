<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use Illuminate\Http\Request;

class CampaignAudienceController extends Controller
{
    public function segments(Request $request)
    {
        $business = $this->business($request, 'read');
        return response()->json(['data' => Segment::forBusiness($business->id)->latest()->get()]);
    }

    public function storeSegment(Request $request)
    {
        $business = $this->business($request, 'write');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rules' => ['nullable', 'array'],
            'rules.tags' => ['nullable', 'array', 'max:50'],
            'rules.tags.*' => ['string', 'max:100'],
            'rules.min_spent' => ['nullable', 'numeric', 'min:0'],
            'rules.last_order_days_ago' => ['nullable', 'integer', 'min:0'],
            'rules.min_orders' => ['nullable', 'integer', 'min:0'],
            'rules.location' => ['nullable', 'string', 'max:120'],
        ]);

        $segment = Segment::create([
            'business_profile_id' => $business->id,
            'name' => trim($validated['name']),
            'rules' => $validated['rules'] ?? [],
        ]);
        return response()->json(['data' => $segment], 201);
    }

    public function updateSegment(Request $request, int $id)
    {
        $business = $this->business($request, 'write');
        $segment = Segment::forBusiness($business->id)->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.tags' => ['nullable', 'array', 'max:50'],
            'rules.tags.*' => ['string', 'max:100'],
            'rules.min_spent' => ['nullable', 'numeric', 'min:0'],
            'rules.last_order_days_ago' => ['nullable', 'integer', 'min:0'],
            'rules.min_orders' => ['nullable', 'integer', 'min:0'],
            'rules.location' => ['nullable', 'string', 'max:120'],
        ]);
        $segment->update($validated);
        return response()->json(['data' => $segment->fresh()]);
    }

    public function destroySegment(Request $request, int $id)
    {
        $business = $this->business($request, 'write');
        Segment::forBusiness($business->id)->findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    public function templates(Request $request)
    {
        $business = $this->business($request, 'read');
        return response()->json(['data' => CampaignTemplate::forBusiness($business->id)->latest()->get()]);
    }

    public function storeTemplate(Request $request)
    {
        $business = $this->business($request, 'write');
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'channel_type' => ['required', 'string', 'in:whatsapp,instagram,facebook,email,sms'],
            'body' => ['required', 'string', 'max:10000'],
            'category' => ['nullable', 'string', 'max:80'],
        ]);
        $template = CampaignTemplate::create([
            'business_profile_id' => $business->id,
            ...$validated,
            'category' => $validated['category'] ?? 'general',
            'variables' => $this->extractVariables($validated['body']),
        ]);
        return response()->json(['data' => $template], 201);
    }

    public function updateTemplate(Request $request, int $id)
    {
        $business = $this->business($request, 'write');
        $template = CampaignTemplate::forBusiness($business->id)->findOrFail($id);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'channel_type' => ['sometimes', 'required', 'string', 'in:whatsapp,instagram,facebook,email,sms'],
            'body' => ['sometimes', 'required', 'string', 'max:10000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        if (array_key_exists('body', $validated)) {
            $validated['variables'] = $this->extractVariables($validated['body']);
        }
        $template->update($validated);
        return response()->json(['data' => $template->fresh()]);
    }

    public function destroyTemplate(Request $request, int $id)
    {
        $business = $this->business($request, 'write');
        CampaignTemplate::forBusiness($business->id)->findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    private function business(Request $request, string $permission)
    {
        return app(\App\Services\ActiveBusiness::class)->resolve($request, permission: $permission);
    }

    private function extractVariables(string $body): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $body, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }
}
