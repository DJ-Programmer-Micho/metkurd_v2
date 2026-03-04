<?php

use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\Pages\AdminController;
use App\Http\Controllers\App\Auth\AppAuthController;
use App\Http\Controllers\App\Auth\SocialAuthController;
use App\Http\Controllers\App\Pages\AppController;
use App\Http\Middleware\LocalizationMainMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sitemaps
|--------------------------------------------------------------------------
| If sitemap files exist in /public, serve them directly instead of redirecting.
| Redirecting to the same URL creates an infinite loop.
|--------------------------------------------------------------------------
*/
// Route::get('/sitemap_en.xml', fn () => response()->file(public_path('sitemap_en.xml')));
// Route::get('/sitemap_ar.xml', fn () => response()->file(public_path('sitemap_ar.xml')));
// Route::get('/sitemap_ku.xml', fn () => response()->file(public_path('sitemap_ku.xml')));

Route::post('/set-locale', [LocalizationMainMiddleware::class, 'setLocale'])->name('setLocale');

/*
|--------------------------------------------------------------------------
| Admin Auth (guest)
|--------------------------------------------------------------------------
*/
Route::middleware('guest:admin')->group(function () {
    Route::livewire('/'.app('aurl').'/signin', 'admin::auth.signin-one')->name('admin.signin');
});

/*
|--------------------------------------------------------------------------
| Admin (auth) - localized
|--------------------------------------------------------------------------
*/
Route::prefix('{locale}/'.app('aurl'))
    ->middleware(['auth:admin', LocalizationMainMiddleware::class])
    ->group(function () {
        Route::livewire('/home', 'admin::pages.home.app-home')->name('admin.home');
    });

    Route::middleware('auth:admin')->group(function () {
    Route::post('/'.app('aurl').'/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');
});
/*
|--------------------------------------------------------------------------
| App Auth (guest) - NOT localized (simple)
|--------------------------------------------------------------------------
*/
Route::middleware('guest:app')->group(function () {
    Route::livewire('/app/signin', 'app::auth.signin-one')->name('app.signin');
    Route::livewire('/app/register', 'app::auth.signup-one')->name('app.signup');
    Route::livewire('/forgot-password', 'app::auth.forgot-password')->name('app.password.request');
    

    Route::get('/auth/google/redirect', [SocialAuthController::class, 'googleRedirect'])->name('social.google.redirect');
    Route::get('/auth/google/callback', [SocialAuthController::class, 'googleCallback'])->name('social.google.callback');

    Route::get('/auth/github/redirect', [SocialAuthController::class, 'githubRedirect'])->name('social.github.redirect');
    Route::get('/auth/github/callback', [SocialAuthController::class, 'githubCallback'])->name('social.github.callback');
});
Route::livewire('/reset-password/{token}', 'app::auth.reset-password')->name('app.password.reset.form');

Route::middleware('auth:app')->group(function () {
    Route::post('/app/logout', [AppAuthController::class, 'logout'])->name('app.logout');
});

Route::get('/password/reset', fn () => redirect()->route('app.password.request'))->name('password.request');
Route::get('/password/reset/{token}', fn (string $token) =>
    redirect()->route('app.password.reset.form', [
        'token' => $token,
        'email' => request()->query('email', ''),
    ])
)->name('password.reset');

// When it AUTHENTICATED, the user can access the password reset form (with token) but not request a reset link (without token).
Route::middleware('auth:app')->group(function () {
    Route::livewire('/app/password/email', 'app::auth.password-email')
        ->name('app.password.email');
});
/*
|--------------------------------------------------------------------------
| App (auth) - localized (THIS is your main app)
|--------------------------------------------------------------------------
*/
Route::prefix('{locale}')
    ->middleware(['auth:app', 'app.active', LocalizationMainMiddleware::class])
    ->group(function () {

        // account state
        Route::livewire('/app/email', 'app::auth.email-otp')->name('app.email.otp');
        Route::livewire('/app/phone', 'app::auth.phone-otp')->name('app.phone.otp');
        Route::livewire('/app/suspended-301', 'app::auth.suspend-one')->name('app.suspended');


        // pages
        Route::livewire('/app/home', 'app::pages.home.app-home')->name('app.home');
        Route::livewire('/app/profile', 'app::pages.profile.app-profile')->name('app.profile');

        Route::livewire('/app/xtts', 'app::pages.xtts.app-xtts')->name('app.xtts');
        Route::livewire('/app/clone-xtts', 'app::pages.clone-xtts.app-clone-xtts')->name('app.clone_xtts');
        Route::livewire('/app/wasr', 'app::pages.wasr.app-wasr')->name('app.wasr');
        Route::livewire('/app/stem', 'app::pages.stem.app-stem')->name('app.stem');
        Route::livewire('/app/ocr', 'app::pages.ocr.app-ocr')->name('app.ocr');

        // feature gates (same as old)
        // Route::livewire('/app/xtts', 'app::pages.xtts.app-xtts')
        //     ->middleware('feature:tts.active')
        //     ->name('app.xtts');
    });
/*
|--------------------------------------------------------------------------
| Localized App (auth)
|--------------------------------------------------------------------------
| Everything that uses route('...',['locale'=>...]) MUST be inside this group.
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Landing
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => view('welcome'));





// Route::prefix('admin')->name('admin.')->group(function () {
//     Route::middleware('guest:admin')->group(function () {
//         Route::get('/signin', ...)->name('signin');
//     });

//     Route::middleware('auth:admin')->group(function () {
//         Route::get('/dashboard', ...)->name('dashboard');
//     });
// });

// Route::prefix('app')->name('app.')->group(function () {
//     Route::middleware('guest:app')->group(function () {
//         Route::get('/signin', ...)->name('signin');
//     });

//     Route::middleware('auth:app')->group(function () {
//         Route::get('/dashboard', ...)->name('dashboard');
//     });
// });