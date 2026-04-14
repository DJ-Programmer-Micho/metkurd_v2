<?php

namespace App\Support\Landing;

use App\Models\LandingSetting;
use App\Models\LandingSocialLink;
use App\Support\LandingContent;
use Illuminate\Support\Facades\Schema;

class LandingSettingsRepository
{
    public function contactSupportEmail(): string
    {
        $supportEmail = $this->getString('contact.support_email');

        if ($supportEmail !== '') {
            return $supportEmail;
        }

        $lines = $this->contactSupportLines();

        return (string) ($lines[0] ?? 'support@metkurd.ai');
    }

    /**
     * @return string[]
     */
    public function contactSupportLines(): array
    {
        $lines = $this->getLines('contact.support_lines');

        if ($lines !== []) {
            return $lines;
        }

        return array_values(array_filter(
            array_map('trim', (array) LandingContent::section('contact_page.support_lines')),
            fn (string $line) => $line !== ''
        ));
    }

    /**
     * @return string[]
     */
    public function contactCompanyLines(): array
    {
        $lines = $this->getLines('contact.company_lines');

        if ($lines !== []) {
            return $lines;
        }

        return array_values(array_filter(
            array_map('trim', (array) LandingContent::section('contact_page.company_lines')),
            fn (string $line) => $line !== ''
        ));
    }

    /**
     * @return array<int, array{platform:string,url:string,icon_class:string}>
     */
    public function activeSocialLinks(): array
    {
        if (! Schema::hasTable('landing_social_links')) {
            return [];
        }

        return LandingSocialLink::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('platform')
            ->get()
            ->map(fn (LandingSocialLink $link) => [
                'platform' => (string) $link->platform,
                'url' => (string) $link->url,
                'icon_class' => (string) ($link->icon_class ?: $this->defaultIconForPlatform((string) $link->platform)),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{support_email?:string,support_lines?:array<int, string>,company_lines?:array<int, string>}  $payload
     */
    public function saveContactSettings(array $payload): void
    {
        if (! Schema::hasTable('landing_settings')) {
            return;
        }

        if (array_key_exists('support_email', $payload)) {
            $this->setString('contact.support_email', (string) $payload['support_email']);
        }

        if (array_key_exists('support_lines', $payload)) {
            $this->setLines('contact.support_lines', (array) $payload['support_lines']);
        }

        if (array_key_exists('company_lines', $payload)) {
            $this->setLines('contact.company_lines', (array) $payload['company_lines']);
        }
    }

    public function defaultIconForPlatform(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'facebook' => 'bi bi-facebook',
            'instagram' => 'bi bi-instagram',
            'youtube' => 'bi bi-youtube',
            'tiktok' => 'bi bi-tiktok',
            'x', 'twitter' => 'bi bi-twitter-x',
            'linkedin' => 'bi bi-linkedin',
            'github' => 'bi bi-github',
            'telegram' => 'bi bi-telegram',
            default => 'bi bi-link-45deg',
        };
    }

    protected function getString(string $key, string $fallback = ''): string
    {
        if (! Schema::hasTable('landing_settings')) {
            return $fallback;
        }

        $setting = LandingSetting::query()->where('key', $key)->first();
        $value = $setting?->value;

        if (is_array($value) && array_key_exists('value', $value)) {
            return trim((string) $value['value']);
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $fallback;
    }

    /**
     * @return string[]
     */
    protected function getLines(string $key): array
    {
        if (! Schema::hasTable('landing_settings')) {
            return [];
        }

        $setting = LandingSetting::query()->where('key', $key)->first();
        $value = $setting?->value;

        if (! is_array($value)) {
            return [];
        }

        $lines = $value['lines'] ?? [];

        if (! is_array($lines)) {
            return [];
        }

        $normalized = array_values(array_filter(
            array_map(fn ($line) => trim((string) $line), $lines),
            fn (string $line) => $line !== ''
        ));

        return $normalized;
    }

    protected function setString(string $key, string $value): void
    {
        if (! Schema::hasTable('landing_settings')) {
            return;
        }

        LandingSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => trim($value)]]
        );
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function setLines(string $key, array $lines): void
    {
        if (! Schema::hasTable('landing_settings')) {
            return;
        }

        $normalized = array_values(array_filter(
            array_map(fn ($line) => trim((string) $line), $lines),
            fn (string $line) => $line !== ''
        ));

        LandingSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['lines' => $normalized]]
        );
    }
}
