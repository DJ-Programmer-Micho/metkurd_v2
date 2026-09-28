<?php

use App\Rules\ValidTurnstile;
use App\Support\Landing\LandingSettingsRepository;
use App\Support\LandingContent;
use App\Notifications\Landing\TelegramContactUs;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Stevebauman\Location\Facades\Location;

new #[Layout('landing::layouts.app')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $subject = '';
    public string $body = '';
    public string $cfTurnstileResponse = '';
    public bool $submitted = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:4000'],
            'cfTurnstileResponse' => ['bail', 'required', 'string', new ValidTurnstile()],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => __('Please enter your name.'),
            'email.required' => __('Please enter your email address.'),
            'email.email' => __('Please enter a valid email address.'),
            'subject.required' => __('Please enter a subject.'),
            'body.required' => __('Please enter your message.'),
            'cfTurnstileResponse.required' => __('Please complete the human verification challenge.'),
        ];
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['name', 'email', 'subject', 'body'], true)) {
            return;
        }

        $this->submitted = false;
        $this->resetErrorBag('form');
        $this->normalizeFields();
        $this->validateOnly($property);
    }

    public function updatedCfTurnstileResponse(): void
    {
        $this->resetValidation('cfTurnstileResponse');
        $this->resetErrorBag('form');
    }

    public function submitMessage(): void
    {
        $this->submitted = false;
        $this->resetErrorBag();
        $this->normalizeFields();
        $this->ensureNotRateLimited();

        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->resetTurnstileChallenge();

            throw $e;
        }

        $teleId = trim((string) config('services.telegram-bot-api.groups.contact', ''));
        $guestIdentifier = request()->ip();
        $deviceIdentifier = (string) request()->userAgent();
        $location = $this->resolveLocation($guestIdentifier);

        if ($teleId === '') {
            Log::warning('Contact page telegram destination is missing.', [
                'expected_keys' => ['TELEGRAM_GROUP_CON', 'TELEGRAM_CHAT_ID', 'TELEGRAM_GROUP'],
                'route' => request()->path(),
                'locale' => app()->getLocale(),
            ]);
            $this->addError('form', __('Message service is temporarily unavailable. Please try again later.'));
            $this->resetTurnstileChallenge();
            return;
        }

        try {
            Notification::route('telegram', $teleId)->notify(
                new TelegramContactUs(
                    'visitor',
                    '',
                    $this->name,
                    $this->email,
                    $this->subject,
                    $this->body,
                    '',
                    $location,
                    $guestIdentifier,
                    $deviceIdentifier,
                    $teleId
                )
            );

            $this->submitted = true;
            $this->reset(['name', 'email', 'subject', 'body', 'cfTurnstileResponse']);
            $this->resetValidation();
            $this->resetTurnstileChallenge();
        } catch (\Throwable $e) {
            Log::error('Contact page telegram notification failed.', [
                'error' => $e->getMessage(),
                'email' => $this->email,
                'subject' => $this->subject,
                'ip' => $guestIdentifier,
            ]);

            $this->addError('form', __('Message did not send successfully. Please try again.'));
            $this->resetTurnstileChallenge();
        }
    }

    protected function normalizeFields(): void
    {
        $this->name = trim($this->name);
        $this->email = strtolower(trim($this->email));
        $this->subject = trim($this->subject);
        $this->body = trim($this->body);
    }

    protected function resolveLocation(?string $ip): mixed
    {
        if (! $ip) {
            return null;
        }

        try {
            $location = Location::get($ip);

            return $location === false ? null : $location;
        } catch (\Throwable $e) {
            Log::warning('Contact page location lookup failed.', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function ensureNotRateLimited(): void
    {
        $key = $this->rateLimitKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $this->resetTurnstileChallenge();

            throw ValidationException::withMessages([
                'form' => __('Too many messages were sent from your network. Please wait :seconds seconds and try again.', [
                    'seconds' => $seconds,
                ]),
            ]);
        }

        RateLimiter::hit($key, 600);
    }

    protected function rateLimitKey(): string
    {
        return 'landing-contact:' . sha1((string) request()->ip());
    }

    protected function resetTurnstileChallenge(): void
    {
        $this->cfTurnstileResponse = '';
        $this->dispatch('turnstile-reset');
    }
};
?>

@php
    $settingsRepository = app(LandingSettingsRepository::class);
    $supportLines = $settingsRepository->contactSupportLines();
    $companyLines = $settingsRepository->contactCompanyLines();
    $socialLinks = $settingsRepository->activeSocialLinks();
    $locale = app()->getLocale();
    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => LandingContent::text('nav.home'),
                'item' => route('landing.home', ['locale' => $locale]),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => LandingContent::text('contact_page.title'),
                'item' => route('landing.contact', ['locale' => $locale]),
            ],
        ],
    ];
@endphp

<x-slot:title>{{ LandingContent::text('contact_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('contact_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('contact_page.meta.keywords') }}</x-slot:keywords>

@push('meta')
    <script type="application/ld+json">
        @json($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT)
    </script>
@endpush

<div>
    <section class="hero py-5 mt-5">
        <div class="container">
            <div class="text-center reveal contact-card-header">
                <span class="hero-badge mb-3">
                    <i class="bi bi-headset"></i>
                    {{ LandingContent::text('contact_page.badge') }}
                </span>
                <h1 class="display-hero mb-3">{{ LandingContent::text('contact_page.title') }}</h1>
                <p class="lead-soft mx-auto">{{ LandingContent::text('contact_page.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="contact-card glass-card">
                        <h3 class="mb-4">{{ LandingContent::text('contact_page.form_title') }}</h3>

                        @if($submitted)
                            <div class="alert alert-success bg-transparent border-success-subtle text-danger mb-4">
                                <span style="font-weight:bolder">{{ LandingContent::text('common.message_sent') }}</span>
                            </div>
                        @endif

                        @error('form')
                            <div class="alert alert-danger bg-transparent border-danger-subtle text-light mb-4">
                                {{ $message }}
                            </div>
                        @enderror

                        <form wire:submit.prevent="submitMessage" novalidate>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <input
                                        class="form-control @error('name') is-invalid @enderror"
                                        type="text"
                                        wire:model.blur="name"
                                        placeholder="{{ LandingContent::text('contact_page.name_placeholder') }}"
                                        maxlength="120"
                                        autocomplete="name"
                                        aria-invalid="@error('name') true @else false @enderror"
                                    >
                                    @error('name')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-6">
                                    <input
                                        class="form-control @error('email') is-invalid @enderror"
                                        type="email"
                                        wire:model.blur="email"
                                        placeholder="{{ LandingContent::text('contact_page.email_placeholder') }}"
                                        maxlength="190"
                                        autocomplete="email"
                                        aria-invalid="@error('email') true @else false @enderror"
                                    >
                                    @error('email')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <input
                                        class="form-control @error('subject') is-invalid @enderror"
                                        type="text"
                                        wire:model.blur="subject"
                                        placeholder="{{ LandingContent::text('contact_page.subject_placeholder') }}"
                                        maxlength="180"
                                        autocomplete="off"
                                        aria-invalid="@error('subject') true @else false @enderror"
                                    >
                                    @error('subject')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <textarea
                                        class="form-control @error('body') is-invalid @enderror"
                                        rows="6"
                                        wire:model.blur="body"
                                        placeholder="{{ LandingContent::text('contact_page.message_placeholder') }}"
                                        maxlength="4000"
                                        aria-invalid="@error('body') true @else false @enderror"
                                    ></textarea>
                                    @error('body')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <x-turnstile-widget model="cfTurnstileResponse" theme="dark" />
                                </div>

                                <div class="col-12 d-grid">
                                    <button
                                        type="submit"
                                        class="btn btn-glow btn-lg"
                                        wire:loading.attr="disabled"
                                    >
                                        <span wire:loading.remove wire:target="submitMessage">
                                            {{ LandingContent::text('common.send_message') }}
                                        </span>
                                        <span wire:loading wire:target="submitMessage">
                                            {{ __('Sending...') }}
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="contact-card glass-card reveal">
                                <h3>{{ LandingContent::text('contact_page.support_title') }}</h3>
                                @foreach((array) $supportLines as $line)
                                    <p class="text-muted-soft mb-1">{{ $line }}</p>
                                @endforeach
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="contact-card glass-card reveal">
                                <h3>{{ LandingContent::text('contact_page.company_title') }}</h3>
                                @foreach((array) $companyLines as $line)
                                    <p class="text-muted-soft mb-1">{{ $line }}</p>
                                @endforeach
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="contact-card glass-card reveal">
                                <h3>{{ LandingContent::text('contact_page.social_title') }}</h3>
                                @if($socialLinks)
                                    <div class="d-flex gap-2 mt-3">
                                        @foreach($socialLinks as $social)
                                            <a
                                                class="social-link"
                                                href="{{ $social['url'] }}"
                                                aria-label="{{ $social['platform'] }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <i class="{{ $social['icon_class'] }}"></i>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
