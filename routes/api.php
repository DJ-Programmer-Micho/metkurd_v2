<?php

use App\Http\Controllers\Api\Customer\V1\AsrController;
use App\Http\Controllers\Api\Customer\V1\CaptionController;
use App\Http\Controllers\Api\Customer\V1\FileController;
use App\Http\Controllers\Api\Customer\V1\JobController;
use App\Http\Controllers\Api\Customer\V1\MeController;
use App\Http\Controllers\Api\Customer\V1\OcrController;
use App\Http\Controllers\Api\Customer\V1\StemController;
use App\Http\Controllers\Api\Customer\V1\TranslateController;
use App\Http\Controllers\Api\Customer\V1\TtsController;
use App\Http\Controllers\Api\Customer\V1\UsageController;
use App\Http\Controllers\Api\Mobile\Auth\MobileAuthController;
use App\Http\Controllers\Api\Mobile\Auth\MobilePhoneVerificationController;
use App\Http\Controllers\Api\Mobile\Auth\MobileSocialAuthController;
use App\Http\Controllers\Api\Mobile\MobileAccountUsageController;
use App\Http\Controllers\Api\Mobile\MobileFilesController;
use App\Http\Controllers\Api\Mobile\MobileJobsController;
use App\Http\Controllers\Api\Mobile\MobileTtsVoicesController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->name('api.mobile.')->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/login', [MobileAuthController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('login');

        Route::post('/social/{provider}', [MobileSocialAuthController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('social');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/phone', [MobilePhoneVerificationController::class, 'savePhone'])
                ->middleware('throttle:10,1')
                ->name('phone.save');
            Route::post('/phone/otp/send', [MobilePhoneVerificationController::class, 'sendOtp'])
                ->middleware('throttle:10,1')
                ->name('phone.otp.send');
            Route::post('/phone/otp/verify', [MobilePhoneVerificationController::class, 'verifyOtp'])
                ->middleware('throttle:12,1')
                ->name('phone.otp.verify');

            Route::get('/me', [MobileAuthController::class, 'me'])->name('me');
            Route::post('/logout', [MobileAuthController::class, 'logout'])->name('logout');
            Route::get('/apps', [MobileAuthController::class, 'apps'])->name('apps');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/tts/voices', [MobileTtsVoicesController::class, 'index'])
            ->name('tts.voices.index');
        Route::get('/tts/voices/{speakerId}/avatar', [MobileTtsVoicesController::class, 'avatar'])
            ->where(['speakerId' => '[A-Za-z0-9_-]+'])
            ->name('tts.voices.avatar');
        Route::get('/tts/voices/{speakerId}/preview', [MobileTtsVoicesController::class, 'preview'])
            ->where(['speakerId' => '[A-Za-z0-9_-]+'])
            ->name('tts.voices.preview');
        Route::get('/account/usage', [MobileAccountUsageController::class, 'show'])
            ->name('account.usage');

        Route::prefix('{app}')
            ->where(['app' => 'tts|ctts|asr|stem|ocr|tran'])
            ->name('apps.')
            ->group(function () {
                Route::get('/jobs', [MobileJobsController::class, 'index'])->name('jobs.index');
                Route::post('/jobs', [MobileJobsController::class, 'store'])
                    ->middleware('throttle:20,1')
                    ->name('jobs.store');
                Route::get('/jobs/{jobId}', [MobileJobsController::class, 'show'])
                    ->whereUuid('jobId')
                    ->name('jobs.show');

                Route::get('/files', [MobileFilesController::class, 'index'])->name('files.index');
                Route::post('/files/upload', [MobileFilesController::class, 'store'])
                    ->middleware('throttle:20,1')
                    ->name('files.store');
                Route::get('/files/{fileId}', [MobileFilesController::class, 'show'])
                    ->whereNumber('fileId')
                    ->name('files.show');
                Route::get('/files/{fileId}/download', [MobileFilesController::class, 'download'])
                    ->whereNumber('fileId')
                    ->name('files.download');
            });
    });
});

Route::prefix('v1')
    ->name('api.customer.v1.')
    ->middleware([
        'customer.api',
        'customer.api.access',
        'customer.api.rate_limit',
    ])
    ->group(function () {
        Route::get('/me', MeController::class)->name('me');
        Route::get('/usage', UsageController::class)->name('usage');

        Route::get('/tts/apollo-1-0v/voices', [TtsController::class, 'apollo10Voices'])->name('tts.apollo10.voices');
        Route::get('/tts/apollo-1-5v/voices', [TtsController::class, 'apollo15Voices'])->name('tts.apollo15.voices');
        Route::get('/tts/delta-1-0v/voices', [TtsController::class, 'delta10Voices'])->name('tts.delta10.voices');

        Route::get('/tts/xtts/voices', [TtsController::class, 'xttsVoices'])->name('tts.aliases.xtts.voices');
        Route::get('/tts/xomni/voices', [TtsController::class, 'xomniVoices'])->name('tts.aliases.xomni.voices');
        Route::get('/tts/f5tts/voices', [TtsController::class, 'f5ttsVoices'])->name('tts.aliases.f5tts.voices');

        Route::middleware('customer.api.concurrency')->group(function () {
            Route::post('/tts/apollo-1-0v', [TtsController::class, 'apollo10'])->name('tts.apollo10.submit');
            Route::post('/tts/apollo-1-5v', [TtsController::class, 'apollo15'])->name('tts.apollo15.submit');
            Route::post('/tts/delta-1-0v', [TtsController::class, 'delta10'])->name('tts.delta10.submit');
            Route::post('/tts/vector-1-0', [TtsController::class, 'vector10'])->name('tts.vector10.submit');
            Route::post('/tts/vector-1-5', [TtsController::class, 'vector15'])->name('tts.vector15.submit');

            Route::post('/tts/xtts', [TtsController::class, 'xtts'])->name('tts.aliases.xtts.submit');
            Route::post('/tts/xomni', [TtsController::class, 'xomni'])->name('tts.aliases.xomni.submit');
            Route::post('/tts/f5tts', [TtsController::class, 'f5tts'])->name('tts.aliases.f5tts.submit');
            Route::post('/tts/clone-xtts', [TtsController::class, 'cloneXtts'])->name('tts.aliases.clone_xtts.submit');
            Route::post('/tts/clone-xomni', [TtsController::class, 'cloneXomni'])->name('tts.aliases.clone_xomni.submit');

            Route::post('/asr/wasr', [AsrController::class, 'wasr'])->name('asr.wasr.submit');
            Route::post('/asr/qasr', [AsrController::class, 'qasr'])->name('asr.qasr.submit');
            Route::post('/caption/qasr', [CaptionController::class, 'qasr'])->name('caption.qasr.submit');
            Route::post('/ocr', OcrController::class)->name('ocr.submit');
            Route::post('/translate', TranslateController::class)->name('translate.submit');
            Route::post('/stem', StemController::class)->name('stem.submit');
        });

        Route::get('/jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
        Route::get('/files/{file}/download', [FileController::class, 'download'])->name('files.download');
    });

// Native V2 public API. The portal's locale belongs to web routes, not this namespace.
Route::prefix('v2')->name('api.customer.v2.')->middleware([
    \App\Http\Middleware\ApiV2Boundary::class,
    'customer.api', 'customer.api.rate_limit',
])->group(function () {
    Route::get('/services', [\App\Http\Controllers\Api\Customer\V2\ApiController::class, 'services'])->name('services');
    Route::get('/voices', [\App\Http\Controllers\Api\Customer\V2\ApiController::class, 'voices'])->name('voices');
    Route::get('/jobs/{id}', [\App\Http\Controllers\Api\Customer\V2\ApiController::class, 'show'])->name('jobs.show');
    Route::get('/files/{id}/download', [\App\Http\Controllers\Api\Customer\V2\ApiController::class, 'download'])->name('files.download');
    Route::post('/{service}', [\App\Http\Controllers\Api\Customer\V2\ApiController::class, 'submit'])
        ->whereIn('service', \App\Services\CustomerApi\V2\ApiCatalog::SERVICES)->name('submit');
});
