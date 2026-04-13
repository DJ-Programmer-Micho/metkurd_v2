{{-- resources/views/app/partials/navbar-one.blade.php --}}
@php
    /** @var \App\Support\AppShellData $shellData */
    $shellData = app(\App\Support\AppShellData::class);
    $shell = $shellData->forCurrentCustomer();
    $accessMap = $shell['access_map'] ?? [];

    $navSections = [
        [
            'title' => __('General'),
            'items' => [
                [
                    'route' => 'app.home',
                    'icon' => 'bx bx-home',
                    'label' => __('Home'),
                    'enabled' => true,
                ],
            ],
        ],
        [
            'title' => __('Speech Tools'),
            'items' => [
                [
                    'route' => 'app.xtts',
                    'icon' => 'ri-volume-up-line',
                    'label' => __('TTS Apollo'),
                    'enabled' => (bool) ($accessMap['tts'] ?? false),
                ],
                [
                    'route' => 'app.f5tts',
                    'icon' => 'ri-volume-up-line',
                    'label' => __('F5TTS Delta'),
                    'enabled' => (bool) ($accessMap['ftts'] ?? false),
                ],
                [
                    'route' => 'app.clone-xtts',
                    'icon' => 'bx bx-user-voice',
                    'label' => __('CTTS Vector'),
                    'enabled' => (bool) ($accessMap['clone_tts'] ?? false),
                ],
                [
                    'route' => 'app.wasr',
                    'icon' => 'ri-file-text-line',
                    'label' => __('WASR NEO'),
                    'enabled' => (bool) ($accessMap['asr'] ?? false),
                ],
                [
                    'route' => 'app.qasr',
                    'icon' => 'ri-file-text-line',
                    'label' => __('QASR LEO'),
                    'enabled' => (bool) ($accessMap['qasr'] ?? false),
                ],
            ],
        ],
        [
            'title' => __('Music Tools'),
            'items' => [
                [
                    'route' => 'app.stem',
                    'icon' => 'bx bx-music',
                    'label' => __('Stem Separation'),
                    'enabled' => (bool) ($accessMap['stem'] ?? false),
                ],
            ],
        ],
        [
            'title' => __('Document Tools'),
            'items' => [
                [
                    'route' => 'app.ocr',
                    'icon' => 'bx bx-aperture',
                    'label' => __('Optical Character Recognition'),
                    'enabled' => (bool) ($accessMap['ocr'] ?? false),
                ],
            ],
        ],
        [
            'title' => __('Transcription Tools'),
            'items' => [
                [
                    'route' => 'app.youtube',
                    'icon' => 'ri-trademark-line',
                    'label' => __('MET Translation'),
                    'enabled' => (bool) ($accessMap['youtube_download'] ?? false),
                ],
            ],
        ],
        [
            'title' => __('YouTube'),
            'items' => [
                [
                    'route' => 'app.youtube',
                    'icon' => 'ri-youtube-line',
                    'label' => __('YouTube Downloader'),
                    'enabled' => (bool) ($accessMap['youtube_download'] ?? false),
                ],
            ],
        ],
    ];
@endphp

<div class="app-menu navbar-menu" id="app-navbar-menu">
    <div class="navbar-brand-box">
        <a href="/" class="logo logo-dark mt-2">
            <span class="logo-sm mt-0">
                <img src="{{ asset(app('logo_1024_tran')) }}" alt="{{ __('METKURD') }}" height="25">
            </span>
            <span class="logo-lg mt-0">
                <div class="d-flex align-items-center" style="vertical-align: middle">
                    <div>
                        <img src="{{ asset(app('logo_1024_tran')) }}" alt="{{ __('METKURD') }}" height="50">
                    </div>
                    <div class="h3 mt-2 mx-1 text-white">{{ __('MET KURD') }}</div>
                </div>
            </span>
        </a>

        <a href="/" class="logo logo-light mt-2">
            <span class="logo-sm mt-0">
                <img src="{{ asset(app('logo_1024_tran_black')) }}" alt="{{ __('METKURD') }}" height="40">
            </span>
            <span class="logo-lg mt-0">
                <div class="d-flex align-items-center" style="vertical-align: middle">
                    <div>
                        <img src="{{ asset(app('logo_1024_tran_black')) }}" alt="{{ __('METKURD') }}" height="50">
                    </div>
                    <div class="h3 mt-2 mx-1 text-white">{{ __('MET KURD') }}</div>
                </div>
            </span>
        </a>

        <button type="button" class="btn btn-sm p-0 fs-20 header-item float-end btn-vertical-sm-hover" id="vertical-hover">
            <i class="ri-record-circle-line"></i>
        </button>
    </div>

    <div id="scrollbar">
        <div class="container-fluid">
            <div id="two-column-menu"></div>

            <ul class="navbar-nav" id="navbar-nav">
                <li class="menu-title"><span data-key="t-menu">{{ __('Side Bar') }}</span></li>

                @foreach($navSections as $section)
                    @php
                        $items = array_values(array_filter($section['items'], fn (array $item) => $item['enabled']));
                    @endphp

                    @if($items !== [])
                        @if($loop->index > 0)
                            <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ $section['title'] }}</span></li>
                        @endif

                        @foreach($items as $item)
                            <li class="nav-item">
                                <a
                                    class="nav-link menu-link"
                                    href="{{ route($item['route'], ['locale' => app()->getLocale()]) }}"
                                    wire:navigate
                                >
                                    <i class="{{ $item['icon'] }}"></i>
                                    <span>{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    @endif
                @endforeach
            </ul>
        </div>
    </div>

    <div class="navbar-brand-box">
        <div class="position-absolute bottom-0 px-3 pb-3" style="min-width: 220px;">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted">{{ __('Credits') }}</small>
                <small class="fw-semibold">
                    {{ number_format((int) ($shell['credit_balance'] ?? 0)) }}
                    /
                    {{ number_format((int) ($shell['monthly_credits'] ?? 0)) }}
                </small>
            </div>
            <div class="progress" style="height:6px;">
                <div class="progress-bar" style="width: {{ (int) ($shell['credits_pct'] ?? 0) }}%;"></div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-2 mb-1">
                <small class="text-muted">{{ __('Storage') }}</small>
                <small class="fw-semibold">
                    {{ __(':used MB / :total MB', [
                        'used' => number_format((int) ($shell['storage_used_mb'] ?? 0)),
                        'total' => number_format((int) ($shell['storage_quota_mb'] ?? 0)),
                    ]) }}
                </small>
            </div>
            <div class="progress" style="height:6px;">
                <div class="progress-bar bg-info" style="width: {{ (int) ($shell['storage_pct'] ?? 0) }}%;"></div>
            </div>
        </div>
    </div>

    <div class="sidebar-background"></div>
</div>
