<?php

namespace App\Services;

/** Normalize reply references from supported providers without retaining raw events. */
class ReferencedMessageNormalizer
{
    public function meta(array $message): ?string
    {
        $id = $message['reply_to']['mid'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function whatsappCloud(array $message): ?string
    {
        $id = $message['context']['id'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function evolution(array $data, array $messageContent = []): ?string
    {
        $id = $data['contextInfo']['stanzaId']
            ?? $messageContent['contextInfo']['stanzaId']
            ?? null;
        if ($id === null) {
            foreach ([
                'extendedTextMessage', 'imageMessage', 'videoMessage', 'audioMessage',
                'documentMessage', 'stickerMessage', 'conversation',
            ] as $key) {
                $id = $messageContent[$key]['contextInfo']['stanzaId'] ?? null;
                if ($id !== null) {
                    break;
                }
            }
        }
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function telegram(array $message, string|int $chatId): ?string
    {
        $id = $message['reply_to_message']['message_id'] ?? null;
        return $id !== null ? (string) $chatId . ':' . (string) $id : null;
    }

    public function gmail(?string $inReplyTo): ?string
    {
        if (!is_string($inReplyTo) || trim($inReplyTo) === '') {
            return null;
        }
        return trim($inReplyTo, " <>\t\r\n");
    }
}
