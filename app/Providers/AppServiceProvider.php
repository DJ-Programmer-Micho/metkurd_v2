<?php

namespace App\Providers;

use App\Contracts\Payments\AreebaGatewayInterface;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Fib\FibOneTimePaymentClient;
use App\Domain\Payments\Models\Payment;
use App\Events\Payments\PaymentConfirmed;
use App\Http\Middleware\LocalizationMainMiddleware;
use App\Listeners\Payments\RunPaymentFulfillment;
use App\Models\Customer;
use App\Observers\CustomerObserver;
use App\Policies\PaymentPolicy;
use App\Services\Payments\Areeba\AreebaHttpGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Blaze\Blaze;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public $hetzner_S3_domain = 'https://fsn1.your-objectstorage.com/metkurd-v1/';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, FibOneTimePaymentClient::class);
        $this->app->singleton(AreebaGatewayInterface::class, AreebaHttpGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Support\Admin\AdminAccess::register();
        $this->app->scoped(\App\Services\Admin\AdminAudit::class);
        Livewire::addPersistentMiddleware([\App\Http\Middleware\EnsureAdminIsActive::class]);
        foreach ([\App\Models\Customer::class, \App\Models\Tool::class, \App\Models\ToolAction::class,
            \App\Models\ServicePlan::class, \App\Models\StoragePlan::class, \App\Models\CreditProduct::class,
            \App\Models\Coupon::class, \App\Models\PaymentMethod::class, \App\Models\PricingRule::class,
            \App\Models\PlanEntitlement::class, \App\Models\Voice::class, \App\Models\PlanVoiceAccess::class,
            \App\Models\Currency::class, \App\Models\CurrencyExchangeRate::class,
            \App\Models\LandingToolPage::class, \App\Models\LandingSocialLink::class,
            \App\Models\LandingSetting::class, \App\Models\SiteMetaSetting::class,
            \App\Models\CustomerServiceSubscription::class, \App\Models\CustomerStorageSubscription::class,
            \App\Models\CreditOrder::class, Payment::class] as $auditedModel) {
            $auditedModel::observe(\App\Observers\AdminAuditObserver::class);
        }
        // Framework validation groups complement the existing area JSON catalogs.
        app('translation.loader')->addPath(resource_path('lang'));
        // Blaze::optimize()->in(
        //     resource_path('views/app'),
        //     fold: true,

        //     );

        $this->configureDefaults();
        $this->configureLivewireRoutes();
        Customer::observe(CustomerObserver::class);
        foreach ([\App\Models\CustomerServiceSubscription::class, \App\Models\CreditWallet::class] as $accountModel) {
            $accountModel::saved(function ($record) {
                $customerId = (int) $record->customer_id;
                DB::afterCommit(fn () => \App\Support\AppShellData::forgetForCustomerId($customerId));
            });
        }
        Gate::policy(Payment::class, PaymentPolicy::class);
        Event::listen(PaymentConfirmed::class, RunPaymentFulfillment::class);

        $this->app->singleton('cloudfront', function () {
            return $this->hetzner_S3_domain;
        });

        $this->app->singleton('logo_57', function () {
            return '/app/logo/logo_icon_xml/57.png';
        });
        $this->app->singleton('logo_72', function () {
            return '/app/logo/logo_icon_xml/72.png';
        });
        $this->app->singleton('logo_114', function () {
            return '/app/logo/logo_icon_xml/114.png';
        });
        $this->app->singleton('logo_144', function () {
            return '/app/logo/logo_icon_xml/144.png';
        });
        $this->app->singleton('logo_1024', function () {
            return '/app/logo/logo_icon_xml/1024.png';
        });
        $this->app->singleton('logo_1024_tran', function () {
            return '/app/logo/black_logo.png';
        });

        $this->app->singleton('logo_57_dark', function () {
            return '/app/logo/logo_icon_xml/57.png';
        });
        $this->app->singleton('logo_72_dark', function () {
            return '/app/logo/logo_icon_xml/72.png';
        });
        $this->app->singleton('logo_114_dark', function () {
            return '/app/logo/logo_icon_xml/114.png';
        });
        $this->app->singleton('logo_144_dark', function () {
            return '/app/logo/logo_icon_xml/144.png';
        });
        $this->app->singleton('logo_1024_dark', function () {
            return '/app/logo/logo_icon_xml/1024.png';
        });
        $this->app->singleton('logo_1024_tran_black', function () {
            return '/app/logo/white_logo.png';
        });

        $this->app->singleton('userImg', function () {
            return $this->hetzner_S3_domain.'web-setting/users/user.png';
        });
        $this->app->singleton('whatsapp-logo', function () {
            return asset('app/images/icons/whats.png');
        });
        $this->app->singleton('telegram-logo', function () {
            return asset('app/images/icons/tele.png');
        });
        $this->app->singleton('sms-logo', function () {
            return asset('app/images/icons/sms.png');
        });
        $this->app->singleton('glocales', function () {
            return config('app.locales');
        });
        $this->app->singleton('g_review', function () {
            return asset('app/logo/google_review.png');
        });

        $this->app->singleton('aurl', function () {
            return 'adm';
        });
    }

    /**
     * Register stable absolute Livewire endpoints.
     *
     * The default hashed endpoints can resolve poorly behind localized /
     * prefixed routing setups, which leads to 404s on component updates.
     */
    protected function configureLivewireRoutes(): void
    {
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/livewire/update', $handle)
                ->middleware(['web', LocalizationMainMiddleware::class])
                ->name('custom');
        });

        Livewire::setScriptRoute(function ($handle) {
            return Route::get('/livewire/livewire.js', $handle)
                ->name('custom');
        });

        Livewire::addPersistentMiddleware([
            LocalizationMainMiddleware::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
