<?php

return [
    'opt_out_keywords' => [
        'stop', 'unsubscribe', 'إيقاف', 'ايقاف', 'أوقف', 'اوقف', 'الغاء', 'إلغاء',
        'إلغاء الاشتراك', 'الغاء الاشتراك', 'وقف', 'توقف',
    ],
    'opt_in_keywords' => [
        'start', 'ابدأ', 'ابدا', 'استأنف', 'استأنف الاشتراك', 'إعادة الاشتراك', 'اعادة الاشتراك',
    ],
    'auto_opt_in_active_conversations' => filter_var(env('AUTO_CONSENT_ACTIVE_CONVERSATIONS', true), FILTER_VALIDATE_BOOLEAN),
];
