<?php

return [
    // Detailed per-message audit lines (created and status changes).
    'enabled' => env('MESSAGE_AUDIT_ENABLED', true),

    // Characters of message text written to logs. Set 0 to log metadata only.
    // Emails and phone numbers are redacted before anything is written.
    'preview_chars' => (int) env('MESSAGE_AUDIT_PREVIEW_CHARS', 300),
];