<?php

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Voice;
use App\Services\Media\AudioProbeService;
use App\Services\MetKurd\Jobs\MultiSpeakerSubmissionService;
use App\Services\MetKurd\V2\MultiSpeakerInput;
use App\Services\MetKurd\V2\MultiSpeakerReferences;
use App\Services\Providers\RunPodProvider;
use App\Services\XTTS\XttsJobSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->seed(Database\Seeders\OmniToolSeeder::class);
    Storage::fake('s3');
    config(['metkurd_v2.enabled' => true, 'runpod.endpoints.omni_v2' => 'test-omni', 'runpod.v2_input_hosts' => ['storage.test']]);
    $this->customer = Customer::create(['username' => 'batch-user', 'email' => 'batch@example.com', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    CreditWallet::query()->updateOrCreate(['customer_id' => $this->customer->id, 'wallet_type' => 'app'], ['balance_credits' => 100000, 'subscription_balance_credits' => 100000, 'addon_balance_credits' => 0]);
    // Seeders run after migrations in fixtures; copy the source plan coverage once here.
    foreach (['zeta' => 'xomni-v2', 'theta' => 'vector-v2'] as $target => $source) {
        $sourceId = DB::table('tool_actions')->where('full_code', $source.'.generate')->value('id');
        $targetId = DB::table('tool_actions')->where('full_code', $target.'.generate')->value('id');
        foreach (DB::table('plan_entitlements')->where('tool_action_id', $sourceId)->get() as $row) {
            $copy = (array) $row;
            unset($copy['id']);
            $copy['tool_action_id'] = $targetId;
            DB::table('plan_entitlements')->insertOrIgnore($copy);
        }
    }
    foreach (['first', 'second'] as $voice) {
        Voice::create(['code' => $voice, 'name' => $voice, 'is_public' => true, 'is_active' => true, 'meta' => ['engine' => 'xomni', 'ref_audio' => 'real/'.$voice.'.wav', 'ref_text' => 'Stored transcript '.$voice]]);
    }
    $this->segments = [
        ['id' => 'one', 'voice' => 'first', 'text' => 'سڵاو', 'language' => 'ckb', 'pause_after_ms' => 500],
        ['id' => 'two', 'voice' => 'second', 'text' => 'جیهان', 'language' => 'ckb', 'pause_after_ms' => 2000],
    ];
    $this->actingAs($this->customer, 'app');
});

it('submits one ordered Zeta project with exact voice metadata and one total-character charge', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-omni', Mockery::on(function ($input) {
        expect($input['model'])->toBe('model_2')->and($input['mode'])->toBe('builtin_ref_batch')
            ->and($input['segments'][0]['ref_audio'])->toBe('real/first.wav')
            ->and($input['segments'][1]['ref_audio'])->toBe('real/second.wav')
            ->and($input['segments'][0]['ref_text'])->toBe('Stored transcript first')
            ->and(array_column($input['segments'], 'pause_after_ms'))->toBe([500, 0])
            ->and(array_key_exists('voice', $input['segments'][0]))->toBeFalse()
            ->and($input['return_base64'])->toBeTrue();

        return true;
    }), Mockery::any())->andReturn(['id' => 'batch-1']);
    app()->instance(RunPodProvider::class, $provider);
    $service = app(MultiSpeakerSubmissionService::class);
    $job = $service->submit($this->customer, 'text-to-speech', 'zeta-1', 'same-key', $this->segments);
    $again = $service->submit($this->customer, 'text-to-speech', 'zeta-1', 'same-key', $this->segments);
    $chars = mb_strlen('سڵاوجیهان');
    expect($again->id)->toBe($job->id)->and(MlJob::count())->toBe(1)
        ->and(data_get($job->input, 'total_chars'))->toBe($chars)
        ->and((int) $job->credits_charged)->toBe($this->customer->priceCreditsFor('zeta.generate', ['channel' => 'app', 'metric_code' => 'character', 'chars' => $chars, 'language' => 'ckb']))
        ->and(CreditLedger::where('direction', 'debit')->where('reference_code', "ml-job:{$job->id}:charge")->count())->toBe(1);
});

it('saves multiple references once and reuses the exact signed URL within Theta', function () {
    $probe = Mockery::mock(AudioProbeService::class);
    $probe->shouldReceive('probeUploadedFile')->andReturn(['duration_sec' => 1.0]);
    app()->instance(AudioProbeService::class, $probe);
    $fileA = UploadedFile::fake()->createWithContent('a.wav', multiSpeakerWav(1));
    $fileB = UploadedFile::fake()->createWithContent('b.wav', multiSpeakerWav(2));
    $refs = app(MultiSpeakerReferences::class);
    $a = $refs->upload($this->customer, $fileA);
    $b = $refs->upload($this->customer, $fileB);
    expect($refs->upload($this->customer, $fileA)->id)->toBe($a->id)->and(CustomerFile::count())->toBe(2);
    $signatures = 0;
    Storage::disk('s3')->buildTemporaryUrlsUsing(function ($path) use (&$signatures) {
        return 'https://storage.test/'.$path.'?signature='.(++$signatures);
    });
    $segments = [];
    foreach ([$a, $b, $a] as $index => $ref) {
        $segments[] = ['id' => 's'.$index, 'reference_id' => $ref->id, 'ref_text' => 'سڵاو', 'text' => 'دەنگ', 'language' => 'ckb', 'pause_after_ms' => [1000, 2000, 500][$index]];
    }
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-omni', Mockery::on(function ($input) {
        expect($input['mode'])->toBe('audio_url_batch')->and($input['model'])->toBe('model_2')
            ->and($input['segments'][0]['audio_url'])->toBe($input['segments'][2]['audio_url'])
            ->and($input['segments'][1]['audio_url'])->not->toBe($input['segments'][0]['audio_url'])
            ->and(array_column($input['segments'], 'pause_after_ms'))->toBe([1000, 2000, 0])
            ->and($input['segments'][0]['ref_max_sec'])->toBe(20)->and($input['segments'][0]['ref_sample_rate'])->toBe(24000);

        return true;
    }), Mockery::any())->andReturn(['id' => 'theta-1']);
    app()->instance(RunPodProvider::class, $provider);
    $job = app(MultiSpeakerSubmissionService::class)->submit($this->customer, 'clone-text-to-speech', 'theta-1', 'theta-key', $segments);
    expect($signatures)->toBe(2)->and($job->status)->toBe('running')->and(CustomerFile::count())->toBe(2)
        ->and(MlJob::count())->toBe(1)->and(json_encode($job->input))->not->toContain('signature=');
});

function multiSpeakerWav(int $value = 1): string
{
    $pcm = str_repeat(pack('v', $value), 800);

    return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
}

function multiSpeakerProject($test, bool $clone): array
{
    if (! $clone) {
        return ['text-to-speech', 'zeta-1', $test->segments];
    }
    $probe = Mockery::mock(AudioProbeService::class);
    $probe->shouldReceive('probeUploadedFile')->andReturn(['duration_sec' => 1.0]);
    app()->instance(AudioProbeService::class, $probe);
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path) => 'https://storage.test/'.$path.'?signed=example');
    $segments = $test->segments;
    foreach ($segments as $index => &$segment) {
        $file = app(MultiSpeakerReferences::class)->upload($test->customer, UploadedFile::fake()->createWithContent('voice'.$index.'.wav', multiSpeakerWav($index)));
        $segment['reference_id'] = $file->id;
        $segment['ref_text'] = 'Reference transcript';
    }

    return ['clone-text-to-speech', 'theta-1', $segments];
}

it('persists one complete project and serves only owned available output', function (bool $clone) {
    [$service, $slug, $segments] = multiSpeakerProject($this, $clone);
    $code = $clone ? 'theta' : 'zeta';
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'success-1']);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['success' => true, 'model' => 'model_2', 'mode' => $clone ? 'audio_url_batch' : 'builtin_ref_batch', 'segment_count' => 2, 'mime_type' => 'audio/wav', 'audio_base64' => base64_encode(multiSpeakerWav()), 'duration' => 1.2]]);
    app()->instance(RunPodProvider::class, $provider);
    $job = app(MultiSpeakerSubmissionService::class)->submit($this->customer, $service, $slug, 'success', $segments);
    $sync = app(XttsJobSyncService::class);
    $sync->sync($job, $job->tool);
    $sync->sync($job->fresh(), $job->tool);
    expect($job->fresh()->status)->toBe('done')->and(CustomerFile::where('source_id', $job->id)->count())->toBe(1);
    $this->get(route('app.renders.'.$code.'.download', ['locale' => 'en', 'jobId' => $job->id]))->assertOk();
    $this->get(route('app.renders.'.($clone ? 'zeta' : 'theta').'.download', ['locale' => 'en', 'jobId' => $job->id]))->assertNotFound();
    CustomerFile::where('source_id', $job->id)->update(['status' => 'deleted']);
    $this->get(route('app.renders.'.$code.'.download', ['locale' => 'en', 'jobId' => $job->id]))->assertNotFound();
})->with([false, true]);

it('rejects partial completion, sanitizes segment failures and refunds once', function (array $output, bool $clone) {
    [$service, $slug, $segments] = multiSpeakerProject($this, $clone);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'failed-1']);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => $output + ['audio_base64' => base64_encode(multiSpeakerWav())]]);
    app()->instance(RunPodProvider::class, $provider);
    $job = app(MultiSpeakerSubmissionService::class)->submit($this->customer, $service, $slug, 'failed', $segments);
    $sync = app(XttsJobSyncService::class);
    $sync->sync($job, $job->tool);
    $sync->sync($job->fresh(), $job->tool);
    expect($job->fresh()->status)->toBe('failed')->and(CustomerFile::where('purpose', 'render')->count())->toBe(0)
        ->and(json_encode($job->fresh()->error))->not->toContain('secret')
        ->and(CreditLedger::where('reference_code', "ml-job:{$job->id}:refund")->count())->toBe(1);
    if (isset($output['failed_segment'])) {
        expect(data_get($job->fresh()->error, 'failed_segment.id'))->toBe('two');
    }
})->with([
    'segment failure' => [['success' => false, 'failed_segment' => ['index' => 1, 'id' => 'secret-url'], 'stage' => 'inference', 'completed_segments' => 1, 'error' => 'secret signed URL']],
    'incomplete success' => [['success' => true, 'segment_count' => 1, 'model' => 'model_2', 'mode' => 'builtin_ref_batch', 'mime_type' => 'audio/wav']],
])->with([false, true]);

it('does not replay or refund an ambiguous dispatch', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andThrow(new \Illuminate\Http\Client\ConnectionException('timeout'));
    app()->instance(RunPodProvider::class, $provider);
    $service = app(MultiSpeakerSubmissionService::class);
    $job = $service->submit($this->customer, 'text-to-speech', 'zeta-1', 'unknown', $this->segments);
    $again = $service->submit($this->customer, 'text-to-speech', 'zeta-1', 'unknown', $this->segments);
    expect($again->id)->toBe($job->id)->and($job->failure_stage)->toBe('provider_submission_unknown')->and($job->refunded_at)->toBeNull();
});

it('validates the complete project before charging', function () {
    $this->segments[1]['voice'] = 'not-authorized';
    expect(fn () => app(MultiSpeakerSubmissionService::class)->submit($this->customer, 'text-to-speech', 'zeta-1', 'invalid', $this->segments))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(MlJob::count())->toBe(0)->and(CreditLedger::where('direction', 'debit')->count())->toBe(0);
});

it('rejects an oversized project before creating a job or debit', function (string $case) {
    $segments = array_map(fn ($i) => array_merge($this->segments[0], ['id' => 's'.$i, 'text' => str_repeat('x', $case === 'characters' ? 500 : 1)]), range(1, $case === 'characters' ? 11 : 26));
    expect(fn () => app(MultiSpeakerSubmissionService::class)->submit($this->customer, 'text-to-speech', 'zeta-1', 'large', $segments))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(MlJob::count())->toBe(0)->and(CreditLedger::where('direction', 'debit')->count())->toBe(0);
})->with(['characters', 'segments']);

it('corrects local endpoint configuration failures before the dispatch marker', function () {
    config(['runpod.endpoints.omni_v2' => '']);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldNotReceive('run');
    app()->instance(RunPodProvider::class, $provider);
    $job = app(MultiSpeakerSubmissionService::class)->submit($this->customer, 'text-to-speech', 'zeta-1', 'unconfigured', $this->segments);
    expect($job->status)->toBe('failed')->and($job->submission_attempted_at)->toBeNull()->and($job->refunded_at)->not->toBeNull();
});

it('binds submission keys to the original project and enforces App concurrency without extra charges', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'concurrent-1']);
    app()->instance(RunPodProvider::class, $provider);
    $service = app(MultiSpeakerSubmissionService::class);
    $service->submit($this->customer, 'text-to-speech', 'zeta-1', 'original', $this->segments);
    $this->segments[0]['text'] = 'Another text';
    expect(fn () => $service->submit($this->customer, 'text-to-speech', 'zeta-1', 'original', $this->segments))->toThrow(RuntimeException::class);
    $limits = Mockery::mock(\App\Services\Plans\PlanConcurrencyService::class);
    $limits->shouldReceive('allowedConcurrentJobsForCustomer')->andReturn(1);
    app()->instance(\App\Services\Plans\PlanConcurrencyService::class, $limits);
    expect(fn () => $service->submit($this->customer, 'text-to-speech', 'zeta-1', 'new-identity', $this->segments))->toThrow(RuntimeException::class);
    expect(MlJob::count())->toBe(1)->and(CreditLedger::where('direction', 'debit')->count())->toBe(1);
});

it('maps supported pauses and validates size and segment identity', function () {
    $input = app(MultiSpeakerInput::class);
    $segments = array_map(fn ($pause, $i) => array_merge($this->segments[0], ['id' => 'p'.$i, 'pause_after_ms' => $pause]), [0, 500, 1000, 2000, 2000], range(0, 4));
    expect(array_column($input->prepare($this->customer, $segments, false)['segments'], 'pause_after_ms'))->toBe([0, 500, 1000, 2000, 0]);
    $segments[0]['pause_after_ms'] = 1500;
    expect(fn () => $input->prepare($this->customer, $segments, false))->toThrow(\Illuminate\Validation\ValidationException::class);
    $segments[0]['pause_after_ms'] = 0;
    $segments[0]['text'] = str_repeat('x', 501);
    expect(fn () => $input->prepare($this->customer, $segments, false))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('supports segment sorting deletion and native page routing for both products', function (string $service, string $slug) {
    $page = Livewire::test('app::v2.pages.tools.multi-speaker', ['service' => $service, 'tool' => $slug]);
    $page->call('addSegment')->call('addSegment')->call('addSegment');
    [$a, $b, $c, $d] = $page->get('segmentOrder');
    foreach ([$a, $b, $c, $d] as $index => $id) {
        $page->set("segments.$id.text", ['A سڵاو', 'B world', 'C مرحبا', 'D جیهان'][$index])
            ->set("segments.$id.voice", $index % 2 ? 'second' : 'first')
            ->set("segments.$id.reference_id", 100 + $index)
            ->set("segments.$id.ref_text", 'Reference '.$index)
            ->set("segments.$id.language", ['ckb', 'en', 'ar', 'ckb'][$index])
            ->set("segments.$id.pause_after_ms", [500, 1000, 2000, 0][$index]);
    }
    $original = $page->get('segments');
    $chars = $page->get('totalChars');
    $quote = $page->get('creditsCost');
    $page->call('reorderSegments', [$a, $d, $b, $c])->assertSet('segmentOrder', [$a, $d, $b, $c])
        ->assertSet('segments', $original)->assertSet('totalChars', $chars)->assertSet('creditsCost', $quote)
        ->assertSee('segments.'.$d.'.text', false)
        ->set("segments.$d.text", 'D edited')->assertSet("segments.$b.text", $original[$b]['text'])
        ->call('moveSegment', $d, 1)->assertSet('segmentOrder', [$a, $b, $d, $c])
        ->call('reorderSegments', [$c, $d, $a, $b])->assertSet('segmentOrder', [$c, $d, $a, $b])
        ->call('reorderSegments', [$a, $a, $b, $c])->assertSet('segmentOrder', [$c, $d, $a, $b])
        ->call('deleteSegment', $b)->assertSet('segmentOrder', [$c, $d, $a])->call('addSegment');
    $new = $page->get('segmentOrder')[3];
    $page->call('reorderSegments', [$new, $a, $d, $c])->assertSet('segmentOrder', [$new, $a, $d, $c])
        ->assertSet("segments.$d.text", 'D edited')->assertSet("segments.$c.language", 'ar')
        ->assertSet("segments.$c.pause_after_ms", 2000)->assertSet("segments.$new.text", '');
    expect(array_column($page->get('orderedSegments'), 'id'))->toBe([$new, $a, $d, $c]);
    $this->get(route('app.v2.tool', ['locale' => 'en', 'service' => $service, 'tool' => $slug]))->assertOk()->assertSee('Generate full project');
})->with([['text-to-speech', 'zeta-1'], ['clone-text-to-speech', 'theta-1']]);

it('uses the Apollo panel to select a voice only for the active Zeta segment', function () {
    $voice = Voice::where('code', 'first')->firstOrFail();
    $voice->update(['meta' => $voice->meta + ['avatar_path' => 'first.png', 'preview_audio' => 'first.wav']]);
    $page = Livewire::test('app::v2.pages.tools.multi-speaker', ['service' => 'text-to-speech', 'tool' => 'zeta-1']);
    $a = $page->get('segmentOrder')[0];
    $page->call('addSegment');
    $b = $page->get('segmentOrder')[1];
    $page->assertSee('omni-reference-panel', false)->assertSee('omni-voice-avatar-image', false)
        ->assertSee('MetKurdSpeakerPreview', false)
        ->call('editSegmentVoice', $a)->call('selectSpeaker', 'first')
        ->assertSet("segments.$a.voice", 'first')->assertSet("segments.$b.voice", '')
        ->call('editSegmentVoice', $b)->call('selectSpeaker', 'second')->assertSet("segments.$b.voice", 'second')
        ->call('selectSpeaker', 'not-authorized')->assertSet("segments.$b.voice", 'second');
    expect(MlJob::count())->toBe(0);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-omni', Mockery::on(function ($input) use ($b, $a) {
        expect($input['model'])->toBe('model_2')->and($input['mode'])->toBe('builtin_ref_batch')
            ->and(array_column($input['segments'], 'id'))->toBe([$b, $a])
            ->and(array_column($input['segments'], 'ref_audio'))->toBe(['real/second.wav', 'real/first.wav'])
            ->and(array_column($input['segments'], 'text'))->toBe(['جیهان', 'سڵاو'])
            ->and(array_column($input['segments'], 'pause_after_ms'))->toBe([1000, 0]);

        return true;
    }), Mockery::any())->andReturn(['id' => 'zeta-ui']);
    app()->instance(RunPodProvider::class, $provider);
    $page->set("segments.$a.text", 'سڵاو')->set("segments.$b.text", 'جیهان')
        ->set("segments.$b.pause_after_ms", 1000)->call('reorderSegments', [$b, $a])
        ->call('submitProject')->assertHasNoErrors()->assertSet('submissionError', '');
    expect(MlJob::count())->toBe(1);
});

it('allows Zeta-only customers to preview shared voice assets and rejects customers without access', function () {
    $voice = Voice::where('code', 'first')->firstOrFail();
    $voice->update(['meta' => $voice->meta + ['avatar' => 'metkurd_audio_data/fixtures/avatar.png', 'preview_audio' => 'metkurd_audio_data/fixtures/preview.wav']]);
    Storage::disk('s3')->put('metkurd_audio_data/fixtures/avatar.png', 'avatar');
    Storage::disk('s3')->put('metkurd_audio_data/fixtures/preview.wav', multiSpeakerWav());
    $setAccess = function (string $code, bool $allowed) {
        $actions = DB::table('tool_actions')->where('full_code', 'like', $code.'.%')->pluck('id');
        foreach ($actions as $id) {
            \App\Models\CustomerEntitlement::updateOrCreate(
                ['customer_id' => $this->customer->id, 'tool_action_id' => $id, 'entitlement_channel' => 'app'],
                ['allowed' => $allowed]
            );
        }
        $this->actingAs($this->customer->fresh(), 'app');
    };
    $setAccess('xomni', false);
    $setAccess('zeta', true);
    foreach (['preview', 'avatar'] as $asset) {
        $this->get(route('app.xomni.speaker.'.$asset, ['locale' => 'en', 'voiceCode' => 'first', 'proxy' => 1]))->assertOk();
    }
    $setAccess('zeta', false);
    foreach (['preview', 'avatar'] as $asset) {
        $this->get(route('app.xomni.speaker.'.$asset, ['locale' => 'en', 'voiceCode' => 'first', 'proxy' => 1]))->assertForbidden();
    }
    $setAccess('xomni', true);
    foreach (['preview', 'avatar'] as $asset) {
        $this->get(route('app.xomni.speaker.'.$asset, ['locale' => 'en', 'voiceCode' => 'first', 'proxy' => 1]))->assertOk();
    }
    expect(MlJob::count())->toBe(0)->and(CreditLedger::where('direction', 'debit')->count())->toBe(0);
});

it('refreshes Theta references after upload and submits reordered shared references without transcripts', function () {
    $probe = Mockery::mock(AudioProbeService::class);
    $probe->shouldReceive('probeUploadedFile')->andReturn(['duration_sec' => 1.0]);
    app()->instance(AudioProbeService::class, $probe);
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path) => 'https://storage.test/'.$path.'?signed=fixture');
    $page = Livewire::test('app::v2.pages.tools.multi-speaker', ['service' => 'clone-text-to-speech', 'tool' => 'theta-1']);
    $page->call('addSegment')->call('addSegment');
    [$a, $b, $c] = $page->get('segmentOrder');
    expect($page->get('references'))->toBe([]);
    $page->set('referenceAudio', UploadedFile::fake()->createWithContent('reference-a.wav', multiSpeakerWav(1)))
        ->assertHasNoErrors()->assertSet('referenceAudio', null)->assertSet('referenceRevision', 1)
        ->assertSee('reference-a.wav')->assertSee('v2-ctts-reference-preview', false);
    $refA = CustomerFile::firstOrFail()->id;
    $page->call('editSegmentVoice', $c)->call('selectReference', $refA)->assertSet("segments.$a.reference_id", $refA)
        ->assertSet("segments.$c.reference_id", $refA)
        ->set('referenceAudio', UploadedFile::fake()->createWithContent('reference-b.wav', multiSpeakerWav(2)))
        ->assertHasNoErrors()->assertSet('referenceRevision', 2)->assertSee('reference-b.wav');
    $refB = CustomerFile::latest('id')->firstOrFail()->id;
    $page->assertSet("segments.$b.reference_id", $refB)
        ->assertDontSee('Reference transcript (optional)')->assertDontSee('wire:model.blur="segments.', false);
    expect($page->get('references'))->toHaveCount(2);
    foreach ([$a, $b, $c] as $index => $id) {
        $page->set("segments.$id.text", ['سڵاو', 'جیهان', 'دەنگ'][$index])->set("segments.$id.pause_after_ms", [500, 1000, 2000][$index]);
    }
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-omni', Mockery::on(function ($input) use ($c, $b, $a) {
        expect($input['model'])->toBe('model_2')->and($input['mode'])->toBe('audio_url_batch')
            ->and(array_column($input['segments'], 'id'))->toBe([$c, $b, $a])
            ->and(array_column($input['segments'], 'text'))->toBe(['دەنگ', 'جیهان', 'سڵاو'])
            ->and(array_column($input['segments'], 'pause_after_ms'))->toBe([2000, 1000, 0])
            ->and($input['segments'][0]['audio_url'])->toBe($input['segments'][2]['audio_url'])
            ->and($input['segments'][1]['audio_url'])->not->toBe($input['segments'][0]['audio_url'])
            ->and($input['segments'][0]['ref_text'] ?? '')->toBe('');

        return true;
    }), Mockery::any())->andReturn(['id' => 'theta-ui']);
    app()->instance(RunPodProvider::class, $provider);
    $page->call('reorderSegments', [$c, $b, $a])->call('submitProject')->assertHasNoErrors()->assertSet('submissionError', '');
    expect(CustomerFile::count())->toBe(2)->and(MlJob::count())->toBe(1);
});

it('blocks unowned expired and deleted references with one safe correction and no dispatch', function (string $state) {
    [, , $segments] = multiSpeakerProject($this, true);
    $file = CustomerFile::findOrFail($segments[0]['reference_id']);
    if ($state === 'unowned') {
        $other = Customer::create(['username' => 'other-batch', 'email' => 'other-batch@example.com', 'password' => 'Secret123!', 'status' => 1]);
        $file->update(['customer_id' => $other->id]);
    } elseif ($state === 'expired') {
        $file->update(['expires_at' => now()->subMinute()]);
    } else {
        $file->update(['status' => 'deleted']);
    }
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldNotReceive('run');
    app()->instance(RunPodProvider::class, $provider);
    $job = app(MultiSpeakerSubmissionService::class)->submit($this->customer, 'clone-text-to-speech', 'theta-1', 'bad-ref', $segments);
    expect($job->status)->toBe('failed')->and($job->submission_attempted_at)->toBeNull()->and($job->refunded_at)->not->toBeNull();
})->with(['unowned', 'expired', 'deleted']);

it('keeps one job active until output storage succeeds without another submission', function () {
    $output = ['success' => true, 'model' => 'model_2', 'mode' => 'builtin_ref_batch', 'segment_count' => 2, 'mime_type' => 'audio/wav', 'audio_base64' => base64_encode(multiSpeakerWav())];
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'storage-retry']);
    $provider->shouldReceive('status')->twice()->andReturn(['status' => 'COMPLETED', 'output' => $output]);
    app()->instance(RunPodProvider::class, $provider);
    $job = app(MultiSpeakerSubmissionService::class)->submit($this->customer, 'text-to-speech', 'zeta-1', 'storage-retry', $this->segments);
    $storage = Mockery::mock(\App\Services\Storage\CustomerOutputStorage::class)->makePartial();
    $storage->shouldReceive('saveWavB64ToS3')->once()->andThrow(new RuntimeException('storage unavailable'));
    app()->instance(\App\Services\Storage\CustomerOutputStorage::class, $storage);
    app(XttsJobSyncService::class)->sync($job, $job->tool);
    expect($job->fresh()->isActive())->toBeTrue()->and(CustomerFile::count())->toBe(0)->and($job->fresh()->refunded_at)->toBeNull();
    app()->forgetInstance(\App\Services\Storage\CustomerOutputStorage::class);
    $this->travel(61)->seconds();
    app(XttsJobSyncService::class)->sync($job->fresh(), $job->tool);
    expect($job->fresh()->status)->toBe('done')->and(CustomerFile::count())->toBe(1);
});

it('registers separate actions with copied pricing and preserves later edits on migration replay', function () {
    $migration = require database_path('migrations/2026_09_20_000001_register_multi_speaker_tools.php');
    $zetaId = DB::table('tool_actions')->where('full_code', 'zeta.generate')->value('id');
    $thetaId = DB::table('tool_actions')->where('full_code', 'theta.generate')->value('id');
    expect($zetaId)->not->toBe($thetaId);
    $before = DB::table('pricing_rules')->whereIn('tool_action_id', [$zetaId, $thetaId])->orderBy('id')->get()->toJson();
    $migration->up();
    expect(DB::table('pricing_rules')->whereIn('tool_action_id', [$zetaId, $thetaId])->orderBy('id')->get()->toJson())->toBe($before);
    foreach (['zeta' => 'xomni-v2', 'theta' => 'vector-v2'] as $target => $source) {
        // Remove only the target fixture rows to exercise a first registration.
        $id = DB::table('tool_actions')->where('full_code', $target.'.generate')->value('id');
        DB::table('pricing_rules')->where('tool_action_id', $id)->delete();
        DB::table('plan_entitlements')->where('tool_action_id', $id)->delete();
        DB::table('tool_actions')->where('id', $id)->delete();
    }
    $migration->up();
    foreach (['zeta' => 'xomni-v2', 'theta' => 'vector-v2'] as $target => $source) {
        foreach (['app', 'api', 'mobile'] as $channel) {
            expect($this->customer->priceCreditsFor($target.'.generate', ['channel' => $channel, 'chars' => 1250, 'metric_code' => 'character']))
                ->toBe($this->customer->priceCreditsFor($source.'.generate', ['channel' => $channel, 'chars' => 1250, 'metric_code' => 'character']));
        }
    }
});

it('localizes both pages and keeps storage product filtering separate', function () {
    foreach (['zeta' => 'text-to-speech', 'theta' => 'clone-text-to-speech'] as $code => $service) {
        foreach (['en' => 'ltr', 'ar' => 'rtl', 'ku' => 'rtl'] as $locale => $direction) {
            $this->get(route('app.v2.tool', ['locale' => $locale, 'service' => $service, 'tool' => $code.'-1']))
                ->assertOk()->assertSee('dir="'.$direction.'"', false)->assertDontSee('Multi Speaker 1.0v')
                ->assertSee(str_replace(':number', '1', json_decode(file_get_contents(resource_path("lang/app/$locale.json")), true)['Choose a voice for segment :number']))
                ->assertDontSee('Reference transcript (optional)');
        }
        $file = CustomerFile::create(['customer_id' => $this->customer->id, 'purpose' => 'render', 'tool_code' => $code, 'disk' => 's3', 'path' => $code.'/output.wav', 'status' => 'active', 'size_bytes' => 10, 'mime' => 'audio/wav']);
        expect(app(\App\Support\CustomerStorageLibrary::class)->identity($file)['key'])->toBe($code.'-1');
    }
    app()->setLocale('en');
    Livewire::test('app::v2.pages.storage.app-storage')->set('product', 'zeta-1')->assertSee('Zeta')->assertDontSee('theta/output.wav');
});
