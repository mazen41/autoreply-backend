<?php

namespace App\Events;

use App\Models\WebChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebChatStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $session;

    public function __construct(WebChatSession $session)
    {
        $this->session = $session;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('business.' . $this->session->business_id);
    }

    public function broadcastWith()
    {
        return [
            'session_id' => $this->session->id,
            'session_key' => $this->session->session_id,
            'is_online' => $this->session->is_online,
            'last_activity_at' => $this->session->last_activity_at->toISOString(),
        ];
    }
}
