{{-- resources/views/app/partials/navbar-one.blade.php --}}
<div class="app-menu navbar-menu">
    <!-- LOGO -->
    <div class="navbar-brand-box">
        <!-- Dark Logo-->
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
        <!-- Light Logo-->
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

            <div id="two-column-menu">
            </div>
            <ul class="navbar-nav" id="navbar-nav">
                <li class="menu-title"><span data-key="t-menu">{{__('Side Bar')}}</span></li>
                
                {{-- <li class="nav-item">
                    <a class="nav-link menu-link" href="#sidebarDashboards" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarDashboards">
                        <i class="bx bxs-dashboard"></i> <span data-key="t-dashboards">{{__('Dashboards')}}</span>
                    </a>
                    <div class="collapse menu-dropdown" id="sidebarDashboards">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item">
                                <a wire:navigate href="{{ route('app.dashboard', ['locale' => app()->getLocale()]) }}" class="nav-link" data-key="t-ecommerce">{{__('Dashboard')}}</a>
                            </li>
                        </ul>
                    </div>
                </li> <!-- end Dashboard Menu --> --}}
                {{-- <x-app.auth.nav-multi-feature-link
                    id="sidebarDashboards"
                    icon="bx bxs-dashboard"
                    :label="__('Dashboards')"
                    :features="null"
                > --}}                 
                <livewire:partials.components.nav-feature-link
                    :route="'app.home'"
                    icon="bx bx-home"
                    :label="__('Home')"
                />
                {{-- </x-app.auth.nav-multi-feature-link> --}}
                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ __('Speech Tools') }}</span></li>

                <livewire:partials.components.nav-feature-link
                    :route="'app.xtts'"
                    icon="ri-volume-up-line"
                    :label="__('Text to Speech')"
                    tool-codes="tts"
                />

                <livewire:partials.components.nav-feature-link 
                    :route="'app.clone-xtts'"
                    icon="bx bx-user-voice"
                    :label="__('Clone Speech')"
                    tool-codes="clone_tts"
                />

                <livewire:partials.components.nav-feature-link 
                    :route="'app.wasr'"
                    icon="ri-file-text-line"
                    :label="__('Speech to Text')"
                    tool-codes="asr"
                />

                {{-- <livewire:partials.components.nav-multi-feature-link
                    id="sidebarAsr"
                    icon="bx bx-microphone"
                    :label="__('SPEECH-TO-TEXT')"
                    :features="null"
                    >

                    <livewire:partials.components.nav-feature-link
                        :route="'app.wasr'"
                        icon="bx bxs-microphone-alt"
                        :label="__('ICE MODEL')"
                        feature="asr.active"
                        badge="PRO"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="app.home"
                        icon="bx bxs-microphone-alt"
                        :label="__('FIRE MODEL')"
                        feature="asr2.active"
                        badge="PRO"
                    />
                </livewire:partials.components.nav-multi-feature-link> --}}

                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ __('Music Tools') }}</span></li>
                    <livewire:partials.components.nav-feature-link
                        :route="'app.stem'"
                        icon="bx bx-music"
                        :label="__('Stem Separation')"
                        tool-codes="stem"
                    />

                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ __('Document Tools') }}</span></li>
                <livewire:partials.components.nav-feature-link
                    :route="'app.ocr'"
                    icon="bx bx-aperture"
                    :label="__('Optical Character Recognition')"
                    tool-codes="ocr"
                />

                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ __('YouTube') }}</span></li>
                <livewire:partials.components.nav-feature-link
                    :route="'app.youtube'"
                    icon="ri-youtube-line"
                    :label="__('YouTube Downloader')"
                    :tool-codes="['youtube_audio', 'youtube_video']"
                />


                {{-- <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">OTHER TOOLS</span></li>
                <x-app.auth.nav-feature-link
                    route="app.audio-format"
                    icon="bx bx-dna"
                    label="Audio Format Converter"
                    feature="audio_format.active"
                    badge="PRO"
                /> --}}
                {{-- <li class="nav-item" >
                    <a class="nav-link menu-link" href="javascript:void(0)" data-toggle="tooltip" data-placement="right" title="Subscribe to PRO Version">
                        <i class="bx bx-dna"></i> <span data-key="t-widgets" style="opacity: 0.6;"></span>
                        <span class="badge badge-pill bg-primary" data-key="t-hot">PRO</span>
                    </a>
                </li> --}}
                {{-- <li class="nav-item" >
                    <a class="nav-link menu-link" href="javascript:void(0)" data-toggle="tooltip" data-placement="right" title="Subscribe to PRO Version">
                        <i class="bx bx-download"></i> <span data-key="t-widgets" style="opacity: 0.6;">Youtube To Audio</span>
                        <span class="badge badge-pill bg-primary" data-key="t-hot">PRO</span>
                    </a>
                </li>
                <li class="nav-item" >
                    <a class="nav-link menu-link" href="javascript:void(0)" data-toggle="tooltip" data-placement="right" title="Subscribe to PRO Version">
                        <i class="bx bx-download"></i> <span data-key="t-widgets" style="opacity: 0.6;">Youtube To Video</span>
                        <span class="badge badge-pill bg-primary" data-key="t-hot">PRO</span>
                    </a>
                </li>
                 --}}
            </ul>
        </div>
    </div>
    <!-- Sidebar -->
    <div class="navbar-brand-box">
        <div class="position-absolute bottom-0">

            <livewire:partials.components.header-account-chip />
        </div>
    </div>

    <div class="sidebar-background"></div>
</div>
