<?php

use App\Domain\Payments\Models\Payment;
use App\Models\AdminAuditEvent;
use App\Models\ApiCreditReservation;
use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\CreditLedger;
use App\Models\CreditOrder;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\CustomerFile;
use App\Models\CustomerServiceSubscription;
use App\Models\MlJob;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Models\User;
use App\Services\Admin\AdminOperations;
use App\Services\CustomerApi\V2\ApiCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    $this->seed();
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    $this->operator = User::forceCreate(['name' => 'P2 Support', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read']]);
    $this->operator->profile()->create(['first_name' => 'P2', 'last_name' => 'Support']);
    $this->actingAs($this->operator, 'admin');
    $this->customer = Customer::create(['username' => 'p2_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'uid' => Str::uuid(), 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->reader = app(AdminOperations::class);
});

function p2Job(Customer $c, string $action = 'ocr.standard', array $values = []): MlJob
{
    $a = ToolAction::where('full_code', $action)->firstOrFail();

    return MlJob::create(array_merge(['id' => (string) Str::uuid(), 'customer_id' => $c->id, 'tool_id' => $a->tool_id, 'tool_action_id' => $a->id, 'status' => 'running', 'input' => ['text' => 'PRIVATE_CUSTOMER_INPUT'], 'output' => [], 'credits_charged' => 50], $values));
}

function p2Api(Customer $c, ?MlJob $j = null): ApiJob
{
    $key = CustomerApiKey::create(['customer_id' => $c->id, 'name' => 'Fixture', 'key_prefix' => Str::random(12), 'key_hash' => hash('sha256', Str::uuid()), 'scopes' => ['v2:ocr'], 'status' => 'active']);

    return ApiJob::create(['id' => (string) Str::uuid(), 'customer_id' => $c->id, 'api_key_id' => $key->id, 'ml_job_id' => $j?->id, 'tool_code' => 'ocr', 'tool_action' => 'ocr.standard', 'engine' => 'v2', 'status' => 'processing', 'storage_mode' => 'temporary', 'idempotency_hash' => hash('sha256', Str::uuid()), 'meta' => ['api_version' => 2, 'service' => 'ocr', 'expires_at' => now()->addDays(7)->toIso8601String()]]);
}

it('keeps attention groups aligned with local persistence and API reservation evidence using read queries only', function () {
    $uncertain = p2Job($this->customer, values: ['status' => 'queued', 'failure_stage' => 'provider_submission_unknown']);
    $saving = p2Job($this->customer, values: ['status' => 'saving', 'output' => ['provider_success' => true]]);
    $missing = p2Job($this->customer, values: ['status' => 'done']);
    $saved = p2Job($this->customer, values: ['status' => 'done', 'output' => ['text' => 'isolated saved text']]);
    $failed = p2Job($this->customer, values: ['status' => 'failed', 'failure_stage' => 'refund_pending']);
    $linked = p2Job($this->customer);
    $api = p2Api($this->customer, $linked);
    $api->update(['status' => 'completed']);
    ApiCreditReservation::create(['id' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'api_job_id' => $api->id, 'amount' => 100, 'status' => 'reserved']);
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    $summary = $this->reader->jobSummary(['customer' => $this->customer->id]);
    expect($summary)->toMatchArray(['attention' => 5, 'active' => 3, 'completed' => 2, 'failed' => 1, 'uncertain' => 1, 'persistence' => 2, 'reservation' => 1]);
    expect($this->reader->jobSummary(['customer' => $this->customer->id, 'search' => $saved->id])['attention'])->toBe(0);
    foreach (AdminOperations::JOB_GROUPS as $group) {
        $rows = $this->reader->query('jobs', ['customer' => $this->customer->id, 'group' => $group])->get();
        expect($rows)->toHaveCount($summary[$group]);
        if ($group === 'attention') {
            foreach ($rows as $job) {
                expect($this->reader->row($job)['attention'])->toBeTrue();
            }
        }
    }
    Livewire::test('admin::pages.operations.adm-operations')->set('group', 'uncertain')
        ->assertSee($uncertain->id)->assertDontSee($failed->id)->call('resetFilters')->assertSet('group', '');
    expect(collect($sql)->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q))->all())->toBe([]);
    Http::assertNothingSent();
});

it('renders every operational section for read support without remote requests', function (string $section) {
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => $section]))->assertOk()->assertSee('Local records only');
    Http::assertNothingSent();
})->with(AdminOperations::SECTIONS);

it('shows separate wallets and normalized subscription storage context without writes', function () {
    $plan = ServicePlan::where('code', 'student')->firstOrFail();
    CustomerServiceSubscription::create(['customer_id' => $this->customer->id, 'service_plan_id' => $plan->id, 'previous_service_plan_id' => ServicePlan::where('code', 'free')->value('id'), 'source' => 'admin', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'auto_renew' => false]);
    foreach (['app' => 123, 'api' => 456] as $type => $amount) {
        CreditWallet::updateOrCreate(['customer_id' => $this->customer->id, 'wallet_type' => $type], ['balance_credits' => $amount, 'subscription_balance_credits' => $amount - 10, 'addon_balance_credits' => 10]);
    }
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    $view = $this->reader->customer($this->customer->id);
    expect($view['app_credits']['balance_credits'])->toBe(123)->and($view['api_credits']['balance_credits'])->toBe(456)
        ->and($view['plan']['plan'])->toBe($plan->name)->and($view['storage']['quota_bytes'])->toBeGreaterThan(0);
    $this->get(route('admin.customers.detail', ['locale' => 'en', 'customer' => $this->customer->id]))->assertOk()->assertSee('App Credits')->assertSee('API Credits');
    expect(collect($sql)->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q))->all())->toBe([]);
    Http::assertNothingSent();
});

it('maps every native model through the current catalog and distinguishes API channel', function () {
    foreach (app(ApiCatalog::class)->variants() as $v) {
        $j = p2Job($this->customer, $v['action']);
        $row = $this->reader->row($j);
        expect($row['family'])->toBe($v['service'])->and($row['model'])->toBe($v['tool']['name'])->and($row['channel'])->toBe('app');
        $api = p2Api($this->customer, $j);
        $row = $this->reader->row($j);
        expect($row['api_job_id'])->toBe($api->id)->and($row['channel'])->toBe('api')->and(array_key_exists('charged', $row))->toBeFalse();
    }
});

it('separates provider completion from local persistence and hides generated content', function () {
    $j = p2Job($this->customer, values: ['status' => 'saving', 'output' => ['provider_success' => true, 'text' => 'PRIVATE_CUSTOMER_OUTPUT', 'url' => 'https://example.test/?signature=fixture']]);
    expect($this->reader->row($j)['persisted_result'])->toBe('not_confirmed');
    expect($this->reader->query('review', ['queue' => 'unfinalized'])->pluck('id')->all())->toContain($j->id);
    $j->update(['status' => 'done', 'finished_at' => now()]);
    expect($this->reader->row($j)['persisted_result'])->toBe('persisted');
    $this->get(route('admin.operations', ['locale' => 'en', 'job' => $j->id]))->assertOk()->assertDontSee('PRIVATE_CUSTOMER_OUTPUT')->assertDontSee('PRIVATE_CUSTOMER_INPUT')->assertDontSee('signature=fixture');
    Http::assertNothingSent();
});

it('filters recovery states without adding mutations', function (string $queue, string $state, ?string $failure) {
    $j = p2Job($this->customer, values: ['status' => $state, 'failure_stage' => $failure, 'created_at' => now()->subHours(3)]);
    $j->forceFill(['created_at' => now()->subHours(3)])->save();
    p2Job($this->customer);
    expect($this->reader->query('review', ['queue' => $queue])->pluck('id')->all())->toBe([$j->id]);
})->with([['provider_submission_unknown', 'queued', 'provider_submission_unknown'], ['refund_pending', 'failed', 'refund_pending'], ['delete_failed', 'delete_failed', null], ['stuck', 'running', null]]);

it('shows reservation held settled and released amounts independently of App refunds', function () {
    $api = p2Api($this->customer, p2Job($this->customer));
    foreach (['reserved' => [100, 0, 0], 'settled' => [0, 70, 30], 'released' => [0, 0, 100]] as $state => $expected) {
        $r = ApiCreditReservation::create(['customer_id' => $this->customer->id, 'api_job_id' => $api->id, 'amount' => 100, 'status' => $state, 'meta' => ['final_amount' => 70, 'reference_code' => 'fixture-ref']]);
        $row = $this->reader->row($r);
        expect([$row['held_amount'], $row['settled_amount'], $row['released_amount']])->toBe($expected)->and($row['wallet_type'])->toBe('api');
        $r->delete();
    }
});

it('shows key and retention metadata while excluding secrets and storage keys', function () {
    $j = p2Job($this->customer);
    $api = p2Api($this->customer, $j);
    CustomerApiKey::create(['customer_id' => $this->customer->id, 'name' => 'Support fixture', 'key_prefix' => 'mk_fixture', 'key_hash' => 'DO_NOT_SHOW_HASH', 'scopes' => ['v2:ocr'], 'status' => 'active']);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'keys']))->assertOk()->assertSee('mk_fixture')->assertDontSee('DO_NOT_SHOW_HASH');
    $f = CustomerFile::create(['customer_id' => $this->customer->id, 'disk' => 's3', 'path' => 'PRIVATE_OBJECT_PATH/result.txt', 'purpose' => 'render', 'mime' => 'text/plain', 'size_bytes' => 123, 'status' => 'active', 'retention_mode' => 'temporary', 'expires_at' => now()->addDays(7), 'counts_toward_quota' => false, 'source_type' => 'api_job', 'source_id' => $api->id]);
    $link = ApiResultFile::create(['id' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'api_job_id' => $api->id, 'storage_file_id' => $f->id, 'result_kind' => 'text']);
    expect($this->reader->query('files', ['job' => $j->id])->count())->toBe(1);
    $row = $this->reader->row($f);
    expect($row['api_result_id'])->toBe($link->id)->and($row['retention_mode'])->toBe('temporary')->and($row['counts_toward_quota'])->toBeFalse();
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'files', 'job' => $j->id]))->assertOk()->assertDontSee('PRIVATE_OBJECT_PATH');
});

it('traces payment fulfillment order subscription ledger and redacted evidence with least privilege', function () {
    $plan = ServicePlan::where('code', 'student')->firstOrFail();
    $p = Payment::create(['uuid' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'provider' => 'fib', 'purchase_type' => 'plan_subscription', 'payment_mode' => 'one_time', 'provider_object_type' => 'payment', 'status' => 'paid', 'internal_status' => 'applied', 'amount' => 100, 'currency' => 'IQD', 'purchasable_type' => ServicePlan::class, 'purchasable_id' => $plan->id, 'local_reference' => 'P2-payment', 'idempotency_key' => (string) Str::uuid(), 'fulfilled_at' => now(), 'status_response' => ['status' => 'PAID', 'secret' => 'NEVER_SHOW_ME', 'untrusted_body' => 'NEVER_SHOW_ME'], 'fib_payment_id' => 'finance-evidence-reference']);
    $order = CreditOrder::create(['customer_id' => $this->customer->id, 'payment_id' => $p->id, 'order_type' => 'addon', 'status' => 'paid', 'credits_amount' => 100, 'amount_usd' => 1, 'currency' => 'IQD']);
    $sub = CustomerServiceSubscription::create(['customer_id' => $this->customer->id, 'payment_id' => $p->id, 'service_plan_id' => $plan->id, 'status' => 'expired', 'source' => 'fib']);
    CreditLedger::create(['customer_id' => $this->customer->id, 'wallet_type' => 'app', 'type' => 'addon', 'source_type' => 'addon', 'direction' => 'credit', 'bucket' => 'addon', 'amount' => 100, 'credits_delta' => 100, 'balance_after' => 100, 'related_type' => CreditOrder::class, 'related_id' => $order->id]);
    expect($this->reader->query('orders', ['payment' => (string) $p->id])->count())->toBe(1)
        ->and($this->reader->query('subscriptions', ['payment' => (string) $p->id])->value('id'))->toBe($sub->id)
        ->and($this->reader->query('ledger', ['payment' => (string) $p->id])->count())->toBe(1);
    $url = route('admin.operations', ['locale' => 'en', 'payment' => $p->id, 'section' => 'payments']);
    $this->get($url)->assertOk()->assertDontSee('finance-evidence-reference')->assertDontSee('NEVER_SHOW_ME');
    $this->operator->forceFill(['admin_capabilities' => ['admin.finance']])->save();
    $this->get($url)->assertOk()->assertSee('finance-evidence-reference')->assertDontSee('NEVER_SHOW_ME');
    Http::assertNothingSent();
});

it('bounds customer lookup and ledger pagination with database queries', function () {
    for ($i = 0; $i < 30; $i++) {
        Customer::create(['username' => 'lookup_'.$i, 'email' => 'lookup_'.$i.'@example.test', 'password' => 'fixture']);
        CreditLedger::create(['customer_id' => $this->customer->id, 'wallet_type' => $i % 2 ? 'app' : 'api', 'type' => 'test', 'source_type' => 'test', 'direction' => 'debit', 'bucket' => 'subscription', 'amount' => 1, 'credits_delta' => -1, 'balance_after' => 100 - $i]);
    }
    expect($this->reader->customerLookup(''))->toHaveCount(0)->and($this->reader->customerLookup('lookup'))->toHaveCount(20)
        ->and($this->reader->customerLookup('lookup', $this->customer->id))->toHaveCount(21);
    $q = $this->reader->query('ledger', ['customer' => $this->customer->id]);
    expect($q->paginate(25)->items())->toHaveCount(25)->and($this->reader->query('ledger', ['customer' => $this->customer->id, 'channel' => 'api', 'direction' => 'debit'])->count())->toBe(15);
});

it('restricts customer trace and rejects revoked support on Livewire updates', function () {
    $j = p2Job($this->customer);
    $other = Customer::create(['username' => 'other', 'email' => 'other@example.test', 'password' => 'fixture']);
    $this->get(route('admin.customers.detail', ['locale' => 'en', 'customer' => $other->id, 'job' => $j->id]))->assertNotFound();
    $component = Livewire::test('admin::pages.operations.adm-operations');
    $this->operator->forceFill(['status' => 0])->save();
    $component->call('$refresh')->assertForbidden();
});

it('renders localized operational labels and safe audit summaries', function (string $locale, string $direction) {
    AdminAuditEvent::create(['admin_id' => $this->operator->id, 'action' => 'customer.test', 'target_type' => Customer::class, 'target_id' => (string) $this->customer->id, 'reason' => 'Bearer NEVER_SHOW_ME', 'before_state' => ['status' => 0, 'secret' => 'NEVER_SHOW_ME'], 'after_state' => ['status' => 1]]);
    $this->get(route('admin.operations', ['locale' => $locale, 'section' => 'audit']))->assertOk()->assertSee('dir="'.$direction.'"', false)->assertDontSee('NEVER_SHOW_ME')->assertDontSee('admin_p2.');
})->with([['en', 'ltr'], ['ar', 'rtl'], ['ku', 'rtl']]);

it('does not hydrate generated input or output in job history queries', function () {
    $j = p2Job($this->customer, values: ['output' => ['text' => 'private text']]);
    $record = $this->reader->query('jobs', ['job' => $j->id])->firstOrFail();
    expect($record->getAttributes())->not->toHaveKeys(['input', 'output'])->and((int) $record->recorded_inline_result)->toBe(1);
});

it('keeps reservation review and payment review aligned with local recorded state', function () {
    $api = p2Api($this->customer);
    $api->update(['status' => 'failed']);
    $r = ApiCreditReservation::create(['customer_id' => $this->customer->id, 'api_job_id' => $api->id, 'status' => 'reserved', 'amount' => 20]);
    expect($this->reader->query('review', ['queue' => 'reservation_review'])->pluck('id')->all())->toBe([$r->id]);
    $r->update(['status' => 'released']);
    expect($this->reader->query('review', ['queue' => 'reservation_review'])->count())->toBe(0);
    $p = Payment::create(['uuid' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'provider' => 'fib', 'purchase_type' => 'plan_subscription', 'payment_mode' => 'one_time', 'provider_object_type' => 'payment', 'status' => 'pending', 'internal_status' => 'requires_review', 'amount' => 100, 'currency' => 'IQD', 'local_reference' => 'P2-review', 'idempotency_key' => (string) Str::uuid()]);
    expect($this->reader->query('review', ['queue' => 'payment_review'])->value('id'))->toBe($p->id);
    $p->update(['meta' => ['review_resolution' => ['closed_at' => now()->toIso8601String()]]]);
    expect($this->reader->query('review', ['queue' => 'payment_review'])->count())->toBe(0);
});

it('paginates pricing groups across stream batches while retaining canonical JSON and channel semantics', function () {
    $a = ToolAction::where('full_code', 'ocr.standard')->firstOrFail();
    for ($i = 0; $i < 90; $i++) {
        foreach (['app', 'mobile', 'api'] as $channel) {
            \App\Models\PricingRule::create(['tool_action_id' => $a->id, 'pricing_channel' => $channel, 'rule_scope' => 'global', 'rule_type' => 'unit', 'metric_code' => 'p2_stream_'.$i, 'unit_size' => 1, 'credits_per_unit' => 3, 'rounding_mode' => 'ceil', 'rounding_step' => 1, 'minimum_credits' => 1, 'priority' => 800, 'is_active' => true, 'conditions' => $channel === 'app' ? ['b' => 2, 'a' => 1] : ['a' => 1, 'b' => 2]]);
        }
    }
    $component = Livewire::test('admin::pages.services.adm-services-pricing')->set('search', 'p2_stream_')->set('perPage', 10);
    $groups = $component->instance()->groupedPricingRules;
    expect($groups->total())->toBe(90)->and($groups->items())->toHaveCount(10);
    $firstIds = collect($groups->items())->pluck('seed_rule_id')->all();
    $component->call('gotoPage', 2);
    $second = $component->instance()->groupedPricingRules;
    expect(array_intersect($firstIds, collect($second->items())->pluck('seed_rule_id')->all()))->toBe([]);
    foreach ($second->items() as $group) {
        expect($group['primary_rule_ids'])->toHaveCount(3);
    }
});

it('reuses current currency rates and schema capabilities within the Admin render', function () {
    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = strtolower($q->sql);
    });
    $this->get(route('admin.payments.currencies', ['locale' => 'en']))->assertOk();
    $rateReads = collect($statements)->filter(fn ($sql) => str_contains($sql, 'from "currency_exchange_rates"') && ! str_contains($sql, 'count('));
    expect($rateReads->count())->toBeLessThanOrEqual(2);
    $schemaReads = collect($statements)->filter(fn ($sql) => str_contains($sql, 'pragma_table_xinfo') && str_contains($sql, 'currencies'));
    expect($schemaReads->count())->toBeLessThanOrEqual(3);
    Http::assertNothingSent();
});

it('keeps the P2 translation catalogs aligned without changing other areas', function () {
    $en = require resource_path('lang/en/admin_p2.php');
    foreach (['ar', 'ku'] as $locale) {
        $messages = require resource_path('lang/'.$locale.'/admin_p2.php');
        expect(array_keys($messages))->toBe(array_keys($en));
        foreach ($messages as $message) {
            expect(trim($message))->not->toBe('');
        }
    }
});

it('preserves a failed audit event outcome after its operation later completes', function () {
    $operation = \App\Models\AdminOperation::create(['id' => (string) Str::uuid(), 'admin_id' => $this->operator->id, 'customer_id' => $this->customer->id, 'action' => 'fixture', 'payload_hash' => hash('sha256', 'fixture'), 'requested' => [], 'reason' => 'Isolated fixture', 'status' => 'completed']);
    $event = AdminAuditEvent::create(['admin_id' => $this->operator->id, 'operation_id' => $operation->id, 'action' => 'fixture.failed', 'target_type' => \App\Models\AdminOperation::class, 'target_id' => $operation->id, 'after_state' => ['status' => 'pending']]);
    $row = $this->reader->row($event);
    expect($row['outcome'])->toBe('failed')->and($row['operation_status'])->toBe('completed');
    expect($this->reader->query('audit', ['customer' => $this->customer->id])->value('id'))->toBe($event->id);
});
