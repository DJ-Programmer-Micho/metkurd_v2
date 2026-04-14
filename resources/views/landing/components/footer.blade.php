<?php

use App\Support\LandingContent;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $newsletterEmail = '';
    public bool $newsletterSubmitted = false;

    #[Computed]
    public function resourceLinks(): array
    {
        return collect(LandingContent::section('tool_catalog'))
            ->take(3)
            ->map(function (array $tool, string $code) {
                return [
                    'label' => $tool['title'] ?? strtoupper($code),
                    'href' => route('landing.tools.show', [
                        'locale' => app()->getLocale(),
                        'slug' => $tool['slug'] ?? $code,
                    ]),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function submitNewsletter(): void
    {
        $this->validate([
            'newsletterEmail' => ['required', 'email', 'max:190'],
        ]);

        $this->newsletterSubmitted = true;
        $this->reset('newsletterEmail');
    }
};
?>

@php
    $locale = app()->getLocale();
    $platformLinks = [
        ['label' => LandingContent::text('nav.tools'), 'href' => route('landing.tools', ['locale' => $locale])],
        ['label' => LandingContent::text('nav.pricing'), 'href' => route('landing.pricing', ['locale' => $locale])],
    ];

    if (auth('admin')->check()) {
        $platformLinks[] = ['label' => LandingContent::text('nav.admin_panel'), 'href' => route('admin.home', ['locale' => $locale])];
    } elseif (auth('app')->check()) {
        $platformLinks[] = ['label' => LandingContent::text('nav.dashboard'), 'href' => route('app.home', ['locale' => $locale])];
        $platformLinks[] = ['label' => LandingContent::text('nav.profile'), 'href' => route('app.profile', ['locale' => $locale])];
    } else {
        $platformLinks[] = ['label' => LandingContent::text('nav.get_started'), 'href' => route('app.signup')];
        $platformLinks[] = ['label' => LandingContent::text('nav.sign_in'), 'href' => route('app.signin')];
    }

    $legalLinks = [
        ['label' => LandingContent::text('nav.terms'), 'href' => route('landing.terms', ['locale' => $locale])],
        ['label' => LandingContent::text('nav.privacy'), 'href' => route('landing.privacy', ['locale' => $locale])],
        ['label' => LandingContent::text('nav.contact'), 'href' => route('landing.contact', ['locale' => $locale])],
    ];

    $statusLines = [
        LandingContent::text('footer.status_uptime'),
        LandingContent::text('footer.status_secure'),
        LandingContent::text('footer.status_accessible'),
    ];
@endphp

<footer class="site-footer">
    <div class="container">
        <div class="newsletter glass-card mb-5 reveal">
            <div class="row align-items-center justify-content-between g-4">
                <div class="col-lg-7">
                    <span class="section-badge mb-3">
                        <i class="bi bi-envelope-paper"></i>
                        {{ LandingContent::text('footer.newsletter_badge') }}
                    </span>
                    <h3 class="fw-bold mb-2">{{ LandingContent::text('footer.newsletter_title') }}</h3>
                    <p class="text-muted-soft mb-0">{{ LandingContent::text('footer.newsletter_copy') }}</p>
                </div>

                <div class="col-lg-5 ">
                    <img src="{{ asset('/app/logo/qr_tele.png') }}" alt="https://t.me/metkurd_ai" width="50%">
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="brand-badge">
                        <img
                            class="brand-logo brand-logo--dark"
                            src="{{ asset(app('logo_1024_tran_black')) }}"
                            alt="{{ __('METKURD') }}"
                        >
                        <img
                            class="brand-logo brand-logo--light"
                            src="{{ asset(app('logo_1024_tran')) }}"
                            alt="{{ __('METKURD') }}"
                        >
                    </span>
                    <strong>{{ LandingContent::text('site.name') }}</strong>
                </div>
                <p class="text-muted-soft">{{ LandingContent::text('footer.copy') }}</p>
                <div class="d-flex gap-2">
                    <a class="social-link" href="#" aria-label="{{ __('LinkedIn') }}"><i class="bi bi-linkedin"></i></a>
                    <a class="social-link" href="#" aria-label="{{ __('X') }}"><i class="bi bi-twitter-x"></i></a>
                    <a class="social-link" href="#" aria-label="{{ __('GitHub') }}"><i class="bi bi-github"></i></a>
                    <a class="social-link" href="#" aria-label="{{ __('YouTube') }}"><i class="bi bi-youtube"></i></a>
                </div>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="fw-bold mb-3">{{ LandingContent::text('footer.platform_heading') }}</h6>
                <div class="d-flex flex-column gap-2">
                    @foreach($platformLinks as $link)
                        <a class="footer-link" href="{{ $link['href'] }}" wire:navigate>{{ $link['label'] }}</a>
                    @endforeach
                </div>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="fw-bold mb-3">{{ LandingContent::text('footer.resources_heading') }}</h6>
                <div class="d-flex flex-column gap-2">
                    @foreach($this->resourceLinks as $link)
                        <a class="footer-link" href="{{ $link['href'] }}" wire:navigate>{{ $link['label'] }}</a>
                    @endforeach
                </div>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="fw-bold mb-3">{{ LandingContent::text('footer.legal_heading') }}</h6>
                <div class="d-flex flex-column gap-2">
                    @foreach($legalLinks as $link)
                        <a class="footer-link" href="{{ $link['href'] }}" wire:navigate>{{ $link['label'] }}</a>
                    @endforeach
                </div>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="fw-bold mb-3">{{ LandingContent::text('footer.status_heading') }}</h6>
                <div class="d-flex flex-column gap-2 text-muted-soft">
                    @foreach($statusLines as $statusLine)
                        <span>{{ $statusLine }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <hr class="my-4" style="border-color: var(--stroke);">

        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 text-muted-soft small">
            <span>{{ LandingContent::text('footer.rights', ['year' => now()->year]) }}</span>
            <span>{{ LandingContent::text('footer.made_for') }}</span>
        </div>
    </div>
</footer>
