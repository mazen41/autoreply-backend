<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use App\Services\EvolutionApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Google\Client as GoogleClient;
use Google\Service\Gmail;

class InboxController extends Controller
{
    /**
     * List all conversations for the authenticated user, newest first.
     */
    public function index(Request $request)
    {
        $conversations = Conversation::whereHas('channel', function ($q) {
                $q->where('user_id', auth()->id());
            })
            ->with([
    'channel:id,type,page_name',
    'latestMessage',
])
            ->orderBy('last_message_at', 'desc')
            ->paginate(50);

        return response()->json($conversations);
    }

    /**
     * Get all messages in a conversation (oldest first for chat display).
     */
    public function messages(Request $request, $conversationId)
    {
        $conversation = Conversation::whereHas('channel', function ($q) {
                $q->where('user_id', auth()->id());
            })
            ->with('channel:id,type,page_name')
            ->findOrFail($conversationId);

        $messages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'conversation' => $conversation,
            'messages'     => $messages,
        ]);
    }

    /**
     * Send a manual reply from the business owner.
     */
    public function reply(Request $request, $conversationId)
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $conversation = Conversation::whereHas('channel', function ($q) {
                $q->where('user_id', auth()->id());
            })
            ->findOrFail($conversationId);

        $channel = $conversation->channel;

        // Handle different channel types
        if ($channel->type === 'gmail') {
            return $this->sendGmailReply($request, $conversation, $channel);
        }

        if ($channel->type === 'whatsapp') {
            return $this->sendWhatsAppReply($request, $conversation, $channel);
        }

        // Handle Facebook/Instagram
        return $this->sendFacebookReply($request, $conversation, $channel);
    }

    /**
     * Send reply via Gmail API
     */
    private function sendGmailReply(Request $request, Conversation $conversation, Channel $channel)
    {
        try {
            $client = $this->getGmailClient($channel);
            if (!$client) {
                Log::error('Gmail client failed for reply', ['channel_id' => $channel->id]);
                return response()->json(['error' => 'Gmail authentication failed'], 500);
            }

            $gmail = new Gmail($client);

            // Create MIME message
            $rawMessage = $this->createMimeMessage(
                $conversation->sender_email ?? $conversation->sender_id,
                $channel->page_name,
                $conversation->subject ?? 'Re',
                $request->message
            );

            // Send the message
            $msg = new Gmail\Message();
            $msg->setRaw(base64_encode($rawMessage));
            $sentMessage = $gmail->users_messages->send('me', $msg);

            // Save to DB
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content'         => $request->message,
                'direction'       => 'outbound',
                'is_ai'           => false,
                'status'          => 'manual',
                'gmail_message_id' => $sentMessage->getId(),
            ]);

            $conversation->update(['last_message_at' => now()]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
            }

            return response()->json(['success' => true, 'message' => $message]);

        } catch (\Exception $e) {
            Log::error('Gmail reply failed', ['error' => $e->getMessage(), 'channel_id' => $channel->id]);
            return response()->json(['error' => 'Failed to send Gmail reply: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Send reply via Facebook Graph API
     */
    private function sendFacebookReply(Request $request, Conversation $conversation, Channel $channel)
    {
        $certPath = base_path('cacert.pem');
        
        // Decrypt the access token
        $accessToken = decrypt($channel->access_token);

        $fbResponse = Http::timeout(10)
            ->withOptions(['verify' => file_exists($certPath) ? $certPath : false])
            ->post(
                "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}",
                [
                    'recipient' => ['id' => $conversation->sender_id],
                    'message'   => ['text' => $request->message],
                ]
            );

        if (!$fbResponse->successful()) {
            Log::error('Manual reply failed', $fbResponse->json());
            return response()->json(['error' => 'Failed to send message via Facebook', 'details' => $fbResponse->json()], 500);
        }

        // Save to DB
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content'         => $request->message,
            'direction'       => 'outbound',
            'is_ai'           => false,
            'status'          => 'manual',
        ]);

        $conversation->update(['last_message_at' => now()]);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

    /**
     * Send reply via WhatsApp Evolution API
     */
    private function sendWhatsAppReply(Request $request, Conversation $conversation, Channel $channel)
    {
        try {
            $whatsappService = new EvolutionApiService();
            $instanceName = $channel->page_id; // We store instance_name in page_id for WhatsApp

            // Send message via Evolution API
            $response = $whatsappService->sendTextMessage(
                $instanceName,
                $conversation->sender_id,
                $request->message
            );

            if (!isset($response['key']['id'])) {
                Log::error('WhatsApp reply failed', ['response' => $response]);
                return response()->json(['error' => 'Failed to send WhatsApp message'], 500);
            }

            // Save to DB
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content'         => $request->message,
                'direction'       => 'outbound',
                'is_ai'           => false,
                'status'          => 'manual',
                'source'          => 'whatsapp',
                'send_status'     => 'sent',
            ]);

            $conversation->update(['last_message_at' => now()]);

            // Also save to WhatsApp messages table for legacy compatibility
            \App\Models\WhatsAppMessage::create([
                'whatsapp_instance_id' => \App\Models\WhatsAppInstance::where('instance_name', $instanceName)->first()?->id,
                'user_id' => $channel->user_id,
                'message_id' => $response['key']['id'] ?? null,
                'remote_message_id' => $response['key']['id'] ?? null,
                'direction' => 'outgoing',
                'from_phone' => null, // Business number
                'from_name' => null,
                'to_phone' => $conversation->sender_id,
                'body' => $request->message,
                'message_type' => 'text',
                'media' => null,
                'metadata' => ['evolution_response' => $response],
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
            }

            return response()->json(['success' => true, 'message' => $message]);

        } catch (\Exception $e) {
            Log::error('WhatsApp reply failed', ['error' => $e->getMessage(), 'channel_id' => $channel->id]);
            return response()->json(['error' => 'Failed to send WhatsApp reply: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get authenticated Gmail client
     */
    private function getGmailClient(Channel $channel): ?GoogleClient
    {
        try {
            $tokenData = json_decode(decrypt($channel->getRawOriginal('access_token')), true);
            $client = new GoogleClient();
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
            $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
            $client->setAccessToken($tokenData);

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $channel->refresh_token ?? ($tokenData['refresh_token'] ?? null);
                if (!$refreshToken) {
                    Log::error('Gmail token expired and no refresh token', ['channel_id' => $channel->id]);
                    return null;
                }
                try {
                    $refreshToken = decrypt($refreshToken);
                } catch (\Exception $e) {
                    // Already plain string from tokenData
                }
                $client->fetchAccessTokenWithRefreshToken($refreshToken);
                $newToken = $client->getAccessToken();

                Channel::where('id', $channel->id)->update([
                    'access_token' => encrypt(json_encode($newToken)),
                    'updated_at'   => now(),
                ]);
            }

            return $client;
        } catch (\Exception $e) {
            Log::error('Gmail client error', ['error' => $e->getMessage(), 'channel_id' => $channel->id]);
            return null;
        }
    }

    /**
     * Create MIME message for Gmail
     */
    private function createMimeMessage($to, $from, $subject, $body): string
    {
        $boundary = uniqid(rand(), true);
        $headers = [
            "From: {$from}",
            "To: {$to}",
            "Subject: {$subject}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary={$boundary}",
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message .= $body . "\r\n";
        $message .= "--{$boundary}--\r\n";
        return $message;
    }

    /**
     * Toggle AI for a specific conversation
     */
    public function toggleAi(Request $request, $conversationId)
    {
        $conversation = Conversation::whereHas('channel', function ($q) {
                $q->where('user_id', auth()->id());
            })
            ->findOrFail($conversationId);

        $conversation->update([
            'ai_enabled' => !$conversation->ai_enabled
        ]);

        return response()->json([
            'message' => 'AI toggled successfully',
            'ai_enabled' => $conversation->ai_enabled
        ]);
    }
}
