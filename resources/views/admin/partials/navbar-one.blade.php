{{-- resources/views/app/partials/navbar-one.blade.php --}}
<div class="app-menu navbar-menu">
    <!-- LOGO -->
    <div class="navbar-brand-box">
        <!-- Dark Logo-->
        <a href="/" class="logo logo-dark mt-2">
            <span class="logo-sm mt-0">
                <img src="{{ asset(app('logo_1024_tran')) }}" alt="METKURD" height="25">
            </span>
            <span class="logo-lg mt-0">
                <div class="d-flex align-items-center" style="vertical-align: middle">
                    <div>
                        <img src="{{ asset(app('logo_1024_tran')) }}" alt="METKURD" height="50">
                    </div>
                    <div class="h3 mt-2 mx-1 text-white">MET KURD</div>
                </div>
            </span>
        </a>
        <!-- Light Logo-->
        <a href="/" class="logo logo-light mt-2">
            <span class="logo-sm mt-0">
                <img src="{{ asset(app('logo_1024_tran_black')) }}" alt="METKURD" height="40">
            </span>
            <span class="logo-lg mt-0">
                <div class="d-flex align-items-center" style="vertical-align: middle">
                    <div>
                        <img src="{{ asset(app('logo_1024_tran_black')) }}" alt="METKURD" height="50">
                    </div>
                    <div class="h3 mt-2 mx-1 text-white">MET KURD</div>
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
                    feature="tts.active"
                    badge="PRO"
                />
                {{-- </x-app.auth.nav-multi-feature-link> --}}
                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">SPEECH TOOLS</span></li>

                <livewire:partials.components.nav-feature-link
                    :route="'app.xtts'"
                    icon="bx bx-aperture"
                    label="TEXT-TO-SPEECH"
                    feature="tts.active"
                    badge="PRO"
                />

                <livewire:partials.components.nav-feature-link 
                    :route="'app.clone-xtts'"
                    icon="bx bx-user-voice"
                    label="Clone Speech"
                    feature="clone_tts.active"
                    badge="PRO"
                />

                <livewire:partials.components.nav-multi-feature-link
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
                </livewire:partials.components.nav-multi-feature-link>

                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">MUSIC TOOLS</span></li>
                    <livewire:partials.components.nav-feature-link
                        :route="'app.stem'"
                        icon="bx bx-music"
                        :label="__('STEM')"
                        feature="asr.active"
                        badge="PRO"
                    />

                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">DOCUMENT TOOLS</span></li>
                <livewire:partials.components.nav-feature-link
                    :route="'app.ocr'"
                    icon="bx bx-aperture"
                    :label="__('OPTICAL CHARACTER RECOGNITION')"
                    feature="asr.active"
                    badge="PRO"
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
    <div class="sidebar-background"></div>
</div>