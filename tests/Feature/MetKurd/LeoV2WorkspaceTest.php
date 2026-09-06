<?php

use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\ASR\QasrJobSyncService;
use App\Services\MetKurd\Jobs\LeoSubmissionService;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Providers\RunPodProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('s3');
    $this->seed();
    $this->seed(Database\Seeders\OmniToolSeeder::class);
    config()->set('metkurd_v2.enabled', true);
    config()->set('runpod.endpoints.qasr_v2', 'leo-worker-test');
    config()->set('runpod.v2_input_hosts', ['storage.example.test']);
});

function leoV2Customer(string $suffix): Customer
{
    $customer = Customer::create(['username' => "leo_{$suffix}", 'email' => "leo-{$suffix}@example.test", 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    CreditWallet::query()->updateOrCreate(['customer_id' => $customer->id, 'wallet_type' => CreditWallet::TYPE_APP], ['balance_credits' => 100000, 'subscription_balance_credits' => 100000, 'addon_balance_credits' => 0]);

    return $customer->fresh();
}

it('renders the green two-panel Leo workspace with FilePond and Intelligent off by default', function () {
    $customer = leoV2Customer('ui');
    $this->actingAs($customer, 'app');

    $this->get(route('app.v2.leo', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('metkurd-v2--success')
        ->assertDontSee('>Language<', false)
        ->assertDontSee('>Model<', false);

    Livewire::test('app::v2.pages.tools.app-leo')
        ->assertSet('intelligent', false)
        ->assertSee('v2-leo-workspace', false)
        ->assertSee('v2-leo-audio-pond', false)
        ->assertSee('Intelligent')
        ->assertSee('Beta')
        ->assertSee('Transcribed Text')
        ->assertSee('Recent Transcriptions')
        ->set('audioName', 'uploaded.wav')
        ->set('audioMime', 'audio/wav')

        ->assertSee('data-leo-upload-waveform', false)
        ->assertSee('data-job="leo-upload"', false)
        ->assertSee('data-accent="success"', false);
});

it('submits Leo with explicit Intelligent worker values and an isolated S3 prefix', function (bool $intelligent, int $expected) {
    $customer = leoV2Customer($intelligent ? 'intelligent-on' : 'intelligent-off');
    $adapter = Mockery::mock(RunPodV2Adapter::class);
    $adapter->shouldReceive('qasr')->once()->with('speech-to-text', 'leo', Mockery::on(fn (array $payload): bool => (int) data_get($payload, 'intelligent') === $expected
        && (string) data_get($payload, 'model_variant') === 'fine_tuned'
        && (string) data_get($payload, 'audio_url') !== ''))->andReturn(['id' => 'leo-runpod-'.$expected]);
    app()->instance(RunPodV2Adapter::class, $adapter);

    $job = app(LeoSubmissionService::class)->submit($customer, UploadedFile::fake()->create('interview.wav', 128, 'audio/wav'), [
        'model_variant' => 'fine_tuned', 'language' => 'ckb', 'intelligent' => $intelligent, 'duration_sec' => 45, 'billable_minutes' => 1,
        'input_hash' => 'leo-test-'.$expected, 'audio_name' => 'interview.wav', 'audio_mime' => 'audio/wav',
    ]);

    expect((string) $job->job_kind)->toBe('leo')->and((string) $job->tool->code)->toBe('leo')
        ->and((string) $job->toolAction->full_code)->toBe('leo.transcribe')
        ->and((int) data_get($job->input, 'intelligent'))->toBe($expected)
        ->and((string) data_get($job->input, 'audio_path'))->toContain('/leo/')
        ->and((string) data_get($job->input, 'audio_path'))->toEndWith('/audio.wav');
    Storage::disk('s3')->assertExists((string) data_get($job->input, 'audio_path'));
})->with([[false, 0], [true, 1]]);

it('saves Leo results and CustomerFiles without leaking historical qasr history', function () {
    $customer = leoV2Customer('sync');
    $leo = Tool::query()->where('code', 'leo')->firstOrFail();
    $qasr = Tool::query()->where('code', 'qasr')->firstOrFail();
    $job = MlJob::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $leo->id, 'job_kind' => 'leo', 'status' => 'running', 'provider_job_id' => 'leo-complete', 'input' => ['type' => 'asr', 'language' => 'ckb', 'audio_path' => 'renders/customer/leo/input.wav', 'audio_disk' => 's3']]);
    MlJob::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $qasr->id, 'job_kind' => 'qasr', 'status' => 'done', 'input' => ['audio_name' => 'old.wav'], 'output' => ['text' => 'Historical QASR text']]);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->once()->with('leo-worker-test', 'leo-complete')->andReturn(['status' => 'COMPLETED', 'output' => ['text' => 'لەو تاقیکردنەوەیە دەق دروست بوو']]);
    app()->instance(RunPodProvider::class, $provider);

    app(QasrJobSyncService::class)->sync($job, $leo);
    $job->refresh();
    expect((string) $job->status)->toBe('done')->and((string) data_get($job->output, 'path'))->toContain('/leo/');
    expect(CustomerFile::query()->where('customer_id', $customer->id)->where('tool_code', 'leo')->where('path', data_get($job->output, 'path'))->exists())->toBeTrue();

    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.tools.app-leo')
        ->assertSee('لەو تاقیکردنەوەیە دەق دروست بوو')
        ->assertSee('View Transcript')
        ->assertSee('data-metkurd-waveform', false)
        ->assertSee('data-accent="success"', false)
        ->assertDontSee('Historical QASR text')
        ->call('showTranscript', (string) $job->id)
        ->assertSee('Leo transcription')
        ->assertSee('لەو تاقیکردنەوەیە دەق دروست بوو')
        ->call('closeTranscript')
        ->assertDontSee('Leo transcription');
});
