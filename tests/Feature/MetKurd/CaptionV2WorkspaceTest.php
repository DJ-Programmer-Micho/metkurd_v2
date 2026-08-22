<?php

use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\ASR\QasrJobSyncService;
use App\Services\MetKurd\Jobs\CaptionSubmissionService;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Providers\RunPodProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('s3');
    $this->seed();
    $this->seed(Database\Seeders\CaptionToolSeeder::class);
    $this->seed(Database\Seeders\OmniToolSeeder::class);
    config()->set('metkurd_v2.enabled', true);
    config()->set('runpod.endpoints.qasr_v2', 'caption-worker-test');
    config()->set('runpod.v2_input_hosts', ['storage.example.test']);
});

function captionV2Customer(string $suffix): Customer
{
    $customer = Customer::create(['username' => "caption_{$suffix}", 'email' => "caption-{$suffix}@example.test", 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    CreditWallet::query()->updateOrCreate(['customer_id' => $customer->id, 'wallet_type' => CreditWallet::TYPE_APP], ['balance_credits' => 100000, 'subscription_balance_credits' => 100000, 'addon_balance_credits' => 0]);
    return $customer->fresh();
}

it('renders the green Caption workspace with FilePond, WaveSurfer, and no fixed dropdowns', function () {
    $customer = captionV2Customer('ui');
    $this->actingAs($customer, 'app');

    $this->get(route('app.v2.caption', ['locale' => 'en']))
        ->assertOk()->assertSee('metkurd-v2--success')
        ->assertDontSee('>Language<', false)->assertDontSee('>Model<', false);

    Livewire::test('app::v2.pages.tools.app-caption')
        ->assertSet('intelligent', false)->assertSee('v2-caption-workspace', false)
        ->assertSee('v2-caption-audio-pond', false)->assertSee('Intelligent')->assertSee('Beta')
        ->assertSee('Current Caption Result')->assertSee('Recent Captions')
        ->set('audioName', 'uploaded.wav')->set('audioMime', 'audio/wav')->set('audioDurationSec', 12.5)
        ->assertSee('data-caption-upload-waveform', false)->assertSee('data-job="caption-upload"', false)
        ->assertSee('data-accent="success"', false);
});

it('preserves the V1 Caption worker contract and adds only intelligent', function (bool $intelligent, int $expected) {
    $customer = captionV2Customer($intelligent ? 'intelligent-on' : 'intelligent-off');
    $adapter = Mockery::mock(RunPodV2Adapter::class);
    $adapter->shouldReceive('qasr')->once()->with('speech-to-text', 'caption', Mockery::on(fn (array $payload): bool =>
        (string) data_get($payload, 'audio_url') !== ''
        && (string) data_get($payload, 'model_variant') === 'fine_tuned'
        && (string) data_get($payload, 'language') === 'ckb'
        && (string) data_get($payload, 'type') === 'caption'
        && (string) data_get($payload, 'output_format') === 'srt'
        && data_get($payload, 'return_srt') === true && data_get($payload, 'return_segments') === true
        && (int) data_get($payload, 'max_words_per_caption') === 8 && (int) data_get($payload, 'max_caption_seconds') === 6
        && (int) data_get($payload, 'min_caption_seconds') === 1 && (int) data_get($payload, 'intelligent') === $expected
    ))->andReturn(['id' => 'caption-runpod-'.$expected]);
    app()->instance(RunPodV2Adapter::class, $adapter);

    $job = app(CaptionSubmissionService::class)->submit($customer, UploadedFile::fake()->create('interview.wav', 128, 'audio/wav'), [
        'model_variant' => 'fine_tuned', 'language' => 'ckb', 'intelligent' => $intelligent, 'duration_sec' => 45, 'billable_minutes' => 1,
        'input_hash' => 'caption-test-'.$expected, 'audio_name' => 'interview.wav', 'audio_mime' => 'audio/wav',
    ]);

    expect((string) $job->job_kind)->toBe('caption')->and((string) $job->tool->code)->toBe('caption')
        ->and((string) $job->toolAction->full_code)->toBe('caption.standard')
        ->and((int) data_get($job->input, 'intelligent'))->toBe($expected)
        ->and((string) data_get($job->input, 'type'))->toBe('caption')
        ->and((string) data_get($job->input, 'audio_path'))->toContain('/caption/')
        ->and((string) data_get($job->input, 'audio_path'))->toEndWith('/audio.wav');
    Storage::disk('s3')->assertExists((string) data_get($job->input, 'audio_path'));
})->with([[false, 0], [true, 1]]);

it('posts the exact V1 Caption options plus the optional intelligent flag', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('caption-worker-test', [
        'audio_url' => 'https://storage.example.test/renders/customer/caption/audio.wav',
        'model_variant' => 'fine_tuned', 'language' => 'ckb', 'type' => 'caption', 'intelligent' => 1,
        'output_format' => 'srt', 'return_srt' => true, 'return_segments' => true,
        'max_words_per_caption' => 8, 'max_caption_seconds' => 6, 'min_caption_seconds' => 1,
    ], 60)->andReturn(['id' => 'caption-payload-test']);
    app()->instance(RunPodProvider::class, $provider);

    expect(app(RunPodV2Adapter::class)->qasr('speech-to-text', 'caption', [
        'audio_url' => 'https://storage.example.test/renders/customer/caption/audio.wav',
        'model_variant' => 'fine_tuned', 'language' => 'ckb', 'intelligent' => true,
        'output_format' => 'srt', 'return_srt' => true, 'return_segments' => true,
        'max_words_per_caption' => 8, 'max_caption_seconds' => 6, 'min_caption_seconds' => 1,
    ]))->toBe(['id' => 'caption-payload-test']);
});

it('stores Caption SRT results and does not show Leo history', function () {
    $customer = captionV2Customer('sync');
    $caption = Tool::query()->where('code', 'caption')->firstOrFail();
    $leo = Tool::query()->where('code', 'leo')->firstOrFail();
    $job = MlJob::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $caption->id, 'job_kind' => 'caption', 'status' => 'running', 'provider_job_id' => 'caption-complete', 'endpoint_key' => 'qasr_v2', 'input' => ['type' => 'caption', 'language' => 'ckb', 'audio_path' => 'renders/customer/caption/input.wav', 'audio_disk' => 's3']]);
    MlJob::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $leo->id, 'job_kind' => 'leo', 'status' => 'done', 'input' => ['audio_name' => 'old.wav'], 'output' => ['text' => 'Historical Leo text']]);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->once()->with('caption-worker-test', 'caption-complete')->andReturn(['status' => 'COMPLETED', 'output' => ['text' => 'دەقی ژێرنوس', 'srt' => "1\n00:00:01,000 --> 00:00:03,000\nدەقی ژێرنوس"]]);
    app()->instance(RunPodProvider::class, $provider);

    app(QasrJobSyncService::class)->sync($job, $caption);
    $job->refresh();
    expect((string) $job->status)->toBe('done')->and((string) data_get($job->output, 'path'))->toContain('/caption/')
        ->and((string) data_get($job->output, 'srt_path'))->toContain('/caption/');
    expect(CustomerFile::query()->where('customer_id', $customer->id)->where('tool_code', 'caption')->where('path', data_get($job->output, 'path'))->exists())->toBeTrue();

    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.tools.app-caption')
        ->assertSee('دەقی ژێرنوس')->assertSee('View Caption')->assertSee('v2-caption-result-block', false)->assertSee('00:00:01,000 --> 00:00:03,000')
        ->assertSee('data-metkurd-waveform', false)
        ->assertSee('data-accent="success"', false)->assertDontSee('Historical Leo text')
        ->call('showCaption', (string) $job->id)->assertSee('Caption result')->assertSee('دەقی ژێرنوس')
        ->call('closeCaption')->assertDontSee('Caption result');
});
