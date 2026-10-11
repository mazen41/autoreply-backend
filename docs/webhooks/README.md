# Referenced message fixtures

These fixtures contain no customer data and use placeholders only. Meta and Telegram examples are synthetic shapes based on provider webhook fields; Evolution uses the adapter's existing `data.contextInfo.stanzaId` contract. Gmail and Web chat entries document this application's normalization contract.

They are **not captured production payloads**. Production conversation 11 retains message IDs and product maps but the current privacy-safe webhook pipeline does not retain raw request bodies or reply-reference metadata, so whether message 297 used the platform Reply action cannot be reconstructed from that conversation. Confirm live field values with a new provider test after deployment; do not enable full-body webhook logging.

The expected reference fields are:

| Channel | Incoming reference |
| --- | --- |
| Facebook Messenger | `message.reply_to.mid` |
| Instagram DM | `message.reply_to.mid` |
| WhatsApp Cloud | `messages[].context.id` |
| WhatsApp Evolution | `data.contextInfo.stanzaId` (nested locations remain supported by the adapter) |
| Telegram | `message.reply_to_message.message_id`, normalized with chat ID |
| Gmail | `In-Reply-To` plus Gmail thread ID; resolve latest in-thread product map when exact provider ID is unavailable |
| Web chat | `referenced_message_id` or `referenced_platform_message_id`, validated in the same conversation |
