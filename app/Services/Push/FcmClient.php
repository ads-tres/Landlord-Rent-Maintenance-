<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Firebase Cloud Messaging HTTP v1 client. Signs a service-account JWT with
 * openssl (no Google SDK needed) and caches the access token for its lifetime.
 */
class FcmClient
{
    public function enabled(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @param  array<string, string>  $data
     * @return 'sent'|'unregistered'|'failed'
     */
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        $creds = $this->credentials() ?? throw new RuntimeException('FCM is not configured.');

        $response = Http::withToken($this->accessToken($creds))
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$creds['project_id']}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'alerts']],
                ],
            ]);

        if ($response->successful()) {
            return 'sent';
        }
        $status = $response->json('error.status');
        $code = $response->json('error.details.0.errorCode');

        return in_array($status, ['NOT_FOUND', 'UNREGISTERED'], true) || $code === 'UNREGISTERED' || $response->status() === 404
            ? 'unregistered'
            : 'failed';
    }

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    private function credentials(): ?array
    {
        $path = config('push.credentials');
        if (! $path || ! is_readable($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return isset($json['project_id'], $json['client_email'], $json['private_key']) ? $json : null;
    }

    private function accessToken(array $creds): string
    {
        return Cache::remember('fcm:token:'.md5($creds['client_email']), now()->addMinutes(50), function () use ($creds) {
            $now = time();
            $segments = [
                $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->b64(json_encode([
                    'iss' => $creds['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            openssl_sign(implode('.', $segments), $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)
                || throw new RuntimeException('Could not sign the FCM token request.');
            $segments[] = $this->b64($signature);

            return Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
