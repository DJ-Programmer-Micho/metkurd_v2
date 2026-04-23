<?php

namespace App\Support;

use App\Notifications\Payments\TelegramSubscriptionLifecycleAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class TelegramSubscriptionLifecycleNotifier
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function send(string $title, array $details = [], string $logContext = 'Subscription lifecycle'): void
    {
        $teleId = trim((string) env('TELEGRAM_GROUP_PAY'));

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
}

