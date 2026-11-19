<?php

namespace App\Services;

use App\Exceptions\SmsFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Hospify's SMS provider. NHC only texts to confirm a phone number. */
class Termii
{
    public function send(string $localPhone, string $text): void
    {
        $config = config('services.termii');

        if (blank($config['api_key']) || blank($config['sender_id'])) {
            $this->fail('Termii is not configured: TERMII_API_KEY or TERMII_SENDER_ID is empty.');
        }

        try {
            $response = Http::timeout(10)->retry(2, 500, throw: false)->post($this->url('/api/sms/send'), [
                'to' => '234'.$localPhone,
                'from' => $config['sender_id'],
                'sms' => $text,
                'type' => 'plain',
                'channel' => $config['channel'],
                'api_key' => $config['api_key'],
            ]);
        } catch (ConnectionException $e) {
            $this->fail('Termii could not be reached: '.$e->getMessage());
        }

        if (! $response->successful()) {
            // Termii's own reason ("Insufficient balance", "ApplicationSenderId not found"...).
            // Never the message we sent: it holds the code.
            $reason = $response->json('message') ?? $response->json('error') ?? mb_substr($response->body(), 0, 200);
            $this->fail("Termii refused the SMS ({$response->status()}): ".(is_string($reason) ? $reason : json_encode($reason)));
        }
    }

    /** Termii's balance, for `php artisan nhc:check`. */
    public function balance(): array
    {
        $response = Http::timeout(10)->get($this->url('/api/get-balance'), ['api_key' => config('services.termii.api_key')]);

        return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $response->json() ?? $response->body()];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.termii.base_url'), '/').$path;
    }

    /**
     * The person sees "We couldn't send the text message"; the reason goes to
     * the logs and LoggerAI. (A 503 alone is never reported: Laravel skips HTTP errors.)
     */
    private function fail(string $reason): never
    {
        report(new RuntimeException($reason));

        throw new SmsFailed;
    }
}
