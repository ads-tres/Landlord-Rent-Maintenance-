<?php

return [
    // From the Chapa dashboard. Without a secret key, online rent is off everywhere.
    'secret_key' => env('CHAPA_SECRET_KEY'),
    // The webhook secret set in Chapa's dashboard; Chapa signs each webhook with it (HMAC-SHA256).
    'webhook_secret' => env('CHAPA_WEBHOOK_SECRET'),
    'base_url' => env('CHAPA_BASE_URL', 'https://api.chapa.co/v1'),
    // Chapa's docs and packages disagree on this path: confirm it in the sandbox.
    'subaccount_path' => env('CHAPA_SUBACCOUNT_PATH', '/subaccount'),
    // Our fee per online payment, taken through the split before the rest reaches the landlord.
    'platform_fee_santim' => (int) env('CHAPA_PLATFORM_FEE_SANTIM', 2500),
    'currency' => 'ETB',
];
