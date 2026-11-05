<?php

namespace App\Services\Sms;

/**
 * Sends SMS to Ethiopian phone numbers. Only the backend talks to the SMS
 * gateway, so the API token never ships inside the app.
 */
interface SmsSender
{
    /**
     * @param  string  $phone  E.164 phone number, e.g. +251911234567
     */
    public function send(string $phone, string $message): void;
}
