<?php

return [
    // Attachments are not read unless this is switched on. While it is off the bot does not
    // download files, does not transcribe voice notes, does not analyse images, and does not
    // take coordinates from location or template attachments. It works from typed text only.
    'read_enabled' => (bool) env('ATTACHMENTS_READ_ENABLED', false),
];