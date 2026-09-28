<aside class="app-menu navbar-menu" id="admin-sidebar" aria-label="{{ __('admin_p3.navigation') }}">
    <div class="navbar-brand-box admin-brand">
        <a wire:navigate href="{{ route('admin.home', ['locale' => app()->getLocale()]) }}">
            <img src="{{ asset(app('logo_1024_tran')) }}" alt="" width="38" height="38">
            <span><strong>{{ __('admin_shell.brand') }}</strong><small>{{ __('admin_shell.control_center') }}</small></span>
        </a>
        <button type="button" class="btn btn-sm admin-sidebar-close" data-admin-sidebar-close aria-label="{{ __('Close') }}"><i class="ri-close-line" aria-hidden="true"></i></button>
    </div>
    <nav id="scrollbar" tabindex="0" aria-label="{{ __('admin_p3.navigation') }}">
        @foreach (\App\Support\Admin\AdminNavigation::groups() as $group => $items)
            @if ($group === 'system')
                <details class="admin-nav-system" @if(collect($items)->contains(fn ($item) => \App\Support\Admin\AdminNavigation::active($item))) open @endif>
                    <summary>{{ __('admin_shell.system') }}</summary>
            @else
                <div class="admin-nav-group">
                    <h2>{{ __('admin_shell.'.$group) }}</h2>
            @endif
            <ul class="admin-nav-list">
                @foreach ($items as $item)
                    <li><a wire:navigate data-admin-nav data-admin-group="{{ __('admin_shell.'.$group) }}" href="{{ route($item['route'], ['locale' => app()->getLocale()] + $item['query']) }}" @class(['admin-nav-link', 'active' => \App\Support\Admin\AdminNavigation::active($item)]) @if(\App\Support\Admin\AdminNavigation::active($item)) aria-current="page" @endif>
                        <i class="{{ $item['icon'] }}" aria-hidden="true"></i><span>{{ __('admin_shell.'.$item['key']) }}</span>
                    </a></li>
                @endforeach
            </ul>
            @if ($group === 'system') </details> @else </div> @endif
        @endforeach
    </nav>
</aside>
