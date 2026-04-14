<?php

namespace App\Support;

use App\Mail\CustomerActionMail;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CustomerEmailNotifier
{
    public static function sendSubscriptionThankYou(Customer $customer, array $payload = [], string $logContext = 'Subscription plan page'): void
    {
        static::send(
            $customer,
            'Your MET KURD subscription is active',
            'app.otp.thank-you-plan',
            array_merge(static::baseData($customer), [
                'planName' => (string) ($payload['plan_name'] ?? 'Service Plan'),
                'billingCycle' => (string) ($payload['billing_cycle'] ?? 'Monthly'),
                'creditsIncluded' => number_format((int) ($payload['monthly_credits'] ?? 0)),
                'activatedOn' => (string) ($payload['activated_on'] ?? now()->format('F d, Y')),
                'actionUrl' => static::appRoute('app.home'),
                'actionLabel' => 'Open Dashboard',
            ]),
            $logContext
        );
    }

    public static function sendAddonThankYou(Customer $customer, array $payload = [], string $logContext = 'Add-on credits page'): void
    {
        static::send(
            $customer,
            'Your MET KURD add-on credits are ready',
            'app.otp.thank-you-addon',
            array_merge(static::baseData($customer), [
                'productName' => (string) ($payload['product_name'] ?? 'Add-on Credits'),
                'creditsAmount' => number_format((int) ($payload['credits_amount'] ?? 0)),
                'orderAmount' => (string) ($payload['amount_label']
                    ?? ('$' . number_format((float) ($payload['amount_usd'] ?? 0), 2))),
                'addedOn' => (string) ($payload['added_on'] ?? now()->format('F d, Y')),
                'statusLabel' => (string) ($payload['status_label'] ?? 'Completed'),
            ]),
            $logContext
        );
    }

    public static function sendStorageThankYou(Customer $customer, array $payload = [], string $logContext = 'Storage plan page'): void
    {
        static::send(
            $customer,
            'Your MET KURD storage plan is active',
            'app.otp.thank-you-storage',
            array_merge(static::baseData($customer), [
                'planName' => (string) ($payload['plan_name'] ?? 'Storage Plan'),
                'storageQuota' => static::formatStorageQuota((int) ($payload['quota_mb'] ?? 0)),
                'amountLabel' => (string) ($payload['amount_label']
                    ?? ('$' . number_format((float) ($payload['amount_usd'] ?? 0), 2))),
                'activatedOn' => (string) ($payload['activated_on'] ?? now()->format('F d, Y')),
            ]),
            $logContext
        );
    }

    public static function sendPaymentFailed(Customer $customer, array $payload = [], string $logContext = 'Payment flow'): void
    {
        static::send(
            $customer,
            'Your MET KURD payment could not be completed',
            'app.otp.payment-failed',
            array_merge(static::baseData($customer), [
                'itemName' => (string) ($payload['item_name'] ?? 'Payment'),
                'amountLabel' => (string) ($payload['amount_label']
                    ?? ('$' . number_format((float) ($payload['amount_usd'] ?? 0), 2))),
                'attemptedOn' => (string) ($payload['attempted_on'] ?? now()->format('F d, Y')),
                'retryUrl' => (string) ($payload['retry_url'] ?? static::appRoute('app.billing')),
            ]),
            $logContext
        );
    }

    public static function sendAccountSuspended(Customer $customer, array $payload = [], string $logContext = 'Customer suspend action'): void
    {
        static::send(
            $customer,
            'Your MET KURD account is currently suspended',
            'app.otp.account-suspended',
            array_merge(static::baseData($customer), [
                'reason' => (string) ($payload['reason'] ?? 'Administrative review required'),
                'effectiveDate' => (string) ($payload['effective_date'] ?? now()->format('F d, Y')),
            ]),
            $logContext
        );
    }

    public static function sendAccountRecovered(Customer $customer, array $payload = [], string $logContext = 'Customer restore action'): void
    {
        static::send(
            $customer,
            'Your MET KURD account access has been restored',
            'app.otp.account-recovered',
            array_merge(static::baseData($customer), [
                'customerEmail' => trim((string) $customer->email),
                'recoveredAt' => (string) ($payload['recovered_at'] ?? now()->format('Y-m-d H:i')),
                'loginUrl' => (string) ($payload['login_url'] ?? route('app.signin')),
            ]),
            $logContext
        );
    }

    protected static function send(Customer $customer, string $subject, string $view, array $viewData, string $logContext): void
    {
        $email = trim((string) $customer->email);

        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new CustomerActionMail($subject, $view, $viewData));
        } catch (\Throwable $e) {
            Log::warning($logContext . ' email notification failed.', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'email' => $email,
                'view' => $view,
                'subject' => $subject,
            ]);
        }
    }

    protected static function baseData(Customer $customer): array
    {
        $customer->loadMissing('profile');
        $profile = $customer->profile;

        $name = trim(implode(' ', array_filter([
            trim((string) ($profile?->first_name ?? '')),
            trim((string) ($profile?->last_name ?? '')),
        ])));

        if ($name === '') {
            $name = trim((string) $customer->username);
        }

        return [
            'customerName' => $name !== '' ? $name : 'there',
            'supportEmail' => 'support@metkurd.ai',
            'supportMailto' => 'mailto:support@metkurd.ai',
        ];
    }

    protected static function appRoute(string $routeName): string
    {
        return route($routeName, ['locale' => app()->getLocale()]);
    }

    protected static function formatStorageQuota(int $quotaMb): string
    {
        if ($quotaMb <= 0) {
            return '0 MB';
        }

        if ($quotaMb >= 1024) {
            $quotaGb = $quotaMb / 1024;
            $formatted = fmod($quotaGb, 1.0) === 0.0
                ? number_format($quotaGb, 0)
                : number_format($quotaGb, 2);

            return $formatted . ' GB';
        }

        return number_format($quotaMb) . ' MB';
    }
}
