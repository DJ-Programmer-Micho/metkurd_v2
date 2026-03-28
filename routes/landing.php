<?php

use App\Http\Middleware\LocalizationMainMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $locale = session('applocale', config('app.locale'));

    if (! in_array($locale, ['en', 'ar', 'ku'], true)) {
        $locale = config('app.locale');
    }

    if (auth('admin')->check()) {
        return redirect()->route('admin.home', ['locale' => $locale]);
    }

    if (auth('app')->check()) {
        return redirect()->route('app.home', ['locale' => $locale]);
    }

    return redirect()->route('landing.home', ['locale' => $locale]);
})->name('landing.redirect');

Route::prefix('{locale}')
    ->where(['locale' => 'en|ar|ku'])
    ->middleware([LocalizationMainMiddleware::class])
    ->group(function () {
        Route::livewire('/', 'landing::pages.home')->name('landing.home');
        Route::livewire('/home', 'landing::pages.home')->name('landing.home.localized');
        Route::livewire('/tools', 'landing::pages.tools')->name('landing.tools');
        Route::livewire('/tools/{slug}', 'landing::pages.tool-detail')->name('landing.tools.show');
        Route::livewire('/pricing', 'landing::pages.pricing')->name('landing.pricing');
        Route::livewire('/contact', 'landing::pages.contact')->name('landing.contact');
        Route::livewire('/privacy', 'landing::pages.privacy')->name('landing.privacy');
        Route::livewire('/terms', 'landing::pages.terms')->name('landing.terms');
    });
