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
        <button type="button" class="btn btn-sm p-0 fs-20 header-item float-end btn-vertical-sm-hover" id="vertical-hover" aria-label="{{ __('admin_p3.navigation') }}">
            <i class="ri-record-circle-line"></i>
        </button>
    </div>

    <div id="scrollbar">
        <div class="container-fluid">

            <div id="two-column-menu">
            </div>
            <ul class="navbar-nav" id="navbar-nav">
                <li class="menu-title"><span data-key="t-menu">{{ __('admin_p3.workspace') }}</span></li>
                                 
                <livewire:partials.components.nav-feature-link
                    :route="'admin.home'"
                    icon="bx bx-home"
                    :label="__('admin_p3.dashboard')"
                />
                <livewire:partials.components.nav-feature-link :route="'admin.operations'" icon="bx bx-search" :label="__('admin_p2.operations')" />
                {{-- </x-app.auth.nav-multi-feature-link> --}}
                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ __('Services') }}</span></li>

                <livewire:partials.components.nav-multi-feature-link
                    id="sidebarService"
                    icon="bx bx-microphone"
                    :label="__('Services')"
                    :features="null"
                    >

                    <livewire:partials.components.nav-feature-link
                        :route="'admin.services.tools'"
                        icon="bx bxs-microphone-alt"
                        :label="__('Tools')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.services.voices"
                        icon="bx bxs-microphone-alt"
                        :label="__('Voices')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.services.pricing"
                        icon="bx bxs-microphone-alt"
                        :label="__('Pricing')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.services.entitlements"
                        icon="bx bxs-microphone-alt"
                        :label="__('Entitlements')"
                    />
                </livewire:partials.components.nav-multi-feature-link>

                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ __('Customers') }}</span></li>

                <livewire:partials.components.nav-multi-feature-link
                    id="sidebarCustomer"
                    icon="bx bx-microphone"
                    :label="__('Customers')"
                    :features="null"
                    >

                    <livewire:partials.components.nav-feature-link
                        :route="'admin.customers.list'"
                        icon="bx bxs-microphone-alt"
                        :label="__('List')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.customers.ranking"
                        icon="bx bxs-microphone-alt"
                        :label="__('Ranking')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.customers.register"
                        icon="bx bxs-microphone-alt"
                        :label="__('Customer Register')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.customers.phone-countries"
                        icon="bx bxs-microphone-alt"
                        :label="__('Phone Countries')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.customers.usage"
                        icon="bx bxs-microphone-alt"
                        :label="__('Usage')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.customers.suspended"
                        icon="bx bxs-microphone-alt"
                        :label="__('Suspended')"
                    />

                </livewire:partials.components.nav-multi-feature-link>

                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ __('admin_p3.billing') }}</span></li>

                <livewire:partials.components.nav-multi-feature-link
                    id="sidebarPayments"
                    icon="bx bx-microphone"
                    :label="__('admin_p3.billing')"
                    :features="null"
                    >

                    <livewire:partials.components.nav-feature-link
                        :route="'admin.payments.plans'"
                        icon="bx bxs-microphone-alt"
                        :label="__('Plans')"
                    />

                    <livewire:partials.components.nav-feature-link
                        :route="'admin.payments.addons'"
                        icon="bx bxs-microphone-alt"
                        :label="__('Addons')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.payments.storage"
                        icon="bx bxs-microphone-alt"
                        :label="__('Storage')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.payments.coupons"
                        icon="bx bxs-microphone-alt"
                        :label="__('Coupons')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.payments.currencies"
                        icon="bx bxs-microphone-alt"
                        :label="__('Currencies')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.payments.methods"
                        icon="bx bxs-microphone-alt"
                        :label="__('Methods')"
                    />

                </livewire:partials.components.nav-multi-feature-link>


                <li class="menu-title mt-1"><i class="ri-more-fill"></i> <span data-key="t-pages">{{ __('Landing CMS') }}</span></li>

                <livewire:partials.components.nav-multi-feature-link
                    id="sidebarLandingCms"
                    icon="bx bx-globe"
                    :label="__('Landing CMS')"
                    :features="null"
                    >

                    <livewire:partials.components.nav-feature-link
                        route="admin.landing.translations"
                        icon="bx bx-translate"
                        :label="__('Translations')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.landing.tools"
                        icon="bx bx-grid-alt"
                        :label="__('Tools Pages')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.landing.contact"
                        icon="bx bx-mail-send"
                        :label="__('Contact & Social')"
                    />

                    <livewire:partials.components.nav-feature-link
                        route="admin.landing.meta"
                        icon="bx bx-palette"
                        :label="__('Meta & Icons')"
                    />
                </livewire:partials.components.nav-multi-feature-link>
            </ul>
        </div>
        </div>
        <!-- Sidebar -->
    <div class="sidebar-background"></div>
</div>
