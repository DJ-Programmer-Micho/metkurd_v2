<?php

use App\Models\Customer;
use App\Models\MlJob;
use App\Support\Landing\LandingPublicMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    $this->seed();
    app(LandingPublicMetrics::class)->flushCache();
});

function landingMetricsCustomer(?string $email = null, ?string $username = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => $username ?? "landing_metrics_{$suffix}",
        'email' => $email ?? "landing-metrics-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

function createLandingMetricJob(Customer $customer, string $jobKind, string $status = 'done'): MlJob
{
    return MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => (int) $customer->id,
        'status' => $status,
        'job_kind' => $jobKind,
        'finished_at' => $status === 'done' ? now() : null,
        'started_at' => now(),
    ]);
}

it('counts generated audio from finished xtts, f5tts, and clone xtts jobs', function () {
    $customer = landingMetricsCustomer('landing-generated-audio@example.com', 'landing_generated_audio_user');

    createLandingMetricJob($customer, 'tts');
    createLandingMetricJob($customer, 'ftts');
    createLandingMetricJob($customer, 'clone_tts');
    createLandingMetricJob($customer, 'tts', 'failed');

    $cards = collect(app(LandingPublicMetrics::class)->cards())->keyBy('key');

    expect($cards['generated_audio']['raw_value'])->toBe(3)
        ->and($cards['generated_audio']['value'])->toBe('3');
});

it('counts transcribed jobs from finished wasr and qasr jobs only', function () {
    $customer = landingMetricsCustomer('landing-transcribed@example.com', 'landing_transcribed_user');

    createLandingMetricJob($customer, 'wasr');
    createLandingMetricJob($customer, 'qasr');
    createLandingMetricJob($customer, 'wasr', 'queued');

    $cards = collect(app(LandingPublicMetrics::class)->cards())->keyBy('key');

    expect($cards['transcribed']['raw_value'])->toBe(2)
        ->and($cards['transcribed']['value'])->toBe('2');
});

it('counts finished ocr jobs', function () {
    $customer = landingMetricsCustomer('landing-ocr@example.com', 'landing_ocr_user');

    createLandingMetricJob($customer, 'ocr');
    createLandingMetricJob($customer, 'ocr');
    createLandingMetricJob($customer, 'ocr', 'running');

    $cards = collect(app(LandingPublicMetrics::class)->cards())->keyBy('key');

    expect($cards['ocr_pages']['raw_value'])->toBe(2);
});

it('counts finished separation jobs', function () {
    $customer = landingMetricsCustomer('landing-tran@example.com', 'landing_tran_user');

    createLandingMetricJob($customer, 'stem');
    createLandingMetricJob($customer, 'stem', 'delete_failed');

    $cards = collect(app(LandingPublicMetrics::class)->cards())->keyBy('key');

    expect($cards['music_separation']['raw_value'])->toBe(1);
});

it('formats compact metric values as expected', function () {
    $service = app(LandingPublicMetrics::class);

    expect($service->formatCompact(999))->toBe('999')
        ->and($service->formatCompact(1010))->toBe('1K')
        ->and($service->formatCompact(3615))->toBe('3.6K')
        ->and($service->formatCompact(12698))->toBe('12.6K')
        ->and($service->formatCompact(100000))->toBe('100K')
        ->and($service->formatCompact(100000000))->toBe('100M')
        ->and($service->formatCompact(532147))->toBe('532K');
});

it('includes current V2 kinds without counting failed or pending work', function () {
    $customer = landingMetricsCustomer();
    foreach (['omni_tts', 'clone_xomni', 'vector-v2', 'omni_tts_batch', 'omni_clone_batch', 'leo', 'caption', 'stem'] as $kind) {
        createLandingMetricJob($customer, $kind);
        createLandingMetricJob($customer, $kind, 'failed');
        createLandingMetricJob($customer, $kind, 'queued');
    }
    $counts = app(LandingPublicMetrics::class)->counts();
    expect($counts['generated_audio'])->toBe(5)->and($counts['transcribed'])->toBe(2)->and($counts['music_separation'])->toBe(1);
});

it('caches raw metric counts until the cache is flushed', function () {
    $customer = landingMetricsCustomer('landing-cache@example.com', 'landing_cache_user');
    $service = app(LandingPublicMetrics::class);

    createLandingMetricJob($customer, 'tts');

    expect(collect($service->cards())->keyBy('key')['generated_audio']['raw_value'])->toBe(1);

    createLandingMetricJob($customer, 'ftts');

    expect(collect($service->cards())->keyBy('key')['generated_audio']['raw_value'])->toBe(1);

    $service->flushCache();

    expect(collect($service->cards())->keyBy('key')['generated_audio']['raw_value'])->toBe(2);
});

it('renders cached landing metrics on the public home page', function () {
    $customer = landingMetricsCustomer('landing-render@example.com', 'landing_render_user');

    foreach (range(1, 1010) as $index) {
        createLandingMetricJob($customer, $index <= 1000 ? 'tts' : 'ftts');
    }

    $response = $this->get(route('landing.home', ['locale' => 'en']));

    $response->assertOk()
        ->assertSee('Generated Audio')
        ->assertSee('1K')
        ->assertSee('Transcribed');
});
