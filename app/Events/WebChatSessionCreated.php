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

class WebChatSessionCreated implements ShouldBroadcast
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
            'visitor_name' => $this->session->visitor_name,
            'page_url' => $this->session->page_url,
            'created_at' => $this->session->created_at->toISOString(),
        ];
    }
}
