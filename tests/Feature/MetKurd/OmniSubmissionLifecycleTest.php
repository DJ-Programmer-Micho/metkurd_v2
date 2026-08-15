<?php

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\Voice;
use App\Services\Billing\CreditService;
use App\Services\MetKurd\Jobs\MlJobRefundService;
use App\Services\MetKurd\Jobs\OmniSubmissionService;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use App\Services\Providers\RunPodProvider;
use App\Services\XTTS\XttsJobSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->seed(Database\Seeders\OmniToolSeeder::class);
    config()->set('runpod.endpoints.omni_v2', 'omni-v2-test');
});

function omniLifecycleCustomer(string $suffix): Customer
{
    $customer = Customer::create([
        'username' => "omni_lifecycle_{$suffix}",
        'email' => "omni-lifecycle-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);

    CreditWallet::query()->updateOrCreate(
        ['customer_id' => $customer->id, 'wallet_type' => CreditWallet::TYPE_APP],
        ['balance_credits' => 1000, 'subscription_balance_credits' => 1000, 'addon_balance_credits' => 0],
    );

    return $customer->fresh();
}

it('persists one OMNI job, one charge, and one provider submission for a repeated submission key', function () {
    $customer = omniLifecycleCustomer('dedupe');
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'provider-omni-1']);
    app()->instance(RunPodProvider::class, $provider);

    $service = app(OmniSubmissionService::class);
    $first = $service->submit($customer, 'text-to-speech', 'apollo-1', 'same-submission-key', [
        'text' => 'A short Kurdish script.', 'ref_audio' => 'voices/demo.wav', 'language' => 'ckb',
    ]);
    $second = $service->submit($customer, 'text-to-speech', 'apollo-1', 'same-submission-key', [
        'text' => 'A short Kurdish script.', 'ref_audio' => 'voices/demo.wav', 'language' => 'ckb',
    ]);

    expect((string) $first->id)->toBe((string) $second->id)
        ->and(MlJob::query()->where('customer_id', $customer->id)->count())->toBe(1)
        ->and(MlJob::query()->findOrFail($first->id)->status)->toBe('running')
        ->and(CreditLedger::query()->where('reference_code', "ml-job:{$first->id}:charge")->where('direction', 'debit')->count())->toBe(1);
});

it('keeps Apollo versions distinct through jobs, credits, storage, and output records', function () {
    $customer = omniLifecycleCustomer('version-separation');
    Storage::fake('s3');

    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->twice()->andReturn(
        ['id' => 'provider-apollo-1'],
        ['id' => 'provider-apollo-2'],
    );
    $provider->shouldReceive('status')->twice()->andReturn(
        ['status' => 'COMPLETED', 'output' => [
            'audio_base64' => base64_encode('apollo-1 audio'),
            'output_filename' => 'omnivoice_20260815_120000_1.wav',
            'mime_type' => 'audio/wav',
        ]],
        ['status' => 'COMPLETED', 'output' => [
            'audio_base64' => base64_encode('apollo-2 audio'),
            'output_filename' => 'omnivoice_20260815_120001_2.wav',
            'mime_type' => 'audio/wav',
        ]],
    );
    app()->instance(RunPodProvider::class, $provider);

    $service = app(OmniSubmissionService::class);
    $apollo1 = $service->submit($customer, 'text-to-speech', 'apollo-1', 'apollo-1-version-key', [
        'text' => 'Apollo one script.', 'ref_audio' => 'voices/demo.wav', 'language' => 'ckb',
    ]);
    $apollo2 = $service->submit($customer, 'text-to-speech', 'apollo-2', 'apollo-2-version-key', [
        'text' => 'Apollo two script.', 'ref_audio' => 'voices/demo.wav', 'language' => 'ckb',
    ]);

    $apollo1Tool = Tool::query()->where('code', 'xomni')->firstOrFail();
    $apollo2Tool = Tool::query()->where('code', 'xomni-v2')->firstOrFail();
    expect($apollo1->tool_id)->toBe($apollo1Tool->id)
        ->and($apollo2->tool_id)->toBe($apollo2Tool->id)
        ->and($apollo1->toolAction?->full_code)->toBe('xomni.generate')
        ->and($apollo2->toolAction?->full_code)->toBe('xomni-v2.generate')
        ->and($apollo1->model_key)->toBe('model_1')
        ->and($apollo2->model_key)->toBe('model_2')
        ->and(data_get(CreditLedger::query()->where('reference_code', "ml-job:{$apollo1->id}:charge")->firstOrFail()->meta, 'tool_action'))->toBe('xomni.generate')
        ->and(data_get(CreditLedger::query()->where('reference_code', "ml-job:{$apollo2->id}:charge")->firstOrFail()->meta, 'tool_action'))->toBe('xomni-v2.generate');

    $sync = app(XttsJobSyncService::class);
    $sync->sync($apollo1->fresh(), $apollo1Tool);
    $sync->sync($apollo2->fresh(), $apollo2Tool);

    $apollo1 = $apollo1->fresh();
    $apollo2 = $apollo2->fresh();
    $apollo1Path = (string) data_get($apollo1->output, 'path');
    $apollo2Path = (string) data_get($apollo2->output, 'path');

    expect($apollo1Path)->toContain("/xomni/{$apollo1->id}/")
        ->and($apollo2Path)->toContain("/xomni-v2/{$apollo2->id}/")
        ->and($apollo2Path)->not->toContain("/xomni/{$apollo2->id}/")
        ->and($apollo1Path)->not->toBe($apollo2Path)
        ->and(CustomerFile::query()->where('source_id', $apollo1->id)->firstOrFail()->tool_code)->toBe('xomni')
        ->and(CustomerFile::query()->where('source_id', $apollo2->id)->firstOrFail()->tool_code)->toBe('xomni-v2');

    Storage::disk('s3')->assertExists($apollo1Path);
    Storage::disk('s3')->assertExists($apollo2Path);

    $this->actingAs($customer, 'app');

    $this->get(route('app.renders.xomni-v2.download', ['locale' => 'en', 'jobId' => $apollo2->id]))
        ->assertOk()
        ->assertDownload(basename($apollo2Path));

    $this->get(route('app.renders.xomni-v2.download', ['locale' => 'en', 'jobId' => $apollo1->id]))
        ->assertNotFound();

    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);
    app(\App\Services\Storage\StorageFileDeletionService::class)->delete(
        CustomerFile::query()->where('source_id', $apollo2->id)->firstOrFail()
    );

    Storage::disk('s3')->assertExists($apollo1Path);
    Storage::disk('s3')->assertMissing($apollo2Path);
});

it('refunds a provider start failure exactly once and restores the wallet', function () {
    $customer = omniLifecycleCustomer('refund');
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andThrow(new RuntimeException('RunPod rejected request'));
    app()->instance(RunPodProvider::class, $provider);

    $job = app(OmniSubmissionService::class)->submit($customer, 'text-to-speech', 'apollo-1', 'failed-submission-key', [
        'text' => 'A short Kurdish script.', 'ref_audio' => 'voices/demo.wav', 'language' => 'ckb',
    ]);

    app(MlJobRefundService::class)->refundFailedJob($job, 'repeat_reconciliation');

    expect(MlJob::query()->findOrFail($job->id)->status)->toBe('failed')
        ->and((int) $customer->fresh()->wallet()->firstOrFail()->balance_credits)->toBe(1000)
        ->and(CreditLedger::query()->where('reference_code', "ml-job:{$job->id}:refund")->where('direction', 'refund')->count())->toBe(1);
});

it('records an Apollo 2.0 refund against its own action identity', function () {
    $customer = omniLifecycleCustomer('apollo-2-refund');
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andThrow(new RuntimeException('RunPod rejected request'));
    app()->instance(RunPodProvider::class, $provider);

    $job = app(OmniSubmissionService::class)->submit($customer, 'text-to-speech', 'apollo-2', 'apollo-2-failure-key', [
        'text' => 'Apollo two failure script.', 'ref_audio' => 'voices/demo.wav', 'language' => 'ckb',
    ]);

    $refund = CreditLedger::query()
        ->where('reference_code', "ml-job:{$job->id}:refund")
        ->where('direction', 'refund')
        ->firstOrFail();

    expect(MlJob::query()->findOrFail($job->id)->toolAction?->full_code)->toBe('xomni-v2.generate')
        ->and(data_get($refund->meta, 'tool_action'))->toBe('xomni-v2.generate');
});

it('submits Apollo from the native V2 leaf through the shared lifecycle', function () {
    $customer = omniLifecycleCustomer('native-leaf');
    Voice::query()->create([
        'code' => 'native_v2_voice',
        'name' => 'Native V2 voice',
        'is_public' => true,
        'is_active' => true,
        'sort_order' => 1,
        'meta' => ['engine' => 'xomni', 'ref_audio' => 'voices/native-v2.wav'],
    ]);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'provider-v2-leaf']);
    app()->instance(RunPodProvider::class, $provider);

    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-2'])
        ->set('text', 'Native V2 Apollo request')
        ->set('speakerId', 'native_v2_voice')
        ->call('submitOmni')
        ->assertSet('currentJobId', fn (?string $id): bool => $id !== null)
        ->assertSee('Running')
        ->assertSee('Speakers')
        ->assertSee('Recent Renders')
        ->assertDontSee('V2 provider mapping')
        ->assertDontSee('V2 staged leaf');

    config()->set('metkurd_v2.enabled', true);
    $this->get(route('app.v2.tool', ['locale' => 'en', 'service' => 'text-to-speech', 'tool' => 'apollo-2']))
        ->assertOk()
        ->assertSee('API')
        ->assertSee('of');
});

it('loads the shared app catalogue and document direction for every V2 locale', function () {
    $customer = omniLifecycleCustomer('v2-locales');
    $this->actingAs($customer, 'app');
    config()->set('metkurd_v2.enabled', true);

    foreach ([
        'ku' => ['دەنگبێژان', 'dir="rtl"'],
        'ar' => ['المتحدثون', 'dir="rtl"'],
        'en' => ['Speakers', 'dir="ltr"'],
    ] as $locale => [$speakerLabel, $direction]) {
        $this->get(route('app.v2.tool', [
            'locale' => $locale,
            'service' => 'text-to-speech',
            'tool' => 'apollo-2',
        ]))
            ->assertOk()
            ->assertSee($speakerLabel)
            ->assertSee($direction, false);
    }
});

it('shows only the current customer’s Apollo renders in the workspace', function () {
    $customer = omniLifecycleCustomer('render-owner');
    $other = omniLifecycleCustomer('render-other');
    $toolId = (int) \App\Models\Tool::query()->where('code', 'xomni')->value('id');

    MlJob::create([
        'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $toolId,
        'status' => 'failed', 'job_kind' => 'xomni', 'input' => ['text' => 'My private Apollo render'],
    ]);
    MlJob::create([
        'id' => (string) Str::uuid(), 'customer_id' => $other->id, 'tool_id' => $toolId,
        'status' => 'failed', 'job_kind' => 'xomni', 'input' => ['text' => 'Other customer private render'],
    ]);

    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-1'])
        ->assertSee('My private Apollo render')
        ->assertDontSee('Other customer private render');
});

it('paginates only the current customer Apollo jobs without resetting the workspace draft', function () {
    $customer = omniLifecycleCustomer('render-pages');
    $other = omniLifecycleCustomer('render-pages-other');
    $toolId = (int) \App\Models\Tool::query()->where('code', 'xomni')->value('id');

    foreach (range(1, 4) as $index) {
        $job = MlJob::create([
            'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $toolId,
            'status' => ['queued', 'running', 'done', 'failed'][$index - 1], 'job_kind' => 'xomni',
            'input' => ['text' => "Apollo render {$index}", 'speaker_id' => 'native_v2_voice'],
            'output' => $index === 3 ? ['path' => 'customers/test/render.mp3'] : [],
        ]);
        MlJob::query()->whereKey($job->id)->update(['updated_at' => now()->addSeconds($index)]);
    }
    MlJob::create([
        'id' => (string) Str::uuid(), 'customer_id' => $other->id, 'tool_id' => $toolId,
        'status' => 'cancelled', 'job_kind' => 'xomni', 'input' => ['text' => 'Other paginated render'],
    ]);

    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-1'])
        ->set('text', 'Keep this draft while browsing history')
        ->assertSee('Apollo render 4')
        ->assertDontSee('Apollo render 1')
        ->assertDontSee('Other paginated render')
        ->assertSee('data-metkurd-waveform', false)
        ->assertDontSee('<audio', false)
        ->call('nextRecentRendersPage')
        ->assertSee('Apollo render 1')
        ->assertSet('text', 'Keep this draft while browsing history');
});

it('uses a plan-scoped, versioned cache for the V2 speaker catalogue', function () {
    $customer = omniLifecycleCustomer('speaker-cache');
    $catalogue = app(OmniSpeakerCatalog::class);
    $planId = method_exists($customer, 'currentServicePlanId')
        ? (int) ($customer->currentServicePlanId() ?? $customer->service_plan_id)
        : (int) $customer->service_plan_id;
    $key = $catalogue->cacheKey($planId, 'en');
    Cache::forget($key);

    $catalogue->forCustomer($customer, 'en');

    expect(Cache::has($key))->toBeTrue();
});

it('delays the GPU queue explanation and clears it when canonical job state changes', function () {
    $customer = omniLifecycleCustomer('queue-message');
    $this->actingAs($customer, 'app');
    Carbon::setTestNow('2026-08-13 10:00:00');

    $component = Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-1'])
        ->set('currentJobId', (string) Str::uuid())
        ->set('currentJob', ['status' => 'queued'])
        ->set('queuedSince', now()->toIso8601String())
        ->assertSee('glass-load--warning')
        ->assertDontSee('MetKurd AI GPUs are currently busy.');

    Carbon::setTestNow(now()->addSeconds(6));
    $component->set('currentJob', ['status' => 'queued'])
        ->assertSee('MetKurd AI GPUs are currently busy.');

    $component->set('currentJob', ['status' => 'running'])
        ->set('queuedSince', null)
        ->assertSee('Running')
        ->assertDontSee('MetKurd AI GPUs are currently busy.');

    Carbon::setTestNow();
});

it('keeps the Apollo editor RTL for every supported interface locale', function () {
    $customer = omniLifecycleCustomer('rtl-editor');
    $this->actingAs($customer, 'app');

    foreach (['en', 'ar', 'ku'] as $locale) {
        app()->setLocale($locale);
        Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-1'])
            ->assertSee('dir="rtl"', false);
    }

    app()->setLocale('en');
});

it('keeps the QASR start-failure refund idempotent for a canonical MlJob', function () {
    $customer = omniLifecycleCustomer('qasr');
    $job = MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'status' => 'failed',
        'job_kind' => 'qasr',
        'credits_charged' => 100,
        'charge_reference' => 'ml-job:qasr-regression:charge',
    ]);

    app(CreditService::class)->charge($customer->id, 100, 'asr_charge', [
        'reference_code' => $job->charge_reference,
        'related_type' => 'ml_job',
        'related_id' => $job->id,
        'ml_job_id' => $job->id,
    ]);
    app(MlJobRefundService::class)->refundFailedJob($job, 'provider_start_failed');
    app(MlJobRefundService::class)->refundFailedJob($job, 'scheduled_reconciliation');

    expect((int) $customer->fresh()->wallet()->firstOrFail()->balance_credits)->toBe(1000)
        ->and(CreditLedger::query()->where('reference_code', "ml-job:{$job->id}:refund")->where('direction', 'refund')->count())->toBe(1);
});
