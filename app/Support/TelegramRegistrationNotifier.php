<?php

namespace App\Support;

use App\Models\Customer;
use App\Notifications\Landing\TelegramNewRegister;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class TelegramRegistrationNotifier
{
    private const STATE_UNVERIFIED = 'unverified';

    private const STATE_VERIFIED = 'verified';

    public static function sendUnverifiedIfNeeded(Customer $customer, ?string $registrationMethod = null): bool
    {
        return self::sendIfNeeded(
            customer: $customer,
            state: self::STATE_UNVERIFIED,
            timestampColumn: 'telegram_unverified_register_sent_at',
            registrationMethod: $registrationMethod
        );
    }

    public static function sendVerifiedIfNeeded(Customer $customer, ?string $registrationMethod = null): bool
    {
        if (! $customer->hasCompletedVerification()) {
            return false;
        }

        return self::sendIfNeeded(
            customer: $customer,
            state: self::STATE_VERIFIED,
            timestampColumn: 'telegram_verified_register_sent_at',
            registrationMethod: $registrationMethod
        );
    }

    private static function sendIfNeeded(
        Customer $customer,
        string $state,
        string $timestampColumn,
        ?string $registrationMethod = null
    ): bool {
        $teleId = trim((string) config('services.telegram-bot-api.groups.registration', ''));

        if ($teleId === '') {
            return false;
        }

        try {
            return (bool) DB::transaction(function () use ($customer, $state, $timestampColumn, $registrationMethod, $teleId) {
                /** @var Customer|null $locked */
                $locked = Customer::query()
                    ->with('profile')
                    ->lockForUpdate()
                    ->find($customer->id);

                if (! $locked) {
                    return false;
                }

                if (! is_null($locked->getAttribute($timestampColumn))) {
                    return false;
                }

                if ($state === self::STATE_VERIFIED && ! $locked->hasCompletedVerification()) {
                    return false;
                }

                $payload = self::buildPayload($locked, $state, $registrationMethod);

                Notification::route('telegram', $teleId)->notify(
                    new TelegramNewRegister($payload, $teleId)
                );

                $locked->forceFill([
                    $timestampColumn => now(),
                ])->save();

                return true;
            }, 3);
        } catch (\Throwable $e) {
            Log::warning('Registration telegram notification failed.', [
                'customer_id' => $customer->id,
                'state' => $state,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function buildPayload(Customer $customer, string $state, ?string $registrationMethod = null): array
    {
        $customer->loadMissing('profile');
        $profile = $customer->profile;

        $method = self::normalizeRegistrationMethod($registrationMethod, $customer);
        $createdAt = self::formatTimestamp($customer->created_at);
        $verifiedAt = $customer->hasCompletedVerification()
            ? self::formatTimestamp(self::resolveVerifiedAt($customer))
            : '';

        $name = trim(implode(' ', array_filter([
            trim((string) ($profile?->first_name ?? '')),
            trim((string) ($profile?->last_name ?? '')),
        ])));

        if ($name === '') {
            $name = trim((string) $customer->username);
        }

        $ipAddress = self::resolveClientIp();
        $userAgent = trim((string) request()->userAgent());

        return [
            'title' => $state === self::STATE_VERIFIED ? 'New Register - Verified' : 'New Register - Unverified',
            'user_id' => (string) $customer->id,
            'name' => $name !== '' ? $name : 'N/A',
            'username' => trim((string) $customer->username) ?: 'N/A',
            'email' => trim((string) $customer->email) ?: 'N/A',
            'phone' => trim((string) ($profile?->phone_number ?? '')) ?: 'N/A',
            'registration_method' => self::registrationMethodLabel($method),
            'auth_provider' => self::authProviderLabel($method),
            'email_verification_status' => $customer->email_verify ? 'Verified' : 'Unverified',
            'phone_verification_status' => $customer->phone_verify ? 'Verified' : 'Unverified',
            'ip_address' => $ipAddress ?: 'N/A',
            'user_agent' => $userAgent !== '' ? $userAgent : 'N/A',
            'app_environment' => (string) app()->environment(),
            'created_at' => $createdAt ?: 'N/A',
            'verified_at' => $verifiedAt ?: 'N/A',
        ];
    }

    private static function resolveClientIp(): ?string
    {
        $ip = trim((string) request()->ip());

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return null;
    }

    private static function normalizeRegistrationMethod(?string $registrationMethod, Customer $customer): string
    {
        $method = Str::of((string) $registrationMethod)->trim()->lower()->replace([' ', '-'], '_')->value();

        if ($method === '') {
            if (! empty($customer->g_id)) {
                return 'google';
            }

            if (! empty($customer->h_id)) {
                return 'github';
            }

            return 'normal_form';
        }

        return match ($method) {
            'normal', 'normal_form', 'form', 'signup' => 'normal_form',
            default => $method,
        };
    }

    private static function registrationMethodLabel(string $method): string
    {
        return match ($method) {
            'normal_form' => 'normal form',
            'google' => 'Google',
            'github' => 'GitHub',
            default => Str::headline($method),
        };
    }

    private static function authProviderLabel(string $method): string
    {
        return match ($method) {
            'normal_form' => 'normal form',
            'google' => 'Google',
            'github' => 'GitHub',
            default => Str::headline($method),
        };
    }

    private static function resolveVerifiedAt(Customer $customer): ?Carbon
    {
        $emailAt = $customer->email_verified_at instanceof Carbon ? $customer->email_verified_at : null;
        $phoneAt = $customer->phone_verified_at instanceof Carbon ? $customer->phone_verified_at : null;

        if ($emailAt && $phoneAt) {
            return $emailAt->greaterThan($phoneAt) ? $emailAt : $phoneAt;
        }

        return $phoneAt ?: $emailAt;
    }

    private static function formatTimestamp(mixed $value): string
    {
        if (! $value instanceof Carbon) {
            return '';
        }

        return $value->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s T');
    }
}
