# Phase 5 A6 — Private media ingestion and retention

## Implemented in this tranche

- Evolution WhatsApp and WhatsApp Cloud inbound media are stored under a non-public `private_media` disk before AI processing. Cloud downloads accept only HTTPS URLs on Meta media hosts and are size-limited.
- Media path resolution checks the storage root and rejects external URLs, traversal, and paths outside the permitted inbound/legacy WhatsApp directories.
- Voice transcription no longer forces Arabic; Whisper can detect the language.
- Messages record media processing status and reason. Analysis failures are visible in the inbox and added to AI context as a failure marker.
- Existing public Evolution media remains readable through an allowlisted legacy path.
- Telegram photos, voice/audio, video, and documents are fetched through Telegram `getFile` and stored on the private disk; failed downloads remain visible as failed media messages.
- Instagram and Facebook image/audio/video/file attachments are downloaded only from HTTPS Meta CDN hosts, size-limited, and stored privately.
- Generated TTS audio is private and served through a seven-day expiring signed URL.
- A daily retention command deletes private inbound media older than `INCOMING_MEDIA_RETENTION_DAYS` (default 30) and marks the corresponding message as expired.
- The multimodal settings page records a tenant-scoped customer-processing disclosure acknowledgment.

## Migration

Run `php artisan migrate`; migrations `2026_10_10_000019_add_media_processing_status_to_messages` and `2026_10_10_000020_add_media_processing_disclosure_to_business_profiles` add the required fields. New inbound media defaults to `INCOMING_MEDIA_DISK=private_media`; outgoing media continues to use `EVOLUTION_MEDIA_DISK`. Set `INCOMING_MEDIA_RETENTION_DAYS` to change the retention period (minimum one day).

The scheduler must be running for `media:expire-inbound` to execute daily. Signed TTS URLs expire after seven days.

## Verification

- `php artisan test --filter='MediaProcessingDisclosureTest|VoiceServiceTest|IncomingMediaStorageTest|TelegramWebhookRoutingTest|CustomerResolutionTest|WhatsAppCloudApiTest'`: 33 passed, 126 assertions.
- `npx tsc --noEmit`: passed.

## Operational note

Existing files already stored on a public disk are not moved automatically. New inbound and generated media use private storage.

## Rollback

Rollback migration `2026_10_10_000019_add_media_processing_status_to_messages` only if it is the latest migration. To revert storage behavior, restore `EvolutionApiService` to the prior media disk and remove the new private Cloud ingestion path. Do not delete private media during rollback.
