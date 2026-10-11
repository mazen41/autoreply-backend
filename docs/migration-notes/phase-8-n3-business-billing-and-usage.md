# Phase 8 N3 — Business Billing and Usage

## What changed

- Subscriptions and payment intents now carry `business_id`; the migration backfills legacy records from each user's primary business.
- Business plan resolution is selected-business scoped. Team members can read billing and usage only for businesses they belong to; only `manage_billing` members can change plans or initiate checkout.
- Paymob and MyFatoorah payment intents retain the authorized business through provider confirmation. A verified paid callback activates a subscription for that business and cancels only its previous active subscription.
- Paid plans cannot be activated by the direct upgrade endpoint. Paid changes require checkout and a verified provider callback.
- The usage endpoint reports monthly AI replies, outbound messages, and connected channels from the same `UsageMeter` used by send and connection gates. Product images are stored as outbound image messages and count toward outbound-message limits.
- Plan administration accepts `outbound_messages_limit`; the value `-1` means unlimited. Existing plans are migrated with `-1` so deployment does not silently reduce current limits.
- Legacy Evolution WhatsApp instances now store their selected `business_id`, with existing instances backfilled from the owner's primary business. Direct sends create normal outbound inbox records and use the same meter; failed-message retries recheck the meter before contacting providers.
- Billing and checkout screens send `X-Business-Id` and show the selected business's plan and usage.
- The scheduled usage check now uses the same meter as runtime enforcement. The invalid `billing:check-usage-limits` command schedule was removed; `usage:check` remains scheduled daily.
- If no `Free` package exists, legacy channel connection requests are treated as unlimited rather than rejected before provider handling.

## Deploy

Run the normal database migration command before deploying worker/web processes:

```bash
php artisan migrate --force
```

Configure each package's `outbound_messages_limit` in admin. Existing packages remain unlimited until explicitly configured.

## Usage definition

- AI replies: successful/queued outbound inbox messages marked `is_ai`, excluding failed and blocked sends.
- Outbound messages: successful/queued outbound inbox messages, accepted email campaign recipients, and sent product-image messages, excluding failed and blocked sends.
- Channels: channel account records owned by the selected business, including disconnected accounts that remain stored.
- Period: calendar month in the application timezone.

## Operational limitation

The send gate reads the current usage ledger immediately before provider submission; the implementation does not reserve quota in a separate atomic ledger. Simultaneous sends near a hard limit can race. Provider acceptance followed by a database outage can also delay usage recording. Monitor usage and delivery records around configured limits.
