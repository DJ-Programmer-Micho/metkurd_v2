<?php

use App\Models\AdminOperation;
use App\Models\CreditMonthlyGrant;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\PaymentIntent;
use App\Models\PlanEntitlement;
use App\Models\PlanVoiceAccess;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\User;
use App\Models\Voice;
use App\Services\Admin\AdminCatalogDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    $this->actingAs(User::forceCreate([
        'name' => 'Plan deletion operator', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture-password', 'status' => 1, 'admin_capabilities' => ['admin.pricing'],
    ]), 'admin');
});

function deletionFixturePlan(): ServicePlan
{
    return ServicePlan::create(['code' => 'delete_'.Str::random(12), 'name' => 'Disposable plan', 'is_active' => false]);
}

function deletionFixtureCustomer(): Customer
{
    return Customer::withoutEvents(fn () => Customer::create([
        'username' => 'delete_'.Str::random(12), 'email' => Str::uuid().'@example.test',
        'password' => 'fixture-password', 'status' => 1,
    ]));
}

function deletionFixtureAccess(ServicePlan $plan, ?Voice $voice = null): PlanVoiceAccess
{
    $voice ??= Voice::create(['code' => 'delete_'.Str::random(12), 'name' => 'Fixture voice']);

    return $plan->voiceAccesses()->create(['voice_id' => $voice->id, 'is_active' => false]);
}

it('never queries a nonexistent customer plan column during the final guard', function (bool $voiceAccess) {
    $plan = deletionFixturePlan();
    $target = $voiceAccess ? deletionFixtureAccess($plan) : $plan;
    expect(Schema::hasColumn('customers', 'service_plan_id'))->toBeFalse();

    // SQLite can treat double-quoted unknown columns as strings. Reject that SQL before execution.
    DB::connection()->beforeExecuting(function (string $sql) {
        $sql = str_replace(['"', '`', '[', ']'], '', strtolower($sql));
        if (preg_match('/\b(?:from|join)\s+customers\b/', $sql) && preg_match('/\bservice_plan_id\b/', $sql)) {
            throw new LogicException('Deletion guard queried nonexistent customers.service_plan_id.');
        }
    });

    expect(app(AdminCatalogDeletion::class)->hasDependencies($target))->toBeFalse();
    app(AdminCatalogDeletion::class)->delete($target);
    expect($target->fresh())->toBeNull();
})->with(['plan' => false, 'voice access' => true]);

it('rechecks current and retained subscription references after a safe preview', function (bool $voiceAccess, string $state) {
    $plan = deletionFixturePlan();
    $target = $voiceAccess ? deletionFixtureAccess($plan) : $plan;
    $guard = app(AdminCatalogDeletion::class);
    expect($guard->hasDependencies($target))->toBeFalse();
    // A previously loaded empty relationship must not hide the newly inserted reference.
    $plan->load('subscriptions', 'previousSubscriptions');
    if ($voiceAccess) {
        $target->setRelation('servicePlan', $plan);
    }
    $subscription = CustomerServiceSubscription::create([
        'customer_id' => deletionFixtureCustomer()->id,
        'service_plan_id' => $state === 'previous' ? deletionFixturePlan()->id : $plan->id,
        'previous_service_plan_id' => $state === 'previous' ? $plan->id : null,
        'status' => $state === 'previous' ? 'ended' : $state,
        'ends_at' => $state === 'active' ? null : now()->subYear(),
    ]);
    $history = $subscription->fresh()->getAttributes();

    expect($guard->hasDependencies($target))->toBeTrue();
    expect(fn () => $guard->delete($target))->toThrow(ValidationException::class);
    expect($target->fresh())->not->toBeNull()
        ->and($subscription->fresh()->getAttributes())->toBe($history);
})->with(['plan' => false, 'voice access' => true])->with(['active', 'canceled', 'expired', 'ended', 'previous']);

it('allows disposable resources despite subscriptions on another plan', function (bool $voiceAccess) {
    $plan = deletionFixturePlan();
    $otherPlan = deletionFixturePlan();
    $access = deletionFixtureAccess($otherPlan);
    CustomerServiceSubscription::create(['customer_id' => deletionFixtureCustomer()->id,
        'service_plan_id' => $otherPlan->id, 'status' => 'active']);
    // Even access to the same voice on a subscribed plan is independent of this plan's access.
    $target = $voiceAccess ? deletionFixtureAccess($plan, $access->voice) : $plan;

    app(AdminCatalogDeletion::class)->delete($target);
    expect($target->fresh())->toBeNull()->and($access->fresh())->not->toBeNull()
        ->and($otherPlan->subscriptions()->count())->toBe(1);
})->with(['plan' => false, 'voice access' => true]);

it('retains other catalog and financial plan dependencies without any subscription', function (string $dependency) {
    $plan = deletionFixturePlan();
    $customer = deletionFixtureCustomer();
    $tool = Tool::create(['code' => 'delete_'.Str::random(12), 'name' => 'Fixture tool']);
    $action = ToolAction::create(['tool_code' => $tool->code, 'action_code' => 'test',
        'full_code' => $tool->code.'.test', 'name' => 'Fixture action']);
    $record = match ($dependency) {
        'voice access' => deletionFixtureAccess($plan),
        'API entitlement' => PlanEntitlement::create(['service_plan_id' => $plan->id,
            'tool_action_id' => $action->id, 'entitlement_channel' => 'api', 'allowed' => false]),
        'inactive API price' => PricingRule::create(['service_plan_id' => $plan->id,
            'tool_action_id' => $action->id, 'pricing_channel' => 'api', 'metric_code' => 'character', 'is_active' => false]),
        'retained grant' => CreditMonthlyGrant::create(['service_plan_id' => $plan->id,
            'customer_id' => $customer->id, 'year_month' => '2025-01', 'granted_credits' => 1]),
        'refunded order' => CreditOrder::create(['service_plan_id' => $plan->id,
            'customer_id' => $customer->id, 'order_type' => 'subscription', 'source_type' => 'service_plan',
            'status' => 'refunded', 'credits_amount' => 1, 'amount_usd' => 1]),
        'pending intent' => PaymentIntent::create(['uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id, 'provider' => 'fake', 'purpose_type' => 'service_plan',
            'purpose_id' => $plan->id, 'base_amount_iqd' => 1, 'gross_amount_iqd' => 1,
            'idempotency_key' => (string) Str::uuid(), 'merchant_transaction_id' => (string) Str::uuid()]),
        'pending Admin grant' => AdminOperation::create(['id' => (string) Str::uuid(),
            'admin_id' => auth('admin')->id(), 'customer_id' => $customer->id, 'action' => 'grant.plan',
            'payload_hash' => hash('sha256', 'fixture'), 'requested' => ['plan_id' => $plan->id],
            'reason' => 'Fixture pending plan grant']),
    };

    expect($plan->subscriptions()->exists())->toBeFalse();
    expect(fn () => app(AdminCatalogDeletion::class)->delete($plan))->toThrow(ValidationException::class);
    expect($plan->fresh())->not->toBeNull()->and($record->fresh())->not->toBeNull();
})->with(['voice access', 'API entitlement', 'inactive API price', 'retained grant', 'refunded order', 'pending intent', 'pending Admin grant']);
