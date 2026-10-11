# Phase 8 N8–N10 — Regional defaults, verified onboarding, and notices

## N8: Regional defaults

Business onboarding now saves a country-specific currency and IANA timezone, with editable currency/timezone fields. Egypt defaults to EGP and `Africa/Cairo`; Saudi Arabia defaults to SAR and `Asia/Riyadh`. Additional MENA mappings are included, and other countries start at USD/UTC until the merchant chooses values. The AI business context includes country/currency when provided. Arabic STOP variants are covered by the consent keyword handler. If checkout cannot determine the delivery country, it asks for country and city; it never asks for map coordinates.

## N9: Verified onboarding

Progress updates accept only real wizard steps and check persisted business/channel state. A client cannot mark AI/test/complete steps as done unless the current AI setup checklist passes. Skip disables AI on the business channels, marks the wizard dismissed, and keeps the checklist incomplete. Finishing the profile wizard without an active verified agent similarly leaves `completed=false` and sends the merchant to channel/setup controls.

## N10: Privacy and consent notices

The Privacy Policy now lists the actual configurable AI, channel, commerce, payment, email, geocoding, and real-time providers and explains which data may be sent. It states default message/media retention and links to configurable retention controls. Terms now require prior marketing opt-in, honoring STOP/unsubscribe, and merchant notices for third-party processing. The multimodal disclosure links to both the policy and retention settings.

## Schema/API

- Migration `2026_10_10_000030_add_currency_to_business_profiles.php` adds nullable `currency`; existing rows remain valid and can be updated through onboarding/profile settings.
- No production data or services were changed.
