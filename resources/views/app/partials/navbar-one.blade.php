{{-- resources/views/app/partials/navbar-one.blade.php --}}
@php
    /** @var \App\Support\AppShellData $shellData */
    $shellData = app(\App\Support\AppShellData::class);
    $shell = $shellData->forCurrentCustomer();
    $accessMap = $shell['access_map'] ?? [];
    $currentRouteName = request()->route()?->getName();

    $navSections = [
        [
            'title' => __('General'),
            'items' => [
                [
                    'route' => 'app.home',
                    'icon' => 'ri-dashboard-line',
                    'label' => __('Dashboard'),
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
                    'label' => __('Apollo'),
                    'description' => __('Text-To-Speech'),
                    'enabled' => (bool) ($accessMap['tts'] ?? false),
                ],
                [
                    'route' => 'app.f5tts',
                    'icon' => 'ri-volume-up-line',
                    'label' => __('Delta'),
                    'description' => __('Text-To-Speech'),
                    'enabled' => (bool) ($accessMap['ftts'] ?? false),
                ],
                [
                    'route' => 'app.clone-xtts',
                    'icon' => 'bx bx-user-voice',
                    'label' => __('Vector'),
                    'description' => __('Clone Speech'),
                    'enabled' => (bool) ($accessMap['clone_tts'] ?? false),
                ],
            ],
        ],
        [
            'title' => __('Text Tools'),
            'items' => [
                [
                    'route' => 'app.wasr',
                    'icon' => 'ri-file-text-line',
                    'label' => __('NEO'),
                    'description' => __('Speech-To-Text'),
                    'enabled' => (bool) ($accessMap['asr'] ?? false),
                ],
                [
                    'route' => 'app.qasr',
                    'icon' => 'ri-file-text-line',
                    'label' => __('LEO'),
                    'description' => __('Speech-To-Text'),
                    'enabled' => (bool) ($accessMap['qasr'] ?? false),
                ],
                [
                    'route' => 'app.caption',
                    'icon' => 'ri-video-chat-line',
                    'label' => __('Caption'),
                    'description' => __('Subtitle .srt'),
                    'enabled' => (bool) ($accessMap['caption'] ?? false),
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
                    'description' => __('Song-To-Track'),
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
                    'label' => __('OCR Scanner'),
                    'description' => __('PDF/Image-To-Text'),
                    'enabled' => (bool) ($accessMap['ocr'] ?? false),
                ],
            ],
        ],
        [
            'title' => __('Translation Tools'),
            'items' => [
                [
                    'route' => 'app.tran',
                    'icon' => 'ri-translate-2',
                    'label' => __('MET Translation'),
                    'description' => __('Kurdish Translation'),
                    'enabled' => (bool) ($accessMap['tran'] ?? false),
                ],
            ],
        ],
        [
            'title' => __(''),
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

    <div id="scrollbar" class="nav-height">
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
                            @php
                                $isActive = $currentRouteName === $item['route'];
                            @endphp
                            <li class="nav-item">
                                <a
                                    class="nav-link menu-link {{ $isActive ? 'active' : '' }}"
                                    href="{{ route($item['route'], ['locale' => app()->getLocale()]) }}"
                                    wire:navigate
                                    title="{{ $item['label'] }}"
                                    aria-label="{{ $item['label'] }}"
                                    @if($isActive) aria-current="page" @endif
                                >
                                    <i class="{{ $item['icon'] }}"></i>
                                    <span class="nav-link-content">
                                        <span class="nav-link-title">{{ $item['label'] }}</span>
                                        @if(! empty($item['description']))
                                            <small class="nav-link-description">{{ $item['description'] }}</small>
                                        @endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    @endif
                @endforeach
            </ul>
        </div>
    </div>

    <div class="navbar-brand-box">
        <div class="position-absolute bottom-0 pb-3" style="min-width: 220px;">
            <livewire:partials.components.header-account-chip />
        </div>
    </div>

    <div class="sidebar-background"></div>
</div>
