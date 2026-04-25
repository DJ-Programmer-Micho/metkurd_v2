<?php

namespace App\Support;

use App\Notifications\Payments\TelegramSubscriptionLifecycleAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class TelegramSubscriptionLifecycleNotifier
{
    public const CHANNEL_CHECKOUT = 'checkout';

    public const CHANNEL_PAYMENT = 'payment';

    /**
     * @param  array<string, mixed>  $details
     */
    public function send(string $title, array $details = [], string $logContext = 'Subscription lifecycle'): void
    {
        $this->sendToChannel(self::CHANNEL_PAYMENT, $title, $details, $logContext);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function sendCheckout(string $title, array $details = [], string $logContext = 'Checkout lifecycle'): void
    {
        $this->sendToChannel(self::CHANNEL_CHECKOUT, $title, $details, $logContext);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function sendToChannel(string $channel, string $title, array $details = [], string $logContext = 'Subscription lifecycle'): void
    {
        $teleId = $this->groupForChannel($channel);

        if ($teleId === '') {
            return;
        }

        try {
            Notification::route('telegram', $teleId)->notify(
                new TelegramSubscriptionLifecycleAlert(
                    title: $title,
                    details: $details,
                    teleId: $teleId,
                )
            );
        } catch (\Throwable $exception) {
            Log::warning($logContext . ' telegram notification failed.', [
                'error' => $exception->getMessage(),
                'title' => $title,
                'details' => $details,
            ]);
        }
    }

    protected function groupForChannel(string $channel): string
    {
        return trim((string) match ($channel) {
            self::CHANNEL_CHECKOUT => config('services.telegram-bot-api.groups.checkout', ''),
            default => config('services.telegram-bot-api.groups.payment', ''),
        });
    }
}
