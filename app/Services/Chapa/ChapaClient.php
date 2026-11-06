<?php

namespace App\Services\Chapa;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chapa API (https://developer.chapa.co). Amounts go to Chapa in birr; we keep santim internally.
 * Only Laravel holds the secret key; the app and tenant pages never talk to Chapa directly.
 */
class ChapaClient
{
    public function enabled(): bool
    {
        return filled(config('chapa.secret_key'));
    }

    /** @return list<array{id: string, name: string}> */
    public function banks(): array
    {
        return collect($this->call('get', '/banks')['data'] ?? [])
            ->map(fn ($b) => ['id' => (string) ($b['id'] ?? ''), 'name' => (string) ($b['name'] ?? '')])
            ->filter(fn ($b) => $b['id'] !== '')
            ->values()
            ->all();
    }

    /** Creates the landlord's sub-account; our flat fee is split off each payment. Returns its id. */
    public function createSubaccount(string $businessName, string $accountName, string $bankCode, string $accountNumber): string
    {
        $response = $this->call('post', config('chapa.subaccount_path'), [
            'business_name' => $businessName,
            'account_name' => $accountName,
            'bank_code' => $bankCode,
            'account_number' => $accountNumber,
            'split_type' => 'flat',
            'split_value' => config('chapa.platform_fee_santim') / 100,
        ]);

        $id = $response['data']['subaccount_id'] ?? $response['data']['id'] ?? null;
        if (! $id) {
            throw new RuntimeException('Chapa returned no sub-account id');
        }

        return (string) $id;
    }

    /** @return string checkout URL to send the tenant to */
    public function initialize(array $params): string
    {
        $url = $this->call('post', '/transaction/initialize', $params)['data']['checkout_url'] ?? null;
        if (! $url) {
            throw new RuntimeException('Chapa returned no checkout_url');
        }

        return $url;
    }

    /** @return array<string, mixed> Chapa's view of the transaction (status, amount, currency, reference) */
    public function verify(string $txRef): array
    {
        return $this->call('get', '/transaction/verify/'.rawurlencode($txRef))['data'] ?? [];
    }

    /** Webhooks carry an HMAC-SHA256 of the raw body, keyed with the webhook secret. */
    public function validSignature(string $body, ?string $signature): bool
    {
        $secret = (string) config('chapa.webhook_secret');

        return $secret !== '' && $signature !== null
            && hash_equals(hash_hmac('sha256', $body, $secret), strtolower(trim($signature)));
    }

    private function call(string $method, string $path, array $data = []): array
    {
        $request = Http::withToken((string) config('chapa.secret_key'))->acceptJson()->timeout(20);
        $url = rtrim(config('chapa.base_url'), '/').'/'.ltrim($path, '/');
        $response = $method === 'get' ? $request->get($url) : $request->asJson()->post($url, $data);

        if ($response->failed()) {
            throw new RuntimeException("Chapa {$path} failed ({$response->status()}): ".mb_substr($response->body(), 0, 500));
        }

        return $response->json() ?? [];
    }
}
