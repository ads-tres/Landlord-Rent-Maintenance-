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

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
    ],
];
