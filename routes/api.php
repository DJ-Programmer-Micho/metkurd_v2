<?php

use App\Http\Controllers\Api\Mobile\Auth\MobileAuthController;
use App\Http\Controllers\Api\Mobile\Auth\MobileSocialAuthController;
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
            ->where(['provider' => 'google|github'])
            ->middleware('throttle:10,1')
            ->name('social');

        Route::middleware('auth:sanctum')->group(function () {
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
