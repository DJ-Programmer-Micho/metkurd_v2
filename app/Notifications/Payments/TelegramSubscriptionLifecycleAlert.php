<?php

namespace App\Notifications\Payments;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\Enums\ParseMode;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

class TelegramSubscriptionLifecycleAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        protected string $title,
        protected array $details = [],
        protected ?string $teleId = null,
    ) {
    }

    public function via($notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram($notifiable): TelegramMessage
    {
        $lines = [
            '<b>' . $this->escapeTelegram($this->title) . '</b>',
        ];

        foreach ($this->details as $label => $value) {
            $value = $this->normalizeValue($value);

            if ($value === null) {
                continue;
            }

            $lines[] = '<b>' . $this->escapeTelegram((string) $label) . ':</b> ' . $this->escapeTelegram($value);
        }

        $lines[] = '<b>Timestamp:</b> ' . $this->escapeTelegram(
            now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s T')
        );

        $message = TelegramMessage::create()
            ->parseMode(ParseMode::HTML)
            ->content(implode("\n", $lines));

        if (! empty($this->teleId)) {
            $message->to($this->teleId);
        }

        return $message;
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title,
            'details' => $this->details,
        ];
    }

    protected function normalizeValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)
                ->filter(static fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
                ->map(static fn (mixed $item): string => trim((string) $item))
                ->implode(', ');
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function escapeTelegram(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

