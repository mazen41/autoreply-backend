<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\SocialCommentEvent;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;

class SocialCommentController extends Controller
{
    public function index(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $filters = $request->validate([
            'platform' => ['sometimes', 'nullable', 'in:facebook,instagram'],
            'channel_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'in:all,needs_attention,replied,received,processing,processed,skipped,failed,retryable'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $statusCounts = SocialCommentEvent::query()
            ->where('business_id', $business->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $total = (int) $statusCounts->sum();
        $replied = (int) ($statusCounts['processed'] ?? 0);

        $query = SocialCommentEvent::query()
            ->where('business_id', $business->id)
            ->with(['channel:id,type,page_name,page_id', 'bot:id,name']);

        if (!empty($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }
        if (!empty($filters['channel_id'])) {
            $channelOwned = $business->channels()->whereKey((int) $filters['channel_id'])->exists();
            if (!$channelOwned) {
                return response()->json(['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0]);
            }
            $query->where('channel_id', (int) $filters['channel_id']);
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'replied') {
                $query->where('status', 'processed');
            } elseif ($filters['status'] === 'needs_attention') {
                $query->whereIn('status', ['received', 'processing', 'skipped', 'failed', 'retryable']);
            } elseif ($filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }
        }
        if (!empty($filters['search'])) {
            $term = addcslashes(trim($filters['search']), '%_\\');
            $query->where(function ($comments) use ($term): void {
                $comments->where('comment_text', 'like', "%{$term}%")
                    ->orWhere('commenter_id', 'like', "%{$term}%")
                    ->orWhere('post_id', 'like', "%{$term}%");
            });
        }

        $events = $query->orderByRaw('COALESCE(platform_created_at, created_at) DESC')
            ->paginate((int) ($filters['per_page'] ?? 30));
        $eventIds = $events->getCollection()->modelKeys();

        if ($eventIds) {
            $replies = Message::query()
                ->whereIn('metadata->social_comment_event_id', $eventIds)
                ->whereIn('source', ['comment_public_reply', 'comment_private_reply'])
                ->orderBy('created_at')
                ->get()
                ->groupBy(fn (Message $message) => (string) data_get($message->metadata, 'social_comment_event_id'));

            $events->getCollection()->transform(function (SocialCommentEvent $event) use ($replies): SocialCommentEvent {
                $reply = $replies->get((string) $event->id, collect())->last();
                $event->setAttribute('reply', $reply ? [
                    'content' => $reply->content,
                    'source' => $reply->source,
                    'created_at' => $reply->created_at,
                ] : null);
                return $event;
            });
        }

        return response()->json([
            'data' => $events->items(),
            'current_page' => $events->currentPage(),
            'last_page' => $events->lastPage(),
            'per_page' => $events->perPage(),
            'total' => $events->total(),
            'stats' => [
                'total' => $total,
                'replied' => $replied,
                'needs_attention' => max(0, $total - $replied),
            ],
        ]);
    }
}
