<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(string $token, ?string $remoteIp = null): array
    {
        $secret = trim((string) config('services.turnstile.secret_key'));
        $token = trim($token);

        if ($secret === '') {
            Log::error('TURNSTILE_SECRET_MISSING');

            return [
                'success' => false,
                'error-codes' => ['missing-secret'],
            ];
        }

        if ($token === '') {
            return [
                'success' => false,
                'error-codes' => ['missing-response'],
            ];
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ], static fn ($value) => $value !== null && $value !== ''));

            if (! $response->successful()) {
                Log::warning('TURNSTILE_SITEVERIFY_HTTP_FAIL', [
                    'status' => $response->status(),
                    'remote_ip' => $remoteIp,
                ]);

                return [
                    'success' => false,
                    'error-codes' => ['http-error'],
                ];
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                Log::warning('TURNSTILE_SITEVERIFY_INVALID_RESPONSE', [
                    'remote_ip' => $remoteIp,
                ]);

                return [
                    'success' => false,
                    'error-codes' => ['invalid-response'],
                ];
            }

            return $payload;
        } catch (\Throwable $e) {
            Log::warning('TURNSTILE_SITEVERIFY_EXCEPTION', [
                'type' => class_basename($e),
                'remote_ip' => $remoteIp,
            ]);

            return [
                'success' => false,
                'error-codes' => ['request-failed'],
            ];
        }
    }
}
