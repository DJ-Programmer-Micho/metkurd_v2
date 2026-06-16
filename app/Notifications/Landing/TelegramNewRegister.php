<?php

namespace App\Notifications\Landing;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\Enums\ParseMode;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

class TelegramNewRegister extends Notification
{
    use Queueable;

    /**
     * @param  array<string, string>  $payload
     */
    public function __construct(
        protected array $payload,
        protected ?string $teleId = null
    ) {}

    public function via($notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram($notifiable): TelegramMessage
    {
        $lines = array_filter([
            '<b>'.$this->escapeTelegram((string) ($this->payload['title'] ?? 'New Register')).'</b>',
            // $this->formatLine('User ID', $this->payload['user_id'] ?? null),
            $this->formatLine('Name', $this->payload['name'] ?? null),
            $this->formatLine('Username', $this->payload['username'] ?? null),
            $this->formatLine('Email', $this->payload['email'] ?? null),
            $this->formatLine('Phone', $this->payload['phone'] ?? null),
            $this->formatLine('Registration Method', $this->payload['registration_method'] ?? null),
            $this->formatLine('Auth Provider', $this->payload['auth_provider'] ?? null),
            $this->formatLine('Email Verification', $this->payload['email_verification_status'] ?? null),
            $this->formatLine('Phone Verification', $this->payload['phone_verification_status'] ?? null),
            $this->formatLine('IP Address', $this->payload['ip_address'] ?? null),
            $this->formatLine('User Agent', $this->payload['user_agent'] ?? null),
            $this->formatLine('Environment', $this->payload['app_environment'] ?? null),
            $this->formatLine('Created At', $this->payload['created_at'] ?? null),
            $this->formatLine('Verified At', $this->payload['verified_at'] ?? null),
        ]);

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
        return $this->payload;
    }

    protected function formatLine(string $label, mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || strtoupper($value) === 'N/A') {
            return null;
        }

        return '<b>'.$this->escapeTelegram($label).':</b> '.$this->escapeTelegram($value);
    }

    protected function escapeTelegram(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
