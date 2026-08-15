<?php

use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Providers\RunPodProvider;
use App\Support\MetKurdV2JobStatusPresentation;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    config()->set('metkurd_v2.enabled', true);
});

it('keeps V2 navigation definitions separate from stable machine identifiers', function () {
    $catalog = app(MetKurdV2ToolCatalog::class);

    expect($catalog->tool('text-to-speech', 'apollo-1'))
        ->toMatchArray([
            'legacy_tool' => 'xomni',
            'legacy_action' => 'xomni.generate',
            'endpoint' => 'omni_v2',
            'provider_model' => 'model_1',
        ])
        ->and($catalog->tool('text-to-speech', 'apollo-2'))->toMatchArray([
            'legacy_tool' => 'xomni-v2',
            'legacy_action' => 'xomni-v2.generate',
            'access' => 'xomni-v2.generate',
            'provider_model' => 'model_2',
        ])
        ->and($catalog->tool('speech-to-text', 'leo')['endpoint'])->toBe('qasr_v2')
        ->and($catalog->tool('ocr', 'scanner')['endpoint'])->toBe('kocr_v2')
        ->and($catalog->tool('stem', '2-stem')['stems'])->toBe(2);
});

it('uses the V1 status semantics for V2 job presentation', function () {
    $presenter = app(MetKurdV2JobStatusPresentation::class);

    expect($presenter->for('queued')['semantic'])->toBe('warning')
        ->and($presenter->for('queued')['glass_class'])->toBe('glass-load--warning')
        ->and($presenter->for('running')['semantic'])->toBe('info')
        ->and($presenter->for('running')['glass_class'])->toBe('glass-load--info')
        ->and($presenter->for('saving')['semantic'])->toBe('primary')
        ->and($presenter->for('saving')['glass_class'])->toBe('glass-load--primary')
        ->and($presenter->for('done')['semantic'])->toBe('success')
        ->and($presenter->for('failed')['semantic'])->toBe('danger')
        ->and($presenter->for('cancelled')['semantic'])->toBe('secondary');
});

it('registers isolated V2 routes without changing V1 route names', function () {
    expect(Route::has('app.v2.home'))->toBeTrue()
        ->and(Route::has('app.v2.service'))->toBeTrue()
        ->and(Route::has('app.v2.tool'))->toBeTrue()
        ->and(Route::has('app.home'))->toBeTrue()
        ->and(Route::has('app.renders.xomni-v2.stream'))->toBeTrue()
        ->and(Route::has('app.renders.xomni-v2.download'))->toBeTrue()
        ->and(route('app.v2.tool', ['locale' => 'en', 'service' => 'text-to-speech', 'tool' => 'apollo-2']))
        ->toContain('/en/app-v2/text-to-speech/apollo-2');
});

it('renders V2 root and maps intelligent mode only in the V2 leaf state', function () {
    Livewire::test('app::v2.pages.home.app-home')
        ->assertSee('Text-to-Speech')
        ->assertSee('Clone Text-to-Speech')
        ->assertSee(asset('app/services_icons/TTS.png'))
        ->assertSee(asset('app/logo/white_logo.svg'));

    Livewire::test('app::v2.pages.services.app-service', ['service' => 'text-to-speech'])
        ->assertSee('Apollo 1.5v')
        ->assertSee('Apollo 2.0v')
        ->assertSee('Multi Speaker 1.0v')
        ->assertSee('Coming soon');

    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'ocr', 'tool' => 'scanner'])
        ->assertSee('OCR Scanner 2.0')
        ->assertSee('Standard')
        ->assertSee('Open V1 workspace')
        ->assertSee(route('app.ocr', ['locale' => 'en']))
        ->set('processingMode', 'intelligent')
        ->assertSee('Intelligent');
});

it('maps each available V2 leaf to an existing V1 workspace instead of a second executor', function () {
    $catalog = app(MetKurdV2ToolCatalog::class);

    foreach ($catalog->services() as $service) {
        foreach ($service['tools'] as $tool) {
            if (($tool['coming_soon'] ?? false) === true) {
                continue;
            }

            expect($tool['legacy_route'])->not->toBeEmpty()
                ->and(Route::has($tool['legacy_route']))->toBeTrue();
        }
    }
});

it('builds OMNI V2 provider input from the central model mapping', function () {
    config()->set('runpod.endpoints.omni_v2', 'omni-v2-test');
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')
        ->once()
        ->with('omni-v2-test', Mockery::on(function (array $input): bool {
            return $input['model'] === 'model_2'
                && $input['text'] === 'Hello'
                && $input['return_base64'] === true;
        }), Mockery::type('int'))
        ->andReturn(['id' => 'runpod-job']);
    app()->instance(RunPodProvider::class, $provider);

    $result = app(RunPodV2Adapter::class)->omni('text-to-speech', 'apollo-2', [
        'text' => 'Hello',
        'ref_audio' => 'custom/voice.wav',
    ]);

    expect($result)->toBe(['id' => 'runpod-job']);
});

it('maps intelligent ASR and OCR selections without accepting arbitrary input hosts or paths', function () {
    config()->set('runpod.endpoints.qasr_v2', 'qasr-v2-test');
    config()->set('runpod.endpoints.kocr_v2', 'kocr-v2-test');
    config()->set('runpod.v2_input_hosts', ['storage.example.test']);

    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')
        ->once()
        ->with('qasr-v2-test', Mockery::on(fn (array $input): bool => $input['intelligent'] === 1 && $input['type'] === 'asr'), Mockery::type('int'))
        ->andReturn(['id' => 'qasr-job']);
    $provider->shouldReceive('run')
        ->once()
        ->with('kocr-v2-test', Mockery::on(function (array $input): bool {
            return $input['options']['run_llm_corrector'] === true
                && $input['options']['return_mode'] === 'summary_and_base64_zip'
                && $input['options']['llm_corrector_model'] === 'Qwen3-4B-Instruct-2507.Q4_K_M.gguf';
        }), Mockery::type('int'))
        ->andReturn(['id' => 'kocr-job']);
    app()->instance(RunPodProvider::class, $provider);

    $adapter = app(RunPodV2Adapter::class);
    expect($adapter->qasr('speech-to-text', 'leo', [
        'audio_url' => 'https://storage.example.test/inputs/audio.mp3',
        'intelligent' => true,
    ]))->toBe(['id' => 'qasr-job'])
        ->and($adapter->kocr('ocr', 'scanner', [
            'job_id' => 'ocr-job',
            'input_path' => 'renders/customer/ocr/ocr-job/input.png',
            'file_name' => 'input.png',
            'intelligent' => true,
        ]))->toBe(['id' => 'kocr-job']);

    expect(fn () => $adapter->qasr('speech-to-text', 'leo', [
        'audio_url' => 'https://127.0.0.1/private.mp3',
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $adapter->kocr('ocr', 'scanner', [
            'job_id' => 'ocr-job',
            'input_path' => '../.env',
            'file_name' => 'input.png',
        ]))->toThrow(InvalidArgumentException::class);
});
