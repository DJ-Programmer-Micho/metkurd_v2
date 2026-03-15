<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Blaze\Blaze;

class AppServiceProvider extends ServiceProvider
{
    public $aws_clountfront_domain = 'https://d1h4q8vrlfl3k9.cloudfront.net/';
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Blaze::optimize()->in(
        //     resource_path('views/app'),
        //     fold: true,

        //     );

        $this->configureDefaults();
                $this->app->singleton('cloudfront', function () {
            return $this->aws_clountfront_domain;
        });
        
        $this->app->singleton('logo_57', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_72', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_114', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_144', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_1024', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_1024_tran', function () {
            return  "/app/logo/black_logo.png";
        });

        $this->app->singleton('logo_57_dark', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_72_dark', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_114_dark', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_144_dark', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_1024_dark', function () {
            return  "/app/logo/logo_icon.png";
        });
        $this->app->singleton('logo_1024_tran_black', function () {
            return  "/app/logo/white_logo.png";
        });

        $this->app->singleton('userImg', function () {
            return $this->aws_clountfront_domain.'users/user.png';
        });
        $this->app->singleton('whatsapp-logo', function () {
            return $this->aws_clountfront_domain.'web-setting/social-icons/whats.png';
        });
        $this->app->singleton('telegram-logo', function () {
            return $this->aws_clountfront_domain.'web-setting/social-icons/tele.png';
        });
        $this->app->singleton('sms-logo', function () {
            return $this->aws_clountfront_domain.'web-setting/social-icons/sms.png';
        });
        $this->app->singleton('glocales', function () {
            return config('app.locales'); 
        });

        $this->app->singleton('aurl', function () {
            return  "adm";
        });
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
