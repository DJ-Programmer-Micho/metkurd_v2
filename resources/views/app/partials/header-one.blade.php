{{-- resources/views/app/partials/header-one.blade.php --}}
<header id="page-topbar">
    <div class="layout-width">
    <div class="navbar-header">
        <div class="d-flex">
            {{-- <audio id="notificationSound" src="{{ asset('dashboard/audio/notification.mp3') }}" preload="auto" allow="autoplay"></audio> --}}
            <!-- LOGO -->
            <div class="navbar-brand-box horizontal-logo">
                <a href="/" class="logo logo-dark">
                    <span class="logo-sm mt-2">
                        <img src="{{ asset(app('logo_1024_tran')) }}" alt="METKURD" height="25">
                    </span>
                    <span class="logo-lg mt-1">
                        <div class="d-flex align-items-center" style="vertical-align: middle">
                            <div>
                                <img src="{{ asset(app('logo_1024_tran')) }}" alt="METKURD" height="50">
                            </div>
                            <div class="h3 mt-2 mx-1 text-white">MET KURD</div>
                        </div>
                    </span>
                </a>
                <!-- Light Logo-->
                <a href="/" class="logo logo-light">
                    <span class="logo-sm mt-0">
                        <img src="{{ asset(app('logo_1024_tran_black')) }}" alt="METKURD" height="40">
                    </span>
                    <span class="logo-lg mt-1">
                        <div class="d-flex align-items-center" style="vertical-align: middle">
                            <div>
                                <img src="{{ asset(app('logo_1024_tran_black')) }}" alt="METKURD" height="50">
                            </div>
                            <div class="h3 mt-2 mx-1 text-white">MET KURD</div>
                        </div>
                    </span>
                </a>
            </div>

            <button type="button" class="btn btn-sm px-3 fs-16 header-item vertical-menu-btn topnav-hamburger" id="topnav-hamburger-icon" wire:ignore>
                <span class="hamburger-icon">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>

            <!-- App Search-->
            {{-- <form class="app-search d-none d-md-block">
                <div class="position-relative">
                    <input type="text" class="form-control" placeholder="Search..." autocomplete="off" id="search-options" value="">
                    <span class="mdi mdi-magnify search-widget-icon"></span>
                    <span class="mdi mdi-close-circle search-widget-icon search-widget-icon-close d-none" id="search-close-options"></span>
                </div>
                <div class="dropdown-menu dropdown-menu-lg" id="search-dropdown">
                    <div data-simplebar style="max-height: 320px;">
                        <!-- item-->
                        <div class="dropdown-header">
                            <h6 class="text-overflow text-muted mb-0 text-uppercase">Recent Searches</h6>
                        </div>

                        <div class="dropdown-item bg-transparent text-wrap">
                            <a href="index.html" class="btn btn-soft-secondary btn-sm rounded-pill">how to setup <i class="mdi mdi-magnify ms-1"></i></a>
                            <a href="index.html" class="btn btn-soft-secondary btn-sm rounded-pill">buttons <i class="mdi mdi-magnify ms-1"></i></a>
                        </div>
                        <!-- item-->
                        <div class="dropdown-header mt-2">
                            <h6 class="text-overflow text-muted mb-1 text-uppercase">Pages</h6>
                        </div>

                        <!-- item-->
                        <a href="javascript:void(0);" class="dropdown-item notify-item">
                            <i class="ri-bubble-chart-line align-middle fs-18 text-muted me-2"></i>
                            <span>Analytics Dashboard</span>
                        </a>

                        <!-- item-->
                        <a href="javascript:void(0);" class="dropdown-item notify-item">
                            <i class="ri-lifebuoy-line align-middle fs-18 text-muted me-2"></i>
                            <span>Help Center</span>Notifications
                        </a>

                        <!-- item-->
                        <a href="javascript:void(0);" class="dropdown-item notify-item">
                            <i class="ri-user-settings-line align-middle fs-18 text-muted me-2"></i>
                            <span>My account settings</span>
                        </a>

                        <!-- item-->
                        <div class="dropdown-header mt-2">
                            <h6 class="text-overflow text-muted mb-2 text-uppercase">Members</h6>
                        </div>

                        <div class="notification-list">
                            <!-- item -->
                            <a href="javascript:void(0);" class="dropdown-item notify-item py-2">
                                <div class="d-flex">
                                    <img src="assets/images/users/avatar-2.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                    <div class="flex-grow-1">
                                        <h6 class="m-0">Angela Bernier</h6>
                                        <span class="fs-11 mb-0 text-muted">Manager</span>
                                    </div>
                                </div>
                            </a>
                            <!-- item -->
                            <a href="javascript:void(0);" class="dropdown-item notify-item py-2">
                                <div class="d-flex">
                                    <img src="assets/images/users/avatar-3.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                    <div class="flex-grow-1">
                                        <h6 class="m-0">David Grasso</h6>
                                        <span class="fs-11 mb-0 text-muted">Web Designer</span>
                                    </div>
                                </div>
                            </a>
                            <!-- item -->
                            <a href="javascript:void(0);" class="dropdown-item notify-item py-2">
                                <div class="d-flex">
                                    <img src="assets/images/users/avatar-5.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                    <div class="flex-grow-1">
                                        <h6 class="m-0">Mike Bunch</h6>
                                        <span class="fs-11 mb-0 text-muted">React Developer</span>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <div class="text-center pt-3 pb-1">
                        <a href="pages-search-results.html" class="btn btn-primary btn-sm">View All Results <i class="ri-arrow-right-line ms-1"></i></a>
                    </div>
                </div>
            </form> --}}
        </div>

        <div class="d-flex align-items-center">
            {{-- <div class="dropdown d-md-none topbar-head-dropdown header-item">
                <button type="button" class="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle" id="page-header-search-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="bx bx-search fs-22"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end p-0" aria-labelledby="page-header-search-dropdown">
                    <form class="p-3">
                        <div class="form-group m-0">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Search ..." aria-label="Recipient's username">
                                <button class="btn btn-primary" type="submit"><i class="mdi mdi-magnify"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div> --}}

            <div class="dropdown ms-1 topbar-head-dropdown header-item" wire:ignore>
                <button type="button" class="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <img id="header-lang-img" src="{{asset('/lang/'.app()->getLocale().'.png')}}" alt="Header Language" height="30" class="rounded">
                </button>

                <div class="dropdown-menu dropdown-menu-end">
                    @foreach (config('app.locales') as $locale)
                    <a class="d-flex dropdown-item notify-item language py-2" href="#" onclick="changeLanguage('{{ $locale }}')">
                        <img src="{{asset('/lang/'.$locale.'.png')}}" class="me-2 rounded" height="25" width="25"> 
                        <span class="align-middle">{{ __(strtoupper($locale)) }}</span>
                    </a>
                    @endforeach
                    <!-- item-->
                    {{-- <a href="javascript:void(0);" class="dropdown-item notify-item language py-2" data-lang="en" title="English">
                        <img src="assets/images/flags/us.svg" alt="user-image" class="me-2 rounded" height="18">
                       
                    </a>

                    <!-- item-->
                    <a href="javascript:void(0);" class="dropdown-item notify-item language" data-lang="ar" title="Arabic">
                        <img src="assets/images/flags/ae.svg" alt="user-image" class="me-2 rounded" height="18">
                        <span class="align-middle">Arabic</span>
                    </a> --}}
                </div>
            </div>
@php
$notificationsCount = 0;
$notifications = [];
// dd(auth('app')->user()->profile);
@endphp
            <div class="ms-1 header-item d-none d-sm-flex">
                <button type="button" class="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle" data-toggle="fullscreen">
                    <i class='bx bx-fullscreen fs-22'></i>
                </button>
            </div>

            <div class="ms-1 header-item d-none d-sm-flex">
                <button type="button" class="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle light-dark-mode">
                    <i class='bx bx-moon fs-22'></i>
                </button>
            </div>
            
            <div class="dropdown topbar-head-dropdown ms-1 header-item" id="notificationDropdown">
                {{-- <button type="button" class="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-haspopup="true" aria-expanded="false">
                    <i class='bx bx-bell fs-22'></i>
                    <span class="position-absolute topbar-badge fs-10 translate-middle badge rounded-pill bg-danger">{{$notificationsCount}}<span class="visually-hidden">unread messages</span></span>
                </button> --}}
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end p-0" aria-labelledby="page-header-notifications-dropdown">

                    <div class="dropdown-head bg-primary bg-pattern rounded-top">
                        <div class="p-3">
                            <div class="row align-items-center">
                                <div class="col">
                                    <h6 class="m-0 fs-16 fw-semibold text-white"> {{__('Notifications')}} </h6>
                                </div>
                                <div class="col-auto dropdown-tabs">
                                    <span class="badge bg-light-subtle text-body fs-13"> {{$notificationsCount}} {{__('New')}}</span>
                                </div>
                            </div>
                        </div>

                        <div class="px-2 pt-2">
                            <ul class="nav nav-tabs dropdown-tabs nav-tabs-custom" data-dropdown-tabs="true" id="notificationItemsTab" role="tablist">
                                <li class="nav-item waves-effect waves-light">
                                    <a class="nav-link active" data-bs-toggle="tab" href="#all-noti-tab" role="tab" aria-selected="true">
                                        {{__('All')}} ({{$notificationsCount}})
                                    </a>
                                </li>
                                {{-- <li class="nav-item waves-effect waves-light">
                                    <a class="nav-link" data-bs-toggle="tab" href="#messages-tab" role="tab" aria-selected="false">
                                        Messages
                                    </a>
                                </li>
                                <li class="nav-item waves-effect waves-light">
                                    <a class="nav-link" data-bs-toggle="tab" href="#alerts-tab" role="tab" aria-selected="false">
                                        Alerts
                                    </a>
                                </li> --}}
                            </ul>
                        </div>

                    </div>
                    <div class="tab-content position-relative" id="notificationItemsTabContent">
                        <div class="tab-pane fade show active py-2 ps-2" id="all-noti-tab" role="tabpanel">
                            <div data-simplebar style="max-height: 300px;" class="pe-2">
                                @forelse ($notifications as $notification)
                                <div class="text-reset notification-item d-block dropdown-item position-relative">
                                    <div class="notification">
                                        {{ $notification->data['message'] }} <small class="text-secondary">{{$notification->created_at->diffForHumans()}}</small><br>
                                        <button type="button" wire:click="markAsRead('{{ $notification->id }}')" class="btn btn-soft-success btn-sm">
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                        @if (!empty($notification->data['orderNumber']))
                                        <a target="_blank" wire:click="markAsRead('{{ $notification->id }}')" href="{{ route('super.orderManagementsViewer', ['locale' => app()->getLocale(), 'id' => $notification->data['orderNumber']]) }}" class="btn btn-soft-secondary btn-sm">
                                            <i class="fa-regular fa-eye"></i>
                                        </a>
                                    @endif
                                    </div>
                                </div>
                                @empty
                                {{__('No Notifications')}}
                                @endforelse
                                {{-- <div class="text-reset notification-item d-block dropdown-item position-relative">
                                    <div class="d-flex">
                                        <img src="assets/images/users/avatar-2.jpg" class="me-3 rounded-circle avatar-xs flex-shrink-0" alt="user-pic">
                                        <div class="flex-grow-1">
                                            <a href="#!" class="stretched-link">
                                                <h6 class="mt-0 mb-1 fs-13 fw-semibold">Angela Bernier</h6>
                                            </a>
                                            <div class="fs-13 text-muted">
                                                <p class="mb-1">Answered to your comment on the cash flow forecast's
                                                    graph 🔔.</p>
                                            </div>
                                            <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                <span><i class="mdi mdi-clock-outline"></i> 48 min ago</span>
                                            </p>
                                        </div>
                                        <div class="px-2 fs-15">
                                            <div class="form-check notification-check">
                                                <input class="form-check-input" type="checkbox" value="" id="all-notification-check02">
                                                <label class="form-check-label" for="all-notification-check02"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-reset notification-item d-block dropdown-item position-relative">
                                    <div class="d-flex">
                                        <div class="avatar-xs me-3 flex-shrink-0">
                                            <span class="avatar-title bg-danger-subtle text-danger rounded-circle fs-16">
                                                <i class='bx bx-message-square-dots'></i>
                                            </span>
                                        </div>
                                        <div class="flex-grow-1">
                                            <a href="#!" class="stretched-link">
                                                <h6 class="mt-0 mb-2 fs-13 lh-base">You have received <b class="text-success">20</b> new messages in the conversation
                                                </h6>
                                            </a>
                                            <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                <span><i class="mdi mdi-clock-outline"></i> 2 hrs ago</span>
                                            </p>
                                        </div>
                                        <div class="px-2 fs-15">
                                            <div class="form-check notification-check">
                                                <input class="form-check-input" type="checkbox" value="" id="all-notification-check03">
                                                <label class="form-check-label" for="all-notification-check03"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-reset notification-item d-block dropdown-item position-relative">
                                    <div class="d-flex">
                                        <img src="assets/images/users/avatar-8.jpg" class="me-3 rounded-circle avatar-xs flex-shrink-0" alt="user-pic">
                                        <div class="flex-grow-1">
                                            <a href="#!" class="stretched-link">
                                                <h6 class="mt-0 mb-1 fs-13 fw-semibold">Maureen Gibson</h6>
                                            </a>
                                            <div class="fs-13 text-muted">
                                                <p class="mb-1">We talked about a project on linkedin.</p>
                                            </div>
                                            <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                <span><i class="mdi mdi-clock-outline"></i> 4 hrs ago</span>
                                            </p>
                                        </div>
                                        <div class="px-2 fs-15">
                                            <div class="form-check notification-check">
                                                <input class="form-check-input" type="checkbox" value="" id="all-notification-check04">
                                                <label class="form-check-label" for="all-notification-check04"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div> --}}

                                {{-- <div class="my-3 text-center view-all">
                                    <button type="button" class="btn btn-soft-success waves-effect waves-light">View
                                        All Notifications <i class="ri-arrow-right-line align-middle"></i></button>
                                </div> --}}
                            </div>

                        </div>
                    {{-- 
                        <div class="tab-pane fade py-2 ps-2" id="messages-tab" role="tabpanel" aria-labelledby="messages-tab">
                            <div data-simplebar style="max-height: 300px;" class="pe-2">
                                <div class="text-reset notification-item d-block dropdown-item">
                                    <div class="d-flex">
                                        <img src="assets/images/users/avatar-3.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                        <div class="flex-grow-1">
                                            <a href="#!" class="stretched-link">
                                                <h6 class="mt-0 mb-1 fs-13 fw-semibold">James Lemire</h6>
                                            </a>
                                            <div class="fs-13 text-muted">
                                                <p class="mb-1">We talked about a project on linkedin.</p>
                                            </div>
                                            <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                <span><i class="mdi mdi-clock-outline"></i> 30 min ago</span>
                                            </p>
                                        </div>
                                        <div class="px-2 fs-15">
                                            <div class="form-check notification-check">
                                                <input class="form-check-input" type="checkbox" value="" id="messages-notification-check01">
                                                <label class="form-check-label" for="messages-notification-check01"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-reset notification-item d-block dropdown-item">
                                    <div class="d-flex">
                                        <img src="assets/images/users/avatar-2.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                        <div class="flex-grow-1">
                                            <a href="#!" class="stretched-link">
                                                <h6 class="mt-0 mb-1 fs-13 fw-semibold">Angela Bernier</h6>
                                            </a>
                                            <div class="fs-13 text-muted">
                                                <p class="mb-1">Answered to your comment on the cash flow forecast's
                                                    graph 🔔.</p>
                                            </div>
                                            <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                <span><i class="mdi mdi-clock-outline"></i> 2 hrs ago</span>
                                            </p>
                                        </div>
                                        <div class="px-2 fs-15">
                                            <div class="form-check notification-check">
                                                <input class="form-check-input" type="checkbox" value="" id="messages-notification-check02">
                                                <label class="form-check-label" for="messages-notification-check02"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-reset notification-item d-block dropdown-item">
                                    <div class="d-flex">
                                        <img src="assets/images/users/avatar-6.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                        <div class="flex-grow-1">
                                            <a href="#!" class="stretched-link">
                                                <h6 class="mt-0 mb-1 fs-13 fw-semibold">Kenneth Brown</h6>
                                            </a>
                                            <div class="fs-13 text-muted">
                                                <p class="mb-1">Mentionned you in his comment on 📃 invoice #12501.
                                                </p>
                                            </div>
                                            <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                <span><i class="mdi mdi-clock-outline"></i> 10 hrs ago</span>
                                            </p>
                                        </div>
                                        <div class="px-2 fs-15">
                                            <div class="form-check notification-check">
                                                <input class="form-check-input" type="checkbox" value="" id="messages-notification-check03">
                                                <label class="form-check-label" for="messages-notification-check03"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-reset notification-item d-block dropdown-item">
                                    <div class="d-flex">
                                        <img src="assets/images/users/avatar-8.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                        <div class="flex-grow-1">
                                            <a href="#!" class="stretched-link">
                                                <h6 class="mt-0 mb-1 fs-13 fw-semibold">Maureen Gibson</h6>
                                            </a>
                                            <div class="fs-13 text-muted">
                                                <p class="mb-1">We talked about a project on linkedin.</p>
                                            </div>
                                            <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                <span><i class="mdi mdi-clock-outline"></i> 3 days ago</span>
                                            </p>
                                        </div>
                                        <div class="px-2 fs-15">
                                            <div class="form-check notification-check">
                                                <input class="form-check-input" type="checkbox" value="" id="messages-notification-check04">
                                                <label class="form-check-label" for="messages-notification-check04"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="my-3 text-center view-all">
                                    <button type="button" class="btn btn-soft-success waves-effect waves-light">View
                                        All Messages <i class="ri-arrow-right-line align-middle"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade p-4" id="alerts-tab" role="tabpanel" aria-labelledby="alerts-tab"></div>

                        <div class="notification-actions" id="notification-actions">
                            <div class="d-flex text-muted justify-content-center">
                                Select <div id="select-content" class="text-body fw-semibold px-1">0</div> Result <button type="button" class="btn btn-link link-danger p-0 ms-3" data-bs-toggle="modal" data-bs-target="#removeNotificationModal">Remove</button>
                            </div>
                        </div> --}}
                    </div>
                </div>
            </div>
            
            <livewire:partials.process-slots />
            @php
                $c =  auth('app')->user();
                $planCode = $c?->serviceCode() ?? 'free';
                $planName = strtoupper($planCode);

                $planClass = match ($planCode) {
                    'premium' => 'bg-warning text-dark',
                    'pro'     => 'bg-success',
                    'student' => 'bg-info',
                    default   => 'bg-secondary',
                };
            @endphp
            <div class="dropdown ms-sm-3 header-item topbar-user" wire:ignore>
                <button type="button" class="btn" id="page-header-user-dropdown"
                        data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="d-flex align-items-center">

                        <div class="position-relative me-2">
                            <img class="rounded-circle header-profile-user"
                                style="width:38px;height:38px;object-fit:cover;"
                                src="{{ auth('app')->user()->profile->avatar_url ?? app('userImg') }}"
                                alt="{{ auth('app')->user()->profile->first_name ?? 'User' }}">

                            {{-- ✅ badge position fixed --}}
                            <span class="position-absolute badge rounded-pill {{ $planClass }}"
                                style="right:-8px; font-size:10px; padding:4px 6px; margin-top:27px;">
                                {{ $planName }}
                            </span>
                        </div>

                        <span class="text-start">
                            <span class="d-none d-xl-inline-block fw-medium user-name-text">
                                {{ auth('app')->user()->profile->first_name }} {{ auth('app')->user()->profile->last_name }}
                            </span>
                        </span>

                    </span>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <h6 class="dropdown-header">{{__('Welcome')}} {{ auth('app')->user()->profile->first_name }}</h6>
                    <a wire:navigate.hover class="dropdown-item" href="{{ route('app.profile',['locale' => app()->getLocale()]) }}">
                        <i class="mdi mdi-account-circle text-muted fs-16 align-middle me-1"></i>
                        <span class="align-middle">{{__('Profile')}}</span>
                    </a>
                    <a wire:navigate.hover class="dropdown-item" href="{{ route('app.storage',['locale' => app()->getLocale()]) }}">
                        <i class="mdi mdi-chart-pie text-muted fs-16 align-middle me-1"></i>
                        <span class="align-middle">{{__('My Storage')}}</span>
                    </a>
                    <hr class="my-1">
                    <a wire:navigate.hover class="dropdown-item" href="{{ route('subscription-plan',['locale' => app()->getLocale()]) }}">
                        <i class="mdi mdi-star-shooting-outline text-info fs-16 align-middle me-1"></i>
                        <span class="align-middle">{{__('Upgrade Plan')}}</span>
                    </a>
                    <a wire:navigate.hover class="dropdown-item" href="{{ route('storage-plan',['locale' => app()->getLocale()]) }}">
                        <i class="mdi mdi-harddisk-plus fs-16 text-primary align-middle me-1"></i>
                        <span class="align-middle">{{__('Upgrade Storage')}}</span>
                    </a>
                    <a wire:navigate.hover class="dropdown-item" href="{{ route('addon-credits',['locale' => app()->getLocale()]) }}">
                        <i class="mdi mdi-plus-circle-multiple-outline text-warning fs-16 align-middle me-1"></i>
                        <span class="align-middle">{{__('Buy Credits')}}</span>
                    </a>
                    <hr class="my-1">
                    <a class="dropdown-item" href="#"
                    onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                        <i class="mdi mdi-logout text-danger fs-16 align-middle me-1"></i>
                        <span class="align-middle text-danger">{{__('Logout')}}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
</header>