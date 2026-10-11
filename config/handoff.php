<?php

return [
    'email_notifications' => (bool) env('HANDOFF_EMAIL_NOTIFICATIONS', true),
    'sla_minutes' => max(1, (int) env('HANDOFF_SLA_MINUTES', 30)),
];
