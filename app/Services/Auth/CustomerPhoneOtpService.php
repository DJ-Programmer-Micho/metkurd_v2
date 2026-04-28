<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CustomerPhoneOtpService
{
    /**
     * @return array<string, int|bool>
     */
    public function state(Customer $customer): array
    {
        $now = now()->timestamp;
        $expiresAt = (int) Cache::get($this->expiresKey($customer), 0);
        $cooldownAt = (int) Cache::get($this->cooldownKey($customer), 0);
        $lockAt = (int) Cache::get($this->lockKey($customer), 0);
        $attempts = (int) Cache::get($this->attemptsKey($customer), 0);

        $maxAttempts = $this->maxAttempts();
        $expiresRemaining = $expiresAt > 0 ? max(0, $expiresAt - $now) : 0;
        $cooldownRemaining = $cooldownAt > 0 ? max(0, $cooldownAt - $now) : 0;
        $lockRemaining = $lockAt > 0 ? max(0, $lockAt - $now) : 0;

        return [
            'expires_remaining' => $expiresRemaining,
            'cooldown_remaining' => $cooldownRemaining,
            'lock_remaining' => $lockRemaining,
            'attempts_left' => max(0, $maxAttempts - $attempts),
            'is_locked' => $lockRemaining > 0,
            'is_expired' => $expiresRemaining <= 0,
        ];
    }

    public function resolvePhone(Customer $customer): string
    {
        $profilePhone = trim((string) optional($customer->profile)->phone_number);

        if ($profilePhone === '') {
            $profilePhone = trim((string) CustomerProfile::query()
                ->where('customer_id', (int) $customer->id)
                ->value('phone_number'));
        }

        return $this->normalizePhone($profilePhone);
    }

    public function hasValidPhone(Customer $customer): bool
    {
        return preg_match('/^\+\d{10,15}$/', $this->resolvePhone($customer)) === 1;
    }

    public function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits !== '' ? '+' . $digits : '';
    }

    public function normalizeOtpCode(?string $value): string
    {
        return substr(preg_replace('/\D+/', '', (string) $value), 0, 6);
    }

    public function clearOtpState(Customer $customer): void
    {
        Cache::forget($this->expiresKey($customer));
        Cache::forget($this->attemptsKey($customer));
        Cache::forget($this->lockKey($customer));
        Cache::forget($this->cooldownKey($customer));
    }

    public function issueCode(Customer $customer, string $channel): void
    {
        $otp = (string) random_int(100000, 999999);

        $customer->forceFill([
            'phone_otp_number' => $otp,
        ])->save();

        $ttlSeconds = $this->otpTtlSeconds();
        $lockSeconds = $this->lockSeconds();
        $cooldownSeconds = $this->cooldownSeconds();

        Cache::put($this->expiresKey($customer), now()->addSeconds($ttlSeconds)->timestamp, $ttlSeconds + 60);
        Cache::put($this->attemptsKey($customer), 0, $ttlSeconds + $lockSeconds + 600);
        Cache::put($this->cooldownKey($customer), now()->addSeconds($cooldownSeconds)->timestamp, $cooldownSeconds + 60);
        Cache::forget($this->lockKey($customer));

        $recipient = ltrim($this->resolvePhone($customer), '+');
        $this->sendStandingTechOtp($channel, $recipient, $otp);
    }

    public function markWrongAttempt(Customer $customer): bool
    {
        $ttl = $this->otpTtlSeconds() + $this->lockSeconds() + 600;
        $attempts = (int) Cache::get($this->attemptsKey($customer), 0) + 1;
        Cache::put($this->attemptsKey($customer), $attempts, $ttl);

        if ($attempts >= $this->maxAttempts()) {
            Cache::put(
                $this->lockKey($customer),
                now()->addSeconds($this->lockSeconds())->timestamp,
                $this->lockSeconds() + 60
            );

            return true;
        }

        return false;
    }

    public function markVerified(Customer $customer): void
    {
        $customer->forceFill([
            'phone_verify' => true,
            'phone_verified_at' => now(),
            'phone_otp_number' => null,
        ])->save();

        $this->clearOtpState($customer);
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('mobile_api.phone_otp.max_attempts', 5));
    }

    public function otpTtlSeconds(): int
    {
        return max(30, (int) config('mobile_api.phone_otp.ttl_seconds', 300));
    }

    public function lockSeconds(): int
    {
        return max(60, (int) config('mobile_api.phone_otp.lock_seconds', 600));
    }

    public function cooldownSeconds(): int
    {
        return max(0, (int) config('mobile_api.phone_otp.cooldown_seconds', 60));
    }

    protected function sendStandingTechOtp(string $type, string $recipient, string $otp): array
    {
        $base = config('services.standingtech.base', env('STANDINGTECH_BASE_URL'));
        $token = config('services.standingtech.token', env('STANDINGTECH_TOKEN'));
        $sender = config('services.standingtech.sender', env('STANDINGTECH_SENDER_ID'));

        if (! $base || ! $token || ! $sender) {
            throw new \RuntimeException(__('StandingTech config is missing (base/token/sender).'));
        }

        $payload = [
            'recipient' => $recipient,
            'sender_id' => $sender,
            'type' => $type,
            'message' => (string) $otp,
            'lang' => 'en',
        ];

        if ($type !== 'sms') {
            $payload['fallback'] = 'sms';
        }

        $response = Http::baseUrl((string) $base)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->retry(2, 200)
            ->withToken((string) $token)
            ->post('/api/v4/sms/send', $payload);

        $response->throw();

        return (array) $response->json();
    }

    protected function expiresKey(Customer $customer): string
    {
        return 'phone_otp_expires_' . (int) $customer->id;
    }

    protected function attemptsKey(Customer $customer): string
    {
        return 'phone_otp_attempts_' . (int) $customer->id;
    }

    protected function lockKey(Customer $customer): string
    {
        return 'phone_otp_lock_' . (int) $customer->id;
    }

    protected function cooldownKey(Customer $customer): string
    {
        return 'phone_otp_cooldown_' . (int) $customer->id;
    }
}
