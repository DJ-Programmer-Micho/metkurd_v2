<?php

use App\Support\LandingContent;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** @var array<string, mixed> */
    public array $tool = [];

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function section(): array
    {
        $raw = data_get($this->tool, 'app_download', []);
        if (! is_array($raw)) {
            $raw = [];
        }

        return [
            'enabled' => $this->toBool(data_get($raw, 'enabled', false)),
            'title' => trim((string) data_get($raw, 'title', '')),
            'body' => trim((string) data_get($raw, 'body', '')),
            'ios' => [
                'enabled' => $this->toBool(data_get($raw, 'ios.enabled', false)),
                'url' => trim((string) data_get($raw, 'ios.url', '')),
                'label' => trim((string) data_get($raw, 'ios.label', '')),
            ],
            'android' => [
                'enabled' => $this->toBool(data_get($raw, 'android.enabled', false)),
                'url' => trim((string) data_get($raw, 'android.url', '')),
                'label' => trim((string) data_get($raw, 'android.label', '')),
            ],
        ];
    }

    /**
     * @return array<int, array{platform:string,url:string,label:string}>
     */
    #[Computed]
    public function buttons(): array
    {
        $section = $this->section;
        if (! $section['enabled']) {
            return [];
        }

        $buttons = [];

        $iosUrl = (string) data_get($section, 'ios.url', '');
        if ($this->toBool(data_get($section, 'ios.enabled', false)) && $this->isValidUrl($iosUrl)) {
            $buttons[] = [
                'platform' => 'ios',
                'url' => $iosUrl,
                'label' => trim((string) data_get($section, 'ios.label', '')) ?: LandingContent::text('common.open_app'),
            ];
        }

        $androidUrl = (string) data_get($section, 'android.url', '');
        if ($this->toBool(data_get($section, 'android.enabled', false)) && $this->isValidUrl($androidUrl)) {
            $buttons[] = [
                'platform' => 'android',
                'url' => $androidUrl,
                'label' => trim((string) data_get($section, 'android.label', '')) ?: LandingContent::text('common.open_app'),
            ];
        }

        return $buttons;
    }

    protected function isValidUrl(string $url): bool
    {
        $url = trim($url);
        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) ((int) $value);
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
};
?>

<div>
    @if($this->section['enabled'])
        @php
            $sectionTitle = data_get($this->section, 'title') ?: LandingContent::text('common.open_app');
            $sectionBody = data_get($this->section, 'body') ?: (string) data_get($this->tool, 'summary', '');
            $squareImageUrl = (string) data_get($this->tool, 'square_image_url', '');
        @endphp

        <section class="section pt-0">
            <div class="container">
                <div class="app-download-card glass-card reveal p-4 p-lg-5">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-8">
                            <span class="section-badge mb-3">
                                <i class="bi bi-phone"></i>
                                {{ LandingContent::text('common.open_app') }}
                            </span>
                            <h2 class="section-title h2 mb-3">{{ $sectionTitle }}</h2>
                            @if($sectionBody !== '')
                                <p class="text-muted-soft mb-0">{{ $sectionBody }}</p>
                            @endif

                            @if($this->buttons !== [])
                                <div class="d-flex flex-wrap gap-2 mt-4">
                                    @foreach($this->buttons as $button)
                                        <a href="{{ $button['url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-soft rounded-pill px-4">
                                            @if($button['platform'] === 'ios')
                                                <i class="bi bi-apple me-1"></i>
                                            @else
                                                <i class="bi bi-google-play me-1"></i>
                                            @endif
                                            {{ $button['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="col-lg-4">
                            <div class="d-flex justify-content-center justify-content-lg-end">
                                @if($squareImageUrl !== '')
                                    <img src="{{ $squareImageUrl }}" alt="{{ (string) data_get($this->tool, 'title', 'Tool') }}" class="tool-square-app-logo" loading="lazy">
                                @else
                                    <div class="icon-chip tool-square-chip">
                                        <span class="tool-fallback-letter">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) data_get($this->tool, 'title', 'T'), 0, 1)) }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endif
</div>
