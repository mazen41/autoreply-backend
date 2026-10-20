<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\Conversation;
use App\Models\SupportCase;
use App\Models\TeamMember;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportCaseController extends Controller
{
    public function index(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $filters = $request->validate([
            'status' => ['sometimes', 'in:open,pending,resolved,closed'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = SupportCase::query()->where('business_id', $business->id)
            ->with(['conversation:id,sender_name,sender_id,channel_id', 'conversation.channel:id,type,page_name', 'commerceOrder:id,order_number,external_id,total,currency', 'assignee:id,name', 'creator:id,name'])
            ->withCount('comments');

        $membership = TeamMember::query()->where('business_id', $business->id)->where('user_id', $request->user()->id)->where('is_active', true)->first();
        if ($membership?->role === 'agent') $query->where('assigned_to', $request->user()->id);
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        if (isset($filters['priority'])) $query->where('priority', $filters['priority']);
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(fn ($q) => $q->where('case_number', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhereHas('conversation', fn ($conversation) => $conversation->where('sender_name', 'like', "%{$search}%")
                    ->orWhere('sender_id', 'like', "%{$search}%")));
        }

        $counts = SupportCase::query()->where('business_id', $business->id)
            ->when($membership?->role === 'agent', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $cases = $query->latest()->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        return response()->json(['data' => $cases->items(), 'current_page' => $cases->currentPage(), 'per_page' => $cases->perPage(), 'total' => $cases->total(), 'last_page' => $cases->lastPage(), 'counts' => $counts]);
    }

    public function options(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $conversations = Conversation::query()->where('business_id', $business->id)->latest('last_message_at')
            ->limit(100)->get(['id', 'sender_name', 'sender_id'])
            ->map(fn (Conversation $conversation) => ['id' => $conversation->id, 'label' => trim(($conversation->sender_name ?: $conversation->sender_id) . ' · Conversation #' . $conversation->id)]);
        $orders = CommerceOrder::query()->where('business_id', $business->id)->latest('ordered_at')->limit(100)
            ->get(['id', 'order_number', 'external_id', 'customer_name', 'total', 'currency'])
            ->map(fn (CommerceOrder $order) => ['id' => $order->id, 'label' => trim('#' . ($order->order_number ?: $order->external_id) . ' · ' . ($order->customer_name ?: 'Customer') . ' · ' . $order->total . ' ' . $order->currency)]);
        $members = TeamMember::query()->where('business_id', $business->id)->where('is_active', true)->whereIn('role', ['owner', 'manager', 'agent'])
            ->with('user:id,name')->get()->map(fn (TeamMember $member) => ['id' => $member->user_id, 'name' => $member->user?->name]);
        $owner = \App\Models\User::query()->find($business->user_id);
        if ($owner && !$members->contains(fn ($member) => (int) $member['id'] === (int) $owner->id)) $members->prepend(['id' => $owner->id, 'name' => $owner->name]);
        return response()->json(['conversations' => $conversations, 'orders' => $orders, 'assignees' => $members->values()]);
    }

    public function store(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:10000'],
            'conversation_id' => ['nullable', 'integer'],
            'commerce_order_id' => ['nullable', 'integer'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'assigned_to' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
        ]);
        $this->validateLinks($business->id, $data);
        $this->validateAssignee($business->id, $business->user_id, $data['assigned_to'] ?? null);
        $membership = TeamMember::query()->where('business_id', $business->id)->where('user_id', $request->user()->id)->where('is_active', true)->first();

        $case = SupportCase::create(array_merge($data, [
            'business_id' => $business->id,
            'created_by' => $request->user()->id,
            'assigned_to' => ($data['assigned_to'] ?? null) ?? ($membership?->role === 'agent' ? $request->user()->id : null),
            'case_number' => 'CASE-' . strtoupper(bin2hex(random_bytes(5))),
            'status' => 'open',
            'priority' => $data['priority'] ?? 'normal',
        ]));
        $case->events()->create(['actor_id' => $request->user()->id, 'event_type' => 'created', 'details' => ['status' => 'open', 'priority' => $case->priority]]);

        return response()->json(['case' => $case->load($this->relations())], 201);
    }

    public function show(Request $request, int $caseId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        return response()->json(['case' => $this->caseQuery($business->id, $request->user()->id)->findOrFail($caseId)->load(array_merge($this->relations(), ['comments.author:id,name', 'events.actor:id,name']))]);
    }

    public function update(Request $request, int $caseId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $case = $this->caseQuery($business->id, $request->user()->id)->findOrFail($caseId);
        $data = $request->validate([
            'subject' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['open', 'pending', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'conversation_id' => ['sometimes', 'nullable', 'integer'],
            'commerce_order_id' => ['sometimes', 'nullable', 'integer'],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $this->validateLinks($business->id, $data);
        if (array_key_exists('assigned_to', $data)) $this->validateAssignee($business->id, $business->user_id, $data['assigned_to']);
        if (isset($data['status'])) $data['resolved_at'] = in_array($data['status'], ['resolved', 'closed'], true) ? ($case->resolved_at ?? now()) : null;
        abort_if($data === [], 422, 'Provide fields to update.');
        $changes = collect($data)->map(fn ($value, $key) => ['from' => $case->getAttribute($key), 'to' => $value])->all();
        $case->update($data);
        $case->events()->create(['actor_id' => $request->user()->id, 'event_type' => 'updated', 'details' => $changes]);
        return response()->json(['case' => $case->fresh()->load(array_merge($this->relations(), ['comments.author:id,name', 'events.actor:id,name']))]);
    }

    public function comment(Request $request, int $caseId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');
        $case = $this->caseQuery($business->id, $request->user()->id)->findOrFail($caseId);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $comment = $case->comments()->create(['author_id' => $request->user()->id, 'body' => trim($data['body'])]);
        $case->events()->create(['actor_id' => $request->user()->id, 'event_type' => 'commented']);
        return response()->json(['comment' => $comment->load('author:id,name')], 201);
    }

    private function caseQuery(int $businessId, int $userId)
    {
        $query = SupportCase::query()->where('business_id', $businessId);
        $membership = TeamMember::query()->where('business_id', $businessId)->where('user_id', $userId)->where('is_active', true)->first();
        if ($membership?->role === 'agent') $query->where('assigned_to', $userId);
        return $query;
    }

    private function validateLinks(int $businessId, array $data): void
    {
        if (!empty($data['conversation_id'])) {
            abort_unless(Conversation::query()->where('business_id', $businessId)->whereKey($data['conversation_id'])->exists(), 404);
        }
        if (!empty($data['commerce_order_id'])) {
            abort_unless(CommerceOrder::query()->where('business_id', $businessId)->whereKey($data['commerce_order_id'])->exists(), 404);
        }
    }

    private function validateAssignee(int $businessId, int $ownerId, ?int $userId): void
    {
        if ($userId === null || $userId === $ownerId) return;
        TeamMember::query()->where('business_id', $businessId)->where('user_id', $userId)->where('is_active', true)
            ->whereIn('role', ['owner', 'manager', 'agent'])->firstOrFail();
    }

    private function relations(): array
    {
        return ['conversation:id,sender_name,sender_id,channel_id', 'conversation.channel:id,type,page_name', 'commerceOrder:id,order_number,external_id,total,currency', 'assignee:id,name', 'creator:id,name'];
    }
}
