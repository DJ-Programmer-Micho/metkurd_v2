<?php

use App\Models\Customer;
use App\Services\Media\AudioProbeService;
use App\Services\MetKurd\V2\InputBoundary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('keeps rendered upload limits and real workspace validation on the same exact byte boundary', function (string $page, array $params, string $property) {
    $this->seed();
    config(['metkurd_v2.enabled' => true]);
    $customer = Customer::create(['username' => 'size-test', 'email' => 'size@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $component = Livewire::actingAs($customer, 'app')->test('app::v2.pages.tools.'.$page, $params);
    $rule = $component->instance()->getRules()[$property];
    $isDocument = $property === 'documentFile';
    if (! $isDocument) {
        $component->assertSee('data-max-upload-kib="'.InputBoundary::AUDIO_MAX_KIB.'"', false)
            ->assertSee('Maximum file size is 100 MiB');
    }
    foreach ([95 * 1048576, 99 * 1048576, 104857600, 104857601] as $bytes) {
        $file = UploadedFile::fake()->create($isDocument ? 'document.pdf' : 'audio.wav', 0, $isDocument ? 'application/pdf' : 'audio/wav');
        $file->sizeToReport = $bytes;
        expect(Validator::make([$property => $file], [$property => $rule])->passes())->toBe($bytes <= 104857600);
        expect(Validator::make(['file' => $file], ['file' => config('livewire.temporary_file_upload.rules')])->passes())->toBe($bytes <= 104857600);
    }
})->with([
    ['app-stem', ['mode' => '2'], 'audioFile'],
    ['app-stem', ['mode' => '4'], 'audioFile'],
    ['app-leo', [], 'audioFile'],
    ['app-caption', [], 'audioFile'],
    ['app-ocr', [], 'documentFile'],
]);

it('preserves the native audio boundary before probing and processing', function () {
    $this->mock(AudioProbeService::class)->shouldReceive('probeUploadedFile')->times(3)->andReturn(['duration_sec' => 1]);
    foreach ([95 * 1048576, 99 * 1048576, 104857600, 104857601] as $bytes) {
        $file = UploadedFile::fake()->create('audio.wav', 0, 'audio/wav');
        $file->sizeToReport = $bytes;
        if ($bytes > 104857600) {
            expect(fn () => app(InputBoundary::class)->audio($file))->toThrow(ValidationException::class);
        } else {
            expect(app(InputBoundary::class)->audio($file)['duration_sec'])->toBe(1.0);
        }
    }
});

it('renders unambiguous size errors in every customer locale', function (string $locale) {
    app()->setLocale($locale);
    app()->instance('request', Illuminate\Http\Request::create('/'.$locale.'/app-v2'));
    $html = view('app.v2.components.upload-size', ['maxKib' => InputBoundary::AUDIO_MAX_KIB])->render();
    expect($html)->toContain('data-max-upload-kib="102400"', '100 MiB')->not->toContain(':size');
})->with(['en', 'ar', 'ku']);
