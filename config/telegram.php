<?php

return [
    // Create a bot with @BotFather, then set these. Empty token = Telegram off (SMS only).
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'username' => env('TELEGRAM_BOT_USERNAME'), // without @
    // Random string; Telegram sends it back in a header on every webhook call.
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
];
