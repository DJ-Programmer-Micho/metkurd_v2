<?php

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\MlJob;
use App\Services\Providers\RunPodProvider;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('keeps Translation durable without changing its V1 action or worker contract', function ($ambiguous) {
    $this->seed();
    Storage::fake('s3');
    config()->set('runpod.endpoints.tran', 'translation-test');
    $customer = Customer::create(['username' => 'tran-hardening', 'email' => 'tran-hardening@example.com', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    CreditWallet::updateOrCreate(['customer_id' => $customer->id, 'wallet_type' => 'app'], ['balance_credits' => 100000, 'subscription_balance_credits' => 100000, 'addon_balance_credits' => 0]);
    $provider = Mockery::mock(RunPodProvider::class);
    $expectation = $provider->shouldReceive('run')->once()->with('translation-test', Mockery::on(fn ($input) => $input['text'] === 'Hello from the test.' && $input['source_lang'] === 'en' && $input['target_lang'] === 'ku' && isset($input['max_new_tokens'],$input['chunk_chars'])), Mockery::type('int'));
    $ambiguous ? $expectation->andThrow(new RuntimeException('Connection lost after dispatch')) : $expectation->andReturn(['id' => 'remote-translation']);
    app()->instance(RunPodProvider::class, $provider);
    Livewire::actingAs($customer, 'app')->test('app::pages.tran.app-tran')
        ->set('sourceLang', 'en')->set('targetLang', 'ku')->set('text', 'Hello from the test.')
        ->call('postTran')->call('postTran');
    $job = MlJob::where('customer_id', $customer->id)->sole();
    expect($job->toolAction->full_code)->toBe('tran.standard')->and($job->charge_reference)->not->toBeEmpty();
    expect($job->status)->toBe($ambiguous ? 'queued' : 'running');
    expect(CreditLedger::where('customer_id', $customer->id)->where('direction', 'debit')->count())->toBe(1);
    expect(CreditLedger::where('customer_id', $customer->id)->where('direction', 'refund')->count())->toBe(0);
})->with([false, true]);
