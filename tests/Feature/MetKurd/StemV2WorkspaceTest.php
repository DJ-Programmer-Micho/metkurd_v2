<?php

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Services\STEM\StemJobSyncService;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    config()->set('metkurd_v2.enabled', true);
});

it('automatically opens the separation that just completed', function () {
    $customer = Customer::create([
        'username' => 'stem_workspace_customer',
        'email' => 'stem-workspace@example.test',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
    $oldJob = stemV2WorkspaceJob($customer, 'done', 'Older separation');
    $newJob = stemV2WorkspaceJob($customer, 'running', 'New separation');
    stemV2WorkspaceRender($customer, $oldJob);
    stemV2WorkspaceRender($customer, $newJob);

    $sync = Mockery::mock(StemJobSyncService::class);
    $sync->shouldReceive('sync')->once()->andReturnUsing(function (MlJob $job): array {
        MlJob::query()->whereKey($job->id)->update(['status' => 'done', 'finished_at' => now(), 'updated_at' => now()]);

        return ['status' => 'done'];
    });
    app()->instance(StemJobSyncService::class, $sync);

    Livewire::actingAs($customer, 'app')
        ->test('app::v2.pages.tools.app-stem', ['mode' => '4'])
        ->set('selectedRenderId', (string) $oldJob->id)
        ->call('pollStem')
        ->assertSet('currentJobId', null)
        ->assertSet('selectedRenderId', (string) $newJob->id)
        ->assertSee('data-v2-stem-player', false)
        ->assertSee('New separation');
});

function stemV2WorkspaceJob(Customer $customer, string $status, string $audioName): MlJob
{
    return MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'job_kind' => 'stem',
        'status' => $status,
        'input' => ['workspace' => 'stem_v2', 'separation_mode' => 4, 'audio_name' => $audioName],
        'output' => ['stems' => [
            'vocals' => ['path' => "renders/stem/{$audioName}/vocals.mp3"],
            'drums' => ['path' => "renders/stem/{$audioName}/drums.mp3"],
            'bass' => ['path' => "renders/stem/{$audioName}/bass.mp3"],
            'other' => ['path' => "renders/stem/{$audioName}/other.mp3"],
        ]],
        'finished_at' => $status === 'done' ? now()->subMinute() : null,
    ]);
}

it('renders stable same-origin player URLs for both modes and all interface directions', function (int $mode) {
    $customer = Customer::create(['username' => 'stem-localized', 'email' => 'stem-localized@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $job = stemV2WorkspaceJob($customer, 'done', 'Completed fixture');
    if ($mode === 2) {
        $job->update(['input' => ['workspace' => 'stem_v2', 'separation_mode' => 2, 'audio_name' => 'Completed fixture'],
            'output' => ['stems' => ['vocals' => ['path' => 'vocals.mp3'], 'instrumental' => ['path' => 'instrumental.mp3']]]]);
    }
    stemV2WorkspaceRender($customer, $job);
    $this->actingAs($customer, 'app');
    $page = Livewire::test('app::v2.pages.tools.app-stem', ['mode' => (string) $mode]);
    $render = $page->get('selectedRender');
    expect($render['stream_urls'])->toHaveCount($mode);
    foreach ($render['stream_urls'] as $track => $url) {
        expect($url)->toBe(route('app.v2.stem.stream', ['locale' => 'en', 'jobId' => $job->id, 'track' => $track]).'?proxy=1');
    }
    foreach (['en' => 'ltr', 'ar' => 'rtl', 'ku' => 'rtl'] as $locale => $direction) {
        $this->get('/'.$locale.'/app-v2/stem/'.$mode.'-stem')->assertOk()
            ->assertSee('dir="'.$direction.'"', false)->assertSee('data-v2-stem-player', false)
            ->assertSee('proxy=1', false)->assertDontSee('data-fallback-url', false);
    }
})->with([2, 4]);

function stemV2WorkspaceRender(Customer $customer, MlJob $job): CustomerFile
{
    return CustomerFile::create([
        'customer_id' => $customer->id,
        'purpose' => 'render',
        'tool_code' => 'stem',
        'disk' => 's3',
        'path' => "renders/stem/{$job->id}/vocals.mp3",
        'size_bytes' => 128,
        'mime' => 'audio/mpeg',
        'status' => 'active',
        'source_type' => 'ml_job',
        'source_id' => (string) $job->id,
        'counts_toward_quota' => true,
        'meta' => ['job_id' => (string) $job->id],
    ]);
}
