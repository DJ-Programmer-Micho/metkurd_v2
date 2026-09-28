            @php
                $adminProfile = auth('admin')->user()->profile;
                $adminName = trim(($adminProfile?->first_name ?? '').' '.($adminProfile?->last_name ?? '')) ?: (auth('admin')->user()->name ?: __('admin_cleanup.panel'));
                $adminAvatar = $adminProfile?->avatar;
                $adminAvatar = is_string($adminAvatar) && preg_match('~^(?:storage/|images/|dashboard/)~', $adminAvatar) && !str_contains($adminAvatar, '..') ? asset($adminAvatar) : asset(app('logo_1024_tran'));
            @endphp
<header id="page-topbar" class="admin-topbar" data-admin-drawer-background>
    <div class="navbar-header">
        <div class="d-flex align-items-center gap-3 admin-header-context">
            <button type="button" class="btn btn-soft-secondary" data-admin-sidebar-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="{{ __('admin_p3.navigation') }}"><i class="ri-menu-2-line" aria-hidden="true"></i></button>
            <div><div class="admin-header-brand">{{ __('admin_shell.brand') }}</div><div class="text-muted small" data-admin-page-context>{{ __('admin_shell.'.\App\Support\Admin\AdminNavigation::context()['key']) }}</div></div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="dropdown">
                <button type="button" class="btn btn-soft-secondary" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('admin_shell.language') }}"><i class="ri-global-line" aria-hidden="true"></i> <bdi>{{ strtoupper(app()->getLocale()) }}</bdi></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @foreach (['en' => 'English', 'ar' => 'العربية', 'ku' => 'کوردی'] as $locale => $language)
                        <li><button type="button" class="dropdown-item" lang="{{ $locale }}" dir="auto" data-admin-locale="{{ $locale }}">{{ $language }}</button></li>
                    @endforeach
                </ul>
            </div>
            <x-admin-action-menu :label="$adminName" class="topbar-user">
                <x-slot:trigger>
                    <img class="rounded-circle header-profile-user" src="{{ $adminAvatar }}" alt="" width="36" height="36">
                    <span class="admin-identity" dir="auto">{{ $adminName }}</span>
                </x-slot:trigger>
                <h6 class="dropdown-header" dir="auto">{{ $adminName }}</h6>
                <button type="submit" form="logout-form" class="dropdown-item text-danger"><i class="ri-logout-box-line" aria-hidden="true"></i> {{ __('Logout') }}</button>
            </x-admin-action-menu>
        </div>
    </div>
</header>
