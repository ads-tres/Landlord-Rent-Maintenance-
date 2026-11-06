<?php

/*
| Firebase Cloud Messaging (HTTP v1). Push stays off until a service account is set:
| FCM_CREDENTIALS = path to the service account JSON from the Firebase console
| (Project settings → Service accounts → Generate new private key).
*/
return [
    'credentials' => env('FCM_CREDENTIALS'),
];
