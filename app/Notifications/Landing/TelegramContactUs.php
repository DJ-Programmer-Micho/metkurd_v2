<?php

namespace App\Notifications\Landing;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\Enums\ParseMode;
use NotificationChannels\Telegram\TelegramMessage;

class TelegramContactUs extends Notification
{
    use Queueable;

    protected string $s_id;
    protected string $resturant_name;
    protected string $name;
    protected string $email;
    protected string $subject;
    protected string $message;
    protected string $phone;
    protected mixed $location;
    protected ?string $guestIdentifier;
    protected ?string $deviceIdentifier;
    protected ?string $tele_id;

    public function __construct(
        string $s_id,
        string $resturant_name,
        string $name,
        string $email,
        string $subject,
        string $message,
        string $phone,
        mixed $location,
        ?string $guestIdentifier,
        ?string $deviceIdentifier,
        ?string $tele_id = null
    ) {
        $this->s_id = $s_id;
        $this->resturant_name = $resturant_name;
        $this->name = $name;
        $this->email = $email;
        $this->subject = $subject;
        $this->message = $message;
        $this->phone = $phone;
        $this->location = $location;
        $this->guestIdentifier = $guestIdentifier;
        $this->deviceIdentifier = $deviceIdentifier;
        $this->tele_id = $tele_id;
    }

    public function via($notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram($notifiable): TelegramMessage
    {
        $registrationId = '#S-' . random_int(10, 99);
        $registration3Id = random_int(100, 999);
        $location = is_object($this->location) ? $this->location : null;
        $sections = [];

        $sections[] = [
            '<b>NEW SUPPORT MESSAGE</b>',
            '<b>MKC-ID:</b> ' . $this->escapeTelegram($registrationId . '-' . $this->s_id . '-' . $registration3Id),
            '<b>Business Name:</b> ' . $this->escapeTelegram($this->resturant_name),
            '<b>Name:</b> ' . $this->escapeTelegram($this->name),
            '<b>Email Address:</b> ' . $this->escapeTelegram($this->email),
            '<b>Phone Number:</b> ' . $this->escapeTelegram($this->phone),
            '<b>Subject:</b> ' . $this->escapeTelegram($this->subject),
            '<b>Message:</b> ' . $this->escapeTelegram($this->message),
        ];

        $this->appendSection($sections, [
            $this->formatLine('IP ADDRESS', $location?->ip ?? $this->guestIdentifier),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('Country', $location?->countryName),
            $this->formatLine('Country Code', $location?->countryCode),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('Region Name', $location?->regionName),
            $this->formatLine('Region Code', $location?->regionCode),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('City Name', $location?->cityName),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('Zip Code', $location?->zipCode),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('Latitude', $location?->latitude),
            $this->formatLine('Longitude', $location?->longitude),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('Area Code', $location?->areaCode),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('Time Zone', $location?->timezone),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('Submitted At', now()->format('Y-m-d H:i:s T')),
        ]);

        $this->appendSection($sections, [
            $this->formatLine('Device', $this->deviceIdentifier),
        ]);

        $content = implode("\n-----------------\n", array_map(
            static fn (array $lines) => implode("\n", $lines),
            $sections
        ));

        $message = TelegramMessage::create()
            ->parseMode(ParseMode::HTML)
            ->content($content);

        if (! empty($this->tele_id)) {
            $message->to($this->tele_id);
        }

        return $message;
    }

    public function toArray($notifiable): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
        ];
    }

    protected function appendSection(array &$sections, array $lines): void
    {
        $lines = array_values(array_filter($lines, static fn (?string $line) => $line !== null));

        if ($lines !== []) {
            $sections[] = $lines;
        }
    }

    protected function formatLine(string $label, mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        return '<b>' . $this->escapeTelegram($label) . ':</b> ' . $this->escapeTelegram($value);
    }

    protected function escapeTelegram(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
