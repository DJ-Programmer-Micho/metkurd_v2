<?php

namespace App\Support;

use App\Models\Customer;
use App\Notifications\Landing\TelegramPayment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stevebauman\Location\Facades\Location;

class TelegramPaymentNotifier
{
    public static function send(Customer $customer, string $paymentType, string $selectedPlan, array $paymentDetails = [], string $logContext = 'Payment'): void
    {
        $teleId = trim((string) env('TELEGRAM_GROUP_PAY'));

        if ($teleId === '') {
            return;
        }

        $customer->loadMissing('profile');
        $profile = $customer->profile;

        $nameParts = array_filter([
            trim((string) ($profile?->first_name ?? '')),
            trim((string) ($profile?->last_name ?? '')),
        ]);

        $name = trim(implode(' ', $nameParts));

        if ($name === '') {
            $name = trim((string) $customer->username);
        }

        $guestIdentifier = request()->ip();
        $deviceIdentifier = (string) request()->userAgent();
        $location = static::resolveLocation($guestIdentifier, $logContext);

        try {
            Notification::route('telegram', $teleId)->notify(
                new TelegramPayment(
                    $name !== '' ? $name : 'N/A',
                    trim((string) $customer->username),
                    trim((string) $customer->email),
                    trim((string) ($profile?->job_title ?? '')),
                    trim((string) ($profile?->phone_number ?? '')),
                    $paymentType,
                    $selectedPlan,
                    $paymentDetails,
                    $location,
                    $guestIdentifier,
                    $deviceIdentifier,
                    $teleId
                )
            );
        } catch (\Throwable $e) {
            Log::warning($logContext . ' telegram notification failed.', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'username' => $customer->username,
                'email' => $customer->email,
                'payment_type' => $paymentType,
                'selected_plan' => $selectedPlan,
                'ip' => $guestIdentifier,
            ]);
        }
    }

    protected static function resolveLocation(?string $ip, string $logContext): mixed
    {
        if (! $ip) {
            return null;
        }

        try {
            $location = Location::get($ip);

            return $location === false ? null : $location;
        } catch (\Throwable $e) {
            Log::warning($logContext . ' location lookup failed.', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
