<?php

use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\App\Auth\AppAuthController;
use App\Http\Controllers\App\Auth\SocialAuthController;
use App\Http\Controllers\App\Services\CloneXttsRenderController;
use App\Http\Controllers\App\Services\F5ttsRenderController;
use App\Http\Controllers\App\Services\F5ttsSpeakerAssetController;
use App\Http\Controllers\App\Services\OcrRenderController;
use App\Http\Controllers\App\Services\CaptionRenderController;
use App\Http\Controllers\App\Services\QasrRenderController;
use App\Http\Controllers\App\Services\StemRenderController;
use App\Http\Controllers\App\Services\TranRenderController;
use App\Http\Controllers\App\Services\WasrRenderController;
use App\Http\Controllers\App\Services\XttsRenderController;
use App\Http\Controllers\App\Services\XttsSpeakerAssetController;
use App\Http\Controllers\App\Services\YoutubeRenderController;
use App\Http\Controllers\Payments\AreebaWebhookController;
use App\Http\Controllers\Payments\FibCallbackController;
use App\Http\Controllers\Payments\FibPaymentController;
use App\Http\Controllers\Payments\FibSubscriptionCallbackController;
use App\Http\Controllers\Landing\PublicLandingMediaController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
Route::get('/up', fn () => response()->json(['status' => 'ok'], 200));

Route::get('sitemap.xml', function () {
    return response()->file(public_path('sitemap.xml'));
});
Route::post('/set-locale', [LocalizationMainMiddleware::class, 'setLocale'])->name('setLocale');
Route::get('/media/web/{path}', PublicLandingMediaController::class)
    ->where('path', '.*')
    ->name('landing.media.web');

Route::prefix('payments/webhooks')->group(function () {
    Route::post('/fib', FibCallbackController::class)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->name('payments.fib.callback');
    Route::post('/fib/subscription', FibSubscriptionCallbackController::class)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->name('payments.fib.subscription.callback');
    Route::post('/areeba', AreebaWebhookController::class)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->name('payments.webhooks.areeba');
});

require __DIR__.'/landing.php';

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
        Route::livewire('/services/tools', 'admin::pages.services.adm-services-tools')->name('admin.services.tools');
        Route::get('/services/rules', fn (string $locale) => redirect()->route('admin.services.voices', ['locale' => $locale]))
            ->name('admin.services.rules');
        Route::livewire('/services/voices', 'admin::pages.services.adm-services-voices')->name('admin.services.voices');
        Route::livewire('/services/pricing', 'admin::pages.services.adm-services-pricing')->name('admin.services.pricing');
        Route::livewire('/services/entitlements', 'admin::pages.services.adm-services-entitlements')->name('admin.services.entitlements');
        Route::livewire('/landing/translations', 'admin::pages.landing.adm-landing-translations')->name('admin.landing.translations');
        Route::livewire('/landing/tools', 'admin::pages.landing.adm-landing-tools')->name('admin.landing.tools');
        Route::livewire('/landing/contact', 'admin::pages.landing.adm-landing-contact')->name('admin.landing.contact');
        Route::livewire('/landing/meta-settings', 'admin::pages.landing.adm-landing-meta-settings')->name('admin.landing.meta');
        Route::livewire('/customers/list', 'admin::pages.customers.adm-customers-list')->name('admin.customers.list');
        Route::livewire('/customers/ranking', 'admin::pages.customers.adm-customers-ranking')->name('admin.customers.ranking');
        Route::livewire('/customers/register', 'admin::pages.customers.adm-customers-register')->name('admin.customers.register');    
        Route::livewire('/customers/phone-countries', 'admin::pages.customers.adm-customers-phone-countries')->name('admin.customers.phone-countries');
        Route::livewire('/customers/usage', 'admin::pages.customers.adm-customers-usage')->name('admin.customers.usage');
        Route::livewire('/customers/suspended', 'admin::pages.customers.adm-customers-suspended')->name('admin.customers.suspended');
        Route::livewire('/packs/plans', 'admin::pages.payments.adm-payments-plans')->name('admin.payments.plans');
        Route::livewire('/packs/addons', 'admin::pages.payments.adm-payments-addons')->name('admin.payments.addons');
        Route::livewire('/packs/storage', 'admin::pages.payments.adm-payments-storages')->name('admin.payments.storage');
        Route::livewire('/packs/coupons', 'admin::pages.payments.adm-payments-coupons')->name('admin.payments.coupons');
        Route::livewire('/packs/methods', 'admin::pages.payments.adm-payments-methods')->name('admin.payments.methods');
        Route::livewire('/packs/currencies', 'admin::pages.payments.adm-payments-currencies')->name('admin.payments.currencies');
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
Route::middleware(['auth:app', 'app.active', LocalizationMainMiddleware::class])
    ->group(function () {
        // account state
        Route::livewire('/app/email', 'app::auth.email-otp')->name('app.email.otp');
        Route::livewire('/app/phone', 'app::auth.phone-otp')->name('app.phone.otp');
        Route::livewire('/app/suspended-301', 'app::auth.suspend-one')->name('app.suspended');
});
Route::prefix('{locale}')
    ->middleware(['auth:app', 'app.active', 'app.verified', LocalizationMainMiddleware::class])
    ->group(function () {
        // pages
        Route::livewire('/app/home', 'app::pages.home.app-home')->name('app.home');
        Route::livewire('/app/profile', 'app::pages.profile.app-profile')->name('app.profile');
        Route::livewire('/app/my-storage', 'app::pages.my-storage.app-storage')->name('app.storage');
        Route::livewire('/app/my-billing', 'app::pages.billing.app-billing')->name('app.billing');

        Route::livewire('/app/xtts', 'app::pages.xtts.app-xtts')
            ->middleware('app.tool.access:tts.standard')
            ->name('app.xtts');
        Route::livewire('/app/f5tts', 'app::pages.f5tts.app-f5tts')
            ->middleware('app.tool.access:ftts.standard')
            ->name('app.f5tts');
        Route::livewire('/app/clone-xtts', 'app::pages.clone-xtts.app-clone-xtts')
            ->middleware('app.tool.access:clone_tts.standard')
            ->name('app.clone-xtts');
        Route::livewire('/app/wasr', 'app::pages.wasr.app-wasr')
            ->middleware('app.tool.access:asr.standard')
            ->name('app.wasr');
        Route::livewire('/app/qasr', 'app::pages.qasr.app-qasr')
            ->middleware('app.tool.access:qasr.standard')
            ->name('app.qasr');
        Route::livewire('/app/caption', 'app::pages.qasr.app-caption')
            ->middleware('app.tool.access:caption.standard')
            ->name('app.caption');
        Route::livewire('/app/tran', 'app::pages.tran.app-tran')
            ->middleware('app.tool.access:tran.standard')
            ->name('app.tran');
        Route::livewire('/app/stem', 'app::pages.stem.app-stem')
            ->middleware('app.tool.access:stem')
            ->name('app.stem');
        Route::livewire('/app/ocr', 'app::pages.ocr.app-ocr')
            ->middleware('app.tool.access:ocr.standard')
            ->name('app.ocr');
        Route::livewire('/app/youtube', 'app::pages.youtube.app-youtube-downloader')
            ->middleware('app.tool.access:any,youtube_audio,youtube_video')
            ->name('app.youtube');

/*
|--------------------------------------------------------------------------
| Billing Route
|--------------------------------------------------------------------------
*/
    Route::livewire('/app/subscription-plans', 'app::pages.subscription-plan.subscription-plan')->name('subscription-plan');
    Route::livewire('/app/storage-plans', 'app::pages.storage-plan.storage-plan')->name('storage-plan');
    Route::livewire('/app/addon-credits', 'app::pages.addon-credits.addon-credits')->name('addon-credits');
    Route::livewire('/app/payments/fib/{payment}', 'app::pages.payments.fib-payment')->name('payments.fib.show');
    Route::get('/app/payments/fib/{payment}/status', [FibPaymentController::class, 'status'])->name('payments.fib.status');
    Route::post('/app/payments/fib/{payment}/refresh', [FibPaymentController::class, 'refresh'])->name('payments.fib.refresh');
    Route::post('/app/payments/fib/{payment}/cancel', [FibPaymentController::class, 'cancel'])->name('payments.fib.cancel');
    Route::get('/app/payments/fib/{payment}/thank-you', [FibPaymentController::class, 'thankYou'])->name('payments.fib.thank-you');

/*
|--------------------------------------------------------------------------
| ML Stream - Download
|--------------------------------------------------------------------------
*/
    Route::get('/app/renders/xtts/{jobId}/stream', [XttsRenderController::class, 'stream'])
        ->middleware('app.tool.access:tts')
        ->name('app.renders.xtts.stream');

    Route::get('/app/renders/xtts/{jobId}/download', [XttsRenderController::class, 'download'])
        ->middleware('app.tool.access:tts')
        ->name('app.renders.xtts.download');

    Route::get('/app/renders/f5tts/{jobId}/stream', [F5ttsRenderController::class, 'stream'])
        ->middleware('app.tool.access:ftts')
        ->name('app.renders.f5tts.stream');

    Route::get('/app/renders/f5tts/{jobId}/download', [F5ttsRenderController::class, 'download'])
        ->middleware('app.tool.access:ftts')
        ->name('app.renders.f5tts.download');

    Route::get('/app/xtts/speakers/{voiceCode}/preview', [XttsSpeakerAssetController::class, 'preview'])
        ->middleware('app.tool.access:tts')
        ->name('app.xtts.speaker.preview');

    Route::get('/app/xtts/speakers/{voiceCode}/avatar', [XttsSpeakerAssetController::class, 'avatar'])
        ->middleware('app.tool.access:tts')
        ->name('app.xtts.speaker.avatar');

    Route::get('/app/f5tts/speakers/{voiceCode}/preview', [F5ttsSpeakerAssetController::class, 'preview'])
        ->middleware('app.tool.access:ftts')
        ->name('app.f5tts.speaker.preview');

    Route::get('/app/f5tts/speakers/{voiceCode}/avatar', [F5ttsSpeakerAssetController::class, 'avatar'])
        ->middleware('app.tool.access:ftts')
        ->name('app.f5tts.speaker.avatar');

    Route::get('/app/renders/clone-xtts/{jobId}/stream', [CloneXttsRenderController::class, 'Stream'])
        ->middleware('app.tool.access:clone_tts')
        ->name('app.renders.clone_xtts.stream');

    Route::get('/app/renders/clone-xtts/{jobId}/download', [CloneXttsRenderController::class, 'Download'])
        ->middleware('app.tool.access:clone_tts')
        ->name('app.renders.clone_xtts.download');

    Route::get('/app/renders/wasr/{jobId}/txt', [WasrRenderController::class, 'downloadTxt'])
        ->middleware('app.tool.access:asr')
        ->name('app.renders.wasr.txt');
        
    Route::get('/app/renders/wasr/{jobId}/json', [WasrRenderController::class, 'downloadJson'])
        ->middleware('app.tool.access:asr')
        ->name('app.renders.wasr.json');

    Route::get('/app/renders/wasr/{jobId}/input-audio', [WasrRenderController::class, 'inputAudio'])
        ->middleware('app.tool.access:asr')
        ->name('app.renders.wasr.input-audio');

    Route::get('/app/renders/qasr/{jobId}/txt', [QasrRenderController::class, 'downloadTxt'])
        ->middleware('app.tool.access:qasr.standard')
        ->name('app.renders.qasr.txt');

    Route::get('/app/renders/qasr/{jobId}/json', [QasrRenderController::class, 'downloadJson'])
        ->middleware('app.tool.access:qasr.standard')
        ->name('app.renders.qasr.json');

    Route::get('/app/renders/qasr/{jobId}/input-audio', [QasrRenderController::class, 'inputAudio'])
        ->middleware('app.tool.access:qasr.standard')
        ->name('app.renders.qasr.input-audio');

    Route::get('/app/renders/caption/{jobId}/txt', [CaptionRenderController::class, 'downloadTxt'])
        ->middleware('app.tool.access:caption.standard')
        ->name('app.renders.caption.txt');

    Route::get('/app/renders/caption/{jobId}/srt', [CaptionRenderController::class, 'downloadSrt'])
        ->middleware('app.tool.access:caption.standard')
        ->name('app.renders.caption.srt');

    Route::get('/app/renders/caption/{jobId}/json', [CaptionRenderController::class, 'downloadJson'])
        ->middleware('app.tool.access:caption.standard')
        ->name('app.renders.caption.json');

    Route::get('/app/renders/caption/{jobId}/input-audio', [CaptionRenderController::class, 'inputAudio'])
        ->middleware('app.tool.access:caption.standard')
        ->name('app.renders.caption.input-audio');

    Route::get('/app/renders/tran/{jobId}/source', [TranRenderController::class, 'downloadSource'])
        ->middleware('app.tool.access:tran.standard')
        ->name('app.renders.tran.source');

    Route::get('/app/renders/tran/{jobId}/target', [TranRenderController::class, 'downloadTarget'])
        ->middleware('app.tool.access:tran.standard')
        ->name('app.renders.tran.target');

    Route::get('/app/renders/stem/{jobId}/stream/{track}', [StemRenderController::class, 'stream'])
        ->middleware('app.tool.access:stem')
        ->name('app.renders.stem.stream');

    Route::get('/app/renders/stem/{jobId}/download/{track}', [StemRenderController::class, 'download'])
        ->middleware('app.tool.access:stem')
        ->name('app.renders.stem.download');

    Route::get('/app/renders/stem/{jobId}/zip', [StemRenderController::class, 'zip'])
        ->middleware('app.tool.access:stem')
        ->name('app.renders.stem.zip');

    Route::get('/app/renders/stem/{jobId}/payload', [StemRenderController::class, 'payload'])
        ->middleware('app.tool.access:stem')
        ->name('app.renders.stem.payload');

    Route::get('/app/renders/ocr/{jobId}/txt', [OcrRenderController::class, 'downloadText'])
        ->middleware('app.tool.access:ocr')
        ->name('app.renders.ocr.text');

    Route::get('/app/renders/ocr/{jobId}/txt/view', [OcrRenderController::class, 'viewText'])
        ->middleware('app.tool.access:ocr')
        ->name('app.renders.ocr.text.view');

    Route::get('/app/renders/ocr/{jobId}/json', [OcrRenderController::class, 'downloadJson'])
        ->middleware('app.tool.access:ocr')
        ->name('app.renders.ocr.json');

    Route::get('/app/renders/ocr/{jobId}/json/view', [OcrRenderController::class, 'viewJson'])
        ->middleware('app.tool.access:ocr')
        ->name('app.renders.ocr.json.view');

    Route::get('/app/renders/ocr/{jobId}/input', [OcrRenderController::class, 'inputDocument'])
        ->middleware('app.tool.access:ocr')
        ->name('app.renders.ocr.input');

    Route::get('/app/renders/ocr/{jobId}/payload', [OcrRenderController::class, 'payload'])
        ->middleware('app.tool.access:ocr')
        ->name('app.renders.ocr.payload');

    Route::get('/app/renders/youtube/{jobId}/download', [YoutubeRenderController::class, 'download'])
        ->middleware('app.tool.access:any,youtube_audio,youtube_video')
        ->name('app.renders.youtube.download');
    // Route::get('/app/renders/wasr/{jobId}/json/view', [WasrRenderController::class, 'viewJson'])
    //     ->name('app.renders.wasr.json.view');
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
