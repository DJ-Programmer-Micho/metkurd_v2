<?php

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\ASR\QasrJobSyncService;
use App\Services\Storage\CustomerOutputStorage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    config()->set('metkurd_v2.enabled', true);
    $this->customer = Customer::create(['username' => 'core-review', 'email' => 'core-review@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
});

it('renders every native V2 workspace in the customer locale without a V1 workspace escape', function (string $locale) {
    app()->setLocale($locale);
    app()->instance('request', \Illuminate\Http\Request::create("/{$locale}/app-v2"));
    // Component tests bypass the route middleware which registers the app catalog.
    \Illuminate\Support\Facades\Lang::addJsonPath(resource_path('lang/app'));
    foreach ([
        ['tool', ['service' => 'text-to-speech', 'tool' => 'apollo-2']],
        ['tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-2']],
        ['leo', []], ['caption', []], ['ocr', []],
        ['stem', ['mode' => '2']], ['stem', ['mode' => '4']],
    ] as [$name, $parameters]) {
        Livewire::actingAs($this->customer, 'app')->test("app::v2.pages.tools.app-{$name}", $parameters)
            ->assertSee(\App\Support\AreaJsonTranslations::get('Credits & Resources', 'app', $locale))
            ->assertDontSee('Open V1 workspace');
    }
})->with(['en', 'ar', 'ku']);

it('shows reconciled QASR results and refreshes credits in the completing response', function (string $kind, string $poll) {
    $tool = Tool::firstOrCreate(['code' => $kind], ['name' => $kind]);
    $job = MlJob::create(['id' => 'completing-job', 'customer_id' => $this->customer->id, 'tool_id' => $tool->id, 'job_kind' => $kind, 'status' => 'running']);
    $sync = Mockery::mock(QasrJobSyncService::class);
    $sync->shouldReceive('sync')->once()->andReturnUsing(function () use ($job) {
        $job->update(['status' => 'done', 'output' => ['text' => 'The persisted result'], 'finished_at' => now()]);

        return ['status' => 'done'];
    });
    app()->instance(QasrJobSyncService::class, $sync);
    Livewire::actingAs($this->customer, 'app')->test("app::v2.pages.tools.app-{$kind}")
        ->call($poll)->assertSee('The persisted result')->assertDispatched('header:refresh');
})->with([['leo', 'pollLeo'], ['caption', 'pollCaption']]);

it('refreshes speech and OCR completion once without refreshing the header on unchanged polls', function (string $component, array $parameters, string $code, string $poll, string $synchronizer) {
    $tool = Tool::firstOrCreate(['code' => $code], ['name' => $code]);
    $job = MlJob::create(['id' => 'final-response-job', 'customer_id' => $this->customer->id, 'tool_id' => $tool->id, 'job_kind' => $code, 'status' => 'running', 'input' => ['v2' => true]]);
    $sync = Mockery::mock($synchronizer);
    $sync->shouldReceive('sync')->once()->andReturn(['status' => 'running']);
    $sync->shouldReceive('sync')->once()->andReturnUsing(function () use ($job) {
        $job->update(['status' => 'done', 'finished_at' => now()]);

        return ['status' => 'done'];
    });
    app()->instance($synchronizer, $sync);
    $view = Livewire::actingAs($this->customer, 'app')->test("app::v2.pages.tools.app-{$component}", $parameters)
        ->set('currentJobId', $job->id)
        ->call($poll)->assertNotDispatched('header:refresh')
        ->call($poll)->assertDispatched('header:refresh');
    expect(data_get($view->get('currentJob'), 'status'))->toBe('done');
    $view->call($poll)->assertNotDispatched('header:refresh');
})->with([
    ['tool', ['service' => 'text-to-speech', 'tool' => 'apollo-2'], 'xomni-v2', 'pollOmni', \App\Services\XTTS\XttsJobSyncService::class],
    ['ocr', [], 'ocr', 'pollOcr', \App\Services\OCR\OcrJobSyncService::class],
]);

it('allows a failed STEM deletion to be retried from its visible history entry', function () {
    $job = MlJob::create(['id' => 'retry-delete', 'customer_id' => $this->customer->id, 'job_kind' => 'stem', 'status' => 'delete_failed', 'input' => ['workspace' => 'stem_v2', 'separation_mode' => 4]]);
    $storage = Mockery::mock(CustomerOutputStorage::class);
    $storage->shouldReceive('deleteStemOutputs')->once()->with(Mockery::on(fn ($candidate) => $candidate->id === $job->id))->andReturnUsing(function () use ($job) {
        $job->update(['status' => 'deleted']);
    });
    app()->instance(CustomerOutputStorage::class, $storage);
    Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-stem', ['mode' => '4'])
        ->assertSee('Deletion failed')->assertSee("deleteRender('retry-delete')", false)
        ->call('deleteRender', $job->id)->assertDispatched('customerStorageUpdated');
    expect($job->fresh()->status)->toBe('deleted');
});

it('rejects client changes to authoritative voice catalogs and limits', function (string $field, mixed $value) {
    Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-2'])
        ->set($field, $value);
})->with([['maxCharacters', 999999], ['speakerGroups', ['injected' => []]]])->throws(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
