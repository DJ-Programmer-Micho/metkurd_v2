<?php

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\AdminAuditEvent;
use App\Models\AdminOperation;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\SubscriptionCreditAllocation;
use App\Models\User;
use App\Services\Admin\AdminOperations;
use App\Services\Billing\BillingReportingBoundary;
use App\Services\Billing\PaymentDomainCutover;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminBillingWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    $this->seed();
    $this->travelTo(now()->setDate(2026, 9, 27)->setTime(12, 0));
    $this->operator = User::forceCreate(['name' => 'Billing operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $this->actingAs($this->operator, 'admin');
    $this->customer = Customer::create(['username' => 'billing_fixture', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]);
    $this->reader = app(AdminOperations::class);
});

function billingWorkspacePayment(Customer $customer, array $values = []): Payment
{
    return Payment::create(array_replace(['uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'status' => 'pending', 'internal_status' => 'requires_review',
        'local_reference' => 'BILLING-'.Str::random(8), 'idempotency_key' => (string) Str::uuid(), 'amount' => 10000, 'currency' => 'IQD'], $values));
}

function billingWorkspaceBoundary(int $paymentId = 0, int $orderId = 0): void
{
    AdminAuditEvent::create(['admin_id' => auth('admin')->id(), 'action' => BillingReportingBoundary::ACTION,
        'target_type' => PaymentDomainCutover::class, 'target_id' => 'fixture', 'reason' => 'Isolated reporting boundary fixture.',
        'after_state' => ['reporting_boundary' => ['starts_at' => now()->subDay()->format('Y-m-d H:i:s'), 'payment_id' => $paymentId, 'credit_order_id' => $orderId]]]);
}

it('keeps current and retained future-dated legacy payments and orders separate', function () {
    $old = billingWorkspacePayment($this->customer, ['created_at' => now()->addDay()]);
    $order = CreditOrder::create(['customer_id' => $this->customer->id, 'order_type' => 'addon', 'status' => 'paid', 'credits_amount' => 300, 'base_amount_iqd' => 1000, 'created_at' => now()->addDay()]);
    billingWorkspaceBoundary($old->id, $order->id);
    $new = billingWorkspacePayment($this->customer);
    expect($this->reader->query('payments')->pluck('id')->all())->toBe([$new->id]);
    expect($this->reader->query('orders')->count())->toBe(0);
    $rows = app(AdminBillingWorkspace::class)->rows($this->reader->query('payments', ['financialEra' => 'legacy'])->get());
    expect($rows->pluck('id')->all())->toBe([$old->id])->and($rows[0]['financial_era'])->toBe('legacy');
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'orders', 'financialEra' => 'legacy']))
        ->assertOk()->assertSee(__('admin_billing.history_notice'))->assertSee('1000 IQD', false);
});

it('filters persisted review states and cases consistently and prioritizes actionable payments', function () {
    $review = billingWorkspacePayment($this->customer);
    $cancel = billingWorkspacePayment($this->customer, ['internal_status' => 'applied', 'meta' => ['provider_cancellation' => ['provider_cancel_pending' => true]]]);
    $process = billingWorkspacePayment($this->customer, ['internal_status' => 'paid_pending_application']);
    $failed = billingWorkspacePayment($this->customer, ['internal_status' => 'failed']);
    $closed = billingWorkspacePayment($this->customer, ['meta' => ['review_resolution' => ['closed_at' => now()->toIso8601String()]]]);
    $paid = billingWorkspacePayment($this->customer, ['internal_status' => 'applied']);
    foreach (['needs_review' => [$cancel->id, $review->id], 'processing' => [$process->id], 'failed' => [$failed->id], 'resolved' => [$paid->id, $closed->id]] as $state => $ids) {
        $models = $this->reader->query('payments', ['billingState' => $state])->get();
        expect($models->pluck('id')->all())->toBe($ids);
        expect(app(AdminBillingWorkspace::class)->rows($models)->pluck('billing_state')->unique()->all())->toBe([$state]);
    }
    expect($this->reader->query('review', ['queue' => 'payment_review'])->pluck('id')->all())->toBe([$cancel->id, $review->id]);
    expect($this->reader->query('payments', ['billingCase' => 'cancellation'])->pluck('id')->all())->toBe([$cancel->id]);
    expect($this->reader->query('payments')->limit(3)->pluck('id')->all())->toBe([$cancel->id, $review->id, $process->id]);
});

it('uses existing access authority rather than historical active status', function () {
    billingWorkspaceBoundary();
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $historical = CustomerServiceSubscription::create(['customer_id' => $this->customer->id, 'service_plan_id' => $plan->id, 'status' => 'active', 'source' => 'fib', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth()]);
    $grant = CustomerServiceSubscription::create(['customer_id' => $this->customer->id, 'service_plan_id' => $plan->id, 'status' => 'active', 'source' => 'admin_manual_grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'auto_renew' => false]);
    $rows = app(AdminBillingWorkspace::class)->rows($this->reader->query('subscriptions')->get())->keyBy('id');
    expect($rows[$historical->id]['effective_now'])->toBeFalse()->and($rows[$grant->id]['effective_now'])->toBeTrue();
    expect($rows[$grant->id]['source_label'])->toBe(__('admin_customer.source_grant'));
    expect($this->customer->servicePlanState()['subscription']->id)->toBe($grant->id);
    expect($this->reader->query('subscriptions', ['subscriptionAccess' => 'effective'])->pluck('id')->all())->toBe([$grant->id]);
    expect($this->reader->query('subscriptions', ['subscriptionAccess' => 'not_effective'])->pluck('id')->all())->toContain($historical->id)->not->toContain($grant->id);
});

it('renders all financial lists and selected evidence in every locale without writes or provider calls', function (string $locale) {
    $payment = billingWorkspacePayment($this->customer, ['status_response' => ['token' => 'DO_NOT_SHOW', 'status' => 'ACTIVE'], 'meta' => ['private_note' => 'DO_NOT_SHOW']]);
    CustomerServiceSubscription::create(['customer_id' => $this->customer->id, 'service_plan_id' => ServicePlan::where('code', 'pro')->value('id'), 'source' => 'admin_manual_grant', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    CreditOrder::create(['customer_id' => $this->customer->id, 'order_type' => 'addon', 'status' => 'paid', 'credits_amount' => 300, 'base_amount_iqd' => 1000]);
    PaymentEvent::create(['payment_id' => $payment->id, 'provider' => 'fib', 'source' => 'admin', 'event_type' => 'fixture_review', 'payload' => ['secret' => 'DO_NOT_SHOW']]);
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    foreach (['payments', 'subscriptions', 'storage_subscriptions', 'orders', 'review'] as $section) {
        $response = $this->get(route('admin.operations', ['locale' => $locale, 'section' => $section, 'queue' => 'payment_review']))->assertOk()->assertDontSee('admin_billing.')->assertDontSee('DO_NOT_SHOW');
        expect($response->getContent())->toContain('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"');
    }
    $this->get(route('admin.operations', ['locale' => $locale, 'section' => 'payments', 'payment' => $payment->id]))->assertOk()
        ->assertSee('fixture_review')->assertSee(__('admin_billing.events'))->assertDontSee('DO_NOT_SHOW');
    expect(collect($sql)->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q))->all())->toBe([]);
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('bounds event and allocation history and keeps it out of list queries', function () {
    $payment = billingWorkspacePayment($this->customer);
    $subscription = CustomerServiceSubscription::create(['customer_id' => $this->customer->id, 'service_plan_id' => ServicePlan::where('code', 'pro')->value('id'), 'payment_id' => $payment->id, 'status' => 'active']);
    foreach (range(1, 12) as $i) {
        PaymentEvent::create(['payment_id' => $payment->id, 'provider' => 'fib', 'source' => 'admin', 'event_type' => 'fixture_'.$i, 'payload' => ['private' => 'EXCLUDED']]);
        SubscriptionCreditAllocation::create(['customer_id' => $this->customer->id, 'subscription_id' => $subscription->id, 'payment_id' => $payment->id, 'cycle_key' => 'fixture_'.$i, 'allocation_type' => 'monthly', 'cycle_started_at' => now(), 'status' => 'applied']);
    }
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    app(AdminBillingWorkspace::class)->rows($this->reader->query('payments')->paginate(25)->getCollection());
    expect(collect($sql)->filter(fn ($q) => str_contains($q, 'payment_events') || str_contains($q, 'subscription_credit_allocations'))->all())->toBe([]);
    $evidence = app(AdminBillingWorkspace::class)->evidence($payment);
    expect($evidence['events']->total())->toBe(12)->and($evidence['events']->count())->toBe(10)->and($evidence['allocations']->count())->toBe(10);
    expect(json_encode($evidence))->not->toContain('EXCLUDED');
    $component = Livewire::withQueryParams(['section' => 'payments', 'payment' => (string) $payment->id])->test('admin::pages.operations.adm-operations');
    $component->call('setPage', 2, 'eventsPage');
    expect($component->instance()->billingEvidence['events']->currentPage())->toBe(2)
        ->and($component->instance()->billingEvidence['events']->count())->toBe(2)
        ->and($component->instance()->billingEvidence['allocations']->currentPage())->toBe(1);
    $component->call('setPage', 2, 'allocationsPage');
    expect($component->instance()->billingEvidence['allocations']->currentPage())->toBe(2);
});

it('gates detailed evidence and correction links while keeping read support and fresh authorization', function () {
    $payment = billingWorkspacePayment($this->customer);
    $this->operator->forceFill(['admin_capabilities' => ['admin.read']])->save();
    expect(app(AdminBillingWorkspace::class)->evidence($payment))->toBe(['restricted' => true]);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'payments', 'payment' => $payment->id]))->assertOk()
        ->assertSee(__('admin_billing.restricted'))->assertDontSee(__('admin_billing.open_workflow'));
    $this->operator->update(['status' => 0]);
    expect(fn () => app(AdminBillingWorkspace::class)->evidence($payment))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('links exact payment evidence audit and the existing scoped review modal', function () {
    $payment = billingWorkspacePayment($this->customer);
    $operation = AdminOperation::create(['id' => (string) Str::uuid(), 'admin_id' => $this->operator->id, 'customer_id' => $this->customer->id, 'action' => 'payment.fixture', 'payload_hash' => hash('sha256', 'fixture'), 'reason' => 'Isolated fixture', 'status' => 'completed', 'requested' => ['payment_id' => $payment->id]]);
    $audit = AdminAuditEvent::create(['admin_id' => $this->operator->id, 'operation_id' => $operation->id, 'action' => 'payment.fixture', 'target_type' => AdminOperation::class, 'target_id' => $operation->id, 'reason' => 'Isolated fixture']);
    expect($this->reader->query('audit', ['payment' => (string) $payment->id])->pluck('id')->all())->toContain($audit->id);
    $url = route('admin.customers.register', ['locale' => 'en', 'customer' => $this->customer->id, 'billingPayment' => $payment->id]);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'payments']))->assertOk()->assertSee($url);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'payments']))->assertSee(route('admin.operations', [
        'locale' => 'en', 'customerFilter' => $this->customer->id, 'payment' => $payment->id, 'financialEra' => 'current', 'section' => 'storage_subscriptions',
    ]));
    $this->get($url)->assertOk()->assertSee(__('admin_billing.selected_payment', ['id' => $payment->id]));
    Livewire::withQueryParams(['customer' => (string) $this->customer->id, 'billingPayment' => (string) $payment->id])
        ->test('admin::pages.customers.adm-customers-register')->call('openReviewPayment', $payment->id)->assertSet('reviewPaymentId', (string) $payment->id)
        ->assertDispatched('admin:modal-show', id: 'customer-payment-review')
        ->call('markReviewPaymentInvalid')->assertHasErrors(['reviewResolutionReason']);
    Http::assertNothingSent();
});

it('keeps dashboard payment review counts linked to their exact current queue', function () {
    $old = billingWorkspacePayment($this->customer);
    billingWorkspaceBoundary($old->id);
    billingWorkspacePayment($this->customer);
    $home = Livewire::test('admin::pages.home.app-home');
    expect($home->instance()->operationalOverview['payment_review'])->toBe($this->reader->query('review', ['queue' => 'payment_review'])->count())->toBe(1);
    $home->assertSee(route('admin.operations', ['locale' => app()->getLocale(), 'section' => 'review', 'queue' => 'payment_review']));
});

it('shows external agreement evidence with inclusive expiry and no fabricated payment', function () {
    $result = app(\App\Services\Admin\AdminServiceAgreements::class)->record((string) Str::uuid(), $this->customer->id,
        ServicePlan::where('code', 'pro')->value('id'), '2026-09-27', '2026-10-27', 20000, 'AGREEMENT-FIXTURE', 'Isolated agreement for read-only billing evidence.');
    $agreement = \App\Models\ServicePlanAgreement::findOrFail($result['agreement_id']);
    $rows = app(AdminBillingWorkspace::class)->rows($this->reader->query('subscriptions', ['billingRecord' => $agreement->subscription_id])->get());
    expect($rows[0]['agreement']['ends_at'])->toBe('2026-10-27')->and($rows[0]['payment_id'])->toBeNull()->and($rows[0]['effective_now'])->toBeTrue();
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'subscriptions', 'billingRecord' => $agreement->subscription_id]))->assertOk()
        ->assertSee('AGREEMENT-FIXTURE')->assertSee(__('admin_billing.agreement_help'))->assertSee(__('admin_billing.allocations'));
    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
});

it('bounds the payment page and batches customer names without hydrating event histories', function () {
    foreach (range(1, 27) as $i) {
        billingWorkspacePayment($this->customer);
    }
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    $page = $this->reader->query('payments')->paginate(AdminOperations::PAGE_SIZE);
    $rows = app(AdminBillingWorkspace::class)->rows($page->getCollection());
    expect($page->total())->toBe(27)->and($rows)->toHaveCount(25);
    expect(collect($sql)->filter(fn ($q) => str_contains($q, 'from "customers"'))->count())->toBe(1);
    expect(collect($sql)->filter(fn ($q) => str_contains($q, 'payment_events'))->count())->toBe(0);
});

it('keeps foreign customer traces and stale historical correction links out of the focused workflow', function () {
    $other = Customer::create(['username' => 'foreign_fixture', 'email' => Str::uuid().'@example.test', 'password' => 'fixture']);
    $payment = billingWorkspacePayment($other);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'payments', 'payment' => $payment->id, 'customerFilter' => $this->customer->id]))->assertNotFound();
    Livewire::withQueryParams(['customer' => (string) $this->customer->id, 'billingPayment' => (string) $payment->id])
        ->test('admin::pages.customers.adm-customers-register')->assertDontSee(__('admin_billing.selected_payment', ['id' => $payment->id]));
    billingWorkspaceBoundary($payment->id);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'review', 'queue' => 'payment_review', 'financialEra' => 'legacy']))->assertOk()
        ->assertSee($payment->local_reference)->assertDontSee(__('admin_billing.open_workflow'));
});

it('shows evidence conflicts using safe category copy and preserves the recorded problem privately', function () {
    $payment = billingWorkspacePayment($this->customer, ['mismatch_reason' => 'SQL private provider error DO_NOT_SHOW']);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'payments', 'billingCase' => 'provider_mismatch']))->assertOk()
        ->assertSee($payment->local_reference)->assertSee(__('admin_billing.provider_mismatch'))->assertDontSee('DO_NOT_SHOW');
    expect($payment->fresh()->mismatch_reason)->toBe('SQL private provider error DO_NOT_SHOW');
});
