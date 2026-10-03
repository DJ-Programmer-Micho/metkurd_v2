<?php

use App\Support\LandingContent;
use Livewire\Component;

new class extends Component
{
    public function localeUrl(string $targetLocale): string
    {
        $route = request()->route();
        $name = $route?->getName();

        if (! $name || ! str_starts_with($name, 'landing.')) {
            return route('landing.home', ['locale' => $targetLocale]);
        }

        $parameters = $route->parameters();
        $parameters['locale'] = $targetLocale;

        return route($name, array_merge($parameters, request()->query()));
    }
};
?>

@php
    $locale = app()->getLocale();
    $navLinks = [
        ['route' => 'landing.home', 'pattern' => 'landing.home*', 'label' => LandingContent::text('nav.home')],
        ['route' => 'landing.tools', 'pattern' => 'landing.tools*', 'label' => LandingContent::text('nav.tools')],
        ['route' => 'landing.pricing', 'pattern' => 'landing.pricing*', 'label' => LandingContent::text('nav.pricing')],
        ['route' => 'landing.contact', 'pattern' => 'landing.contact*', 'label' => LandingContent::text('nav.contact')],
    ];

    if (auth('admin')->check()) {
        $primaryAction = [
            'label' => LandingContent::text('nav.admin_panel'),
            'href' => route('admin.home', ['locale' => $locale]),
        ];

        $secondaryAction = null;
    } elseif (auth('app')->check()) {
        $primaryAction = [
            'label' => LandingContent::text('nav.dashboard'),
            'href' => \App\Support\CustomerAppDestination::home($locale),
        ];

        $secondaryAction = [
            'label' => LandingContent::text('nav.profile'),
            'href' => route('app.profile', ['locale' => $locale]),
        ];
    } else {
        $primaryAction = [
            'label' => LandingContent::text('nav.get_started'),
            'href' => route('app.signup'),
        ];

        $secondaryAction = [
            'label' => LandingContent::text('nav.sign_in'),
            'href' => route('app.signin'),
        ];
    }

    $languages = [
        'en' => LandingContent::text('locales.en'),
        'ar' => LandingContent::text('locales.ar'),
        'ku' => LandingContent::text('locales.ku'),
    ];
@endphp

<header>
    <a class="skip-link" href="#main-content">{{ LandingContent::text('nav.skip_to_content') }}</a>

    <nav class="navbar navbar-expand-lg site-navbar fixed-top" aria-label="{{ LandingContent::text('nav.primary_label') }}">
        <div class="container py-2">
            <a
                class="navbar-brand d-flex align-items-center gap-2 text-decoration-none"
                href="{{ route('landing.home', ['locale' => $locale]) }}"
                aria-label="{{ LandingContent::text('nav.home_label') }}"
                wire:navigate
            >
                <span class="brand-badge">
                    <img
                        class="brand-logo brand-logo--dark"
                        src="{{ asset('landing/images/white_logo-44.webp') }}"
                        srcset="{{ asset('landing/images/white_logo-44.webp') }} 44w, {{ asset('landing/images/white_logo-88.webp') }} 88w"
                        sizes="22px" width="44" height="40"
                        alt="{{ LandingContent::text('site.name') }}"
                    >
                    <img
                        class="brand-logo brand-logo--light"
                        src="{{ asset('landing/images/black_logo-44.webp') }}"
                        srcset="{{ asset('landing/images/black_logo-44.webp') }} 44w, {{ asset('landing/images/black_logo-88.webp') }} 88w"
                        sizes="22px" width="44" height="40"
                        alt="{{ LandingContent::text('site.name') }}"
                    >
                </span>
                <span>{{ LandingContent::text('site.name') }}</span>
            </a>

            <button
                class="navbar-toggler btn btn-outline-soft"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#siteNav"
                aria-controls="siteNav"
                aria-expanded="false"
                aria-label="{{ LandingContent::text('nav.toggle_label') }}"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="collapse navbar-collapse" id="siteNav">
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                    @foreach($navLinks as $link)
                        <li class="nav-item">
                            <a
                                class="nav-link {{ request()->routeIs($link['pattern']) ? 'active' : '' }}"
                                href="{{ route($link['route'], ['locale' => $locale]) }}"
                                wire:navigate
                            >
                                {{ $link['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="d-flex align-items-center gap-2 nav-actions">
                    <div class="locale-switch" aria-label="{{ LandingContent::text('nav.locale_label') }}">
                        @foreach($languages as $languageCode => $languageLabel)
                            <a
                                class="locale-link {{ $languageCode === $locale ? 'is-active' : '' }}"
                                href="{{ $this->localeUrl($languageCode) }}"
                                title="{{ $languageLabel }}"
                                aria-label="{{ $languageLabel }}"
                            >
                                {{ $languageCode }}
                            </a>
                        @endforeach
                    </div>

                    <button class="mode-toggle" id="themeToggle" type="button" aria-label="{{ LandingContent::text('nav.toggle_theme') }}">
                        <i class="bi bi-moon-stars"></i>
                    </button>

                    @if($secondaryAction)
                        <a class="btn btn-link text-decoration-none nav-action-link d-none d-lg-inline-flex" href="{{ $secondaryAction['href'] }}" wire:navigate>
                            {{ $secondaryAction['label'] }}
                        </a>
                    @endif

                    <a class="btn btn-glow rounded-pill px-4" href="{{ $primaryAction['href'] }}" wire:navigate>
                        {{ $primaryAction['label'] }}
                    </a>
                </div>
            </div>
        </div>
    </nav>
</header>
