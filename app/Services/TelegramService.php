<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /**
     * Send a message via Telegram bot
     */
    public function sendMessage(string $botToken, string $chatId, string $text, ?string &$providerMessageId = null): bool
    {
        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);

            if ($response->successful()) {
                $providerMessageId = isset($response->json()['result']['message_id'])
                    ? (string) $response->json()['result']['message_id']
                    : null;
                Log::info('Telegram message sent', ['provider_message_id_present' => $providerMessageId !== null]);
                return true;
            } else {
                Log::error('Failed to send Telegram message', [
                    'status' => $response->status(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Telegram send message error', [
                'exception_type' => get_class($e),
            ]);
            return false;
        }
    }

    /**
     * Set webhook for Telegram bot
     */
    public function setWebhook(string $botToken, string $webhookUrl): bool
    {
        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/setWebhook", [
                'url' => $webhookUrl,
            ]);

            if ($response->successful()) {
                Log::info('Telegram webhook set');
                return true;
            } else {
                Log::error('Failed to set Telegram webhook', [
                    'status' => $response->status(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Telegram set webhook error', [
                'exception_type' => get_class($e),
            ]);
            return false;
        }
    }

    /**
     * Delete webhook for Telegram bot
     */
    public function deleteWebhook(string $botToken): bool
    {
        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/deleteWebhook");

            if ($response->successful()) {
                Log::info('Telegram webhook deleted');
                return true;
            } else {
                Log::error('Failed to delete Telegram webhook', [
                    'status' => $response->status(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Telegram delete webhook error', [
                'exception_type' => get_class($e),
            ]);
            return false;
        }
    }

    /**
     * Get bot information
     */
    public function getMe(string $botToken): ?array
    {
        try {
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$botToken}/getMe");

            if ($response->successful()) {
                return $response->json()['result'] ?? null;
            } else {
                Log::error('Failed to get Telegram bot info', [
                    'status' => $response->status(),
                    'status' => $response->status(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Telegram getMe error', [
                'exception_type' => get_class($e),
            ]);
            return null;
        }
    }
}
