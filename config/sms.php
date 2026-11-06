<?php

return [
    // "log" writes messages to the log; "afromessage" sends real SMS.
    'driver' => env('SMS_DRIVER', 'log'),

    'afromessage' => [
        'token' => env('AFROMESSAGE_TOKEN'),
        'base_url' => env('AFROMESSAGE_BASE_URL', 'https://api.afromessage.com/api'),
        'identifier_id' => env('AFROMESSAGE_IDENTIFIER_ID'),
        'sender' => env('AFROMESSAGE_SENDER'),
    ],

    // Price per SMS segment in santim (from your AfroMessage quote), used to show the cost
    // of an announcement before sending. Leave empty to show only the number of SMS.
    'price_per_segment_santim' => env('SMS_PRICE_PER_SEGMENT_SANTIM'),

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
    ],
];
