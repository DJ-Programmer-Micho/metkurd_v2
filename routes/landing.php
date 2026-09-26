<?php

use App\Http\Controllers\Landing\LandingToolVoiceAssetController;
use App\Http\Controllers\LawController;
use App\Http\Middleware\LocalizationMainMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('law/terms-conditions', [LawController::class, 'termsCondition'])->name('law.terms');
Route::get('law/privacy-policy', [LawController::class, 'privacyPolicy'])->name('law.privacy');

Route::get('/', function () {
    $locale = session('applocale', config('app.locale'));

    if (! in_array($locale, ['en', 'ar', 'ku'], true)) {
        $locale = config('app.locale');
    }

    if (auth('admin')->check()) {
        return redirect()->route('admin.home', ['locale' => $locale]);
    }

    if (auth('app')->check()) {
        return redirect()->to(\App\Support\CustomerAppDestination::home($locale));
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
        Route::get('/tools/demos/voices/{voiceCode}/preview', [LandingToolVoiceAssetController::class, 'preview'])
            ->where(['voiceCode' => '[A-Za-z0-9_-]+'])
            ->name('landing.tools.demo.voice.preview');
        Route::get('/tools/demos/voices/{voiceCode}/avatar', [LandingToolVoiceAssetController::class, 'avatar'])
            ->where(['voiceCode' => '[A-Za-z0-9_-]+'])
            ->name('landing.tools.demo.voice.avatar');
        Route::livewire('/pricing', 'landing::pages.pricing')->name('landing.pricing');
        Route::livewire('/contact', 'landing::pages.contact')->name('landing.contact');
        Route::livewire('/metkurd-ai-overview', 'landing::pages.overview')->name('landing.overview');
        Route::livewire('/research-development', 'landing::pages.research-development')->name('landing.research-development');
        Route::livewire('/kurdish-ai-challenges', 'landing::pages.kurdish-ai-challenges')->name('landing.kurdish-ai-challenges');
        Route::livewire('/how-metkurd-ai-was-built', 'landing::pages.how-built')->name('landing.how-built');
        Route::livewire('/privacy', 'landing::pages.privacy')->name('landing.privacy');
        Route::livewire('/terms', 'landing::pages.terms')->name('landing.terms');
    });
