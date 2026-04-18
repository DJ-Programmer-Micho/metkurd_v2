<?php

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\User;
use App\Services\Billing\PlanSwitcher;
use App\Services\Plans\PlanConcurrencyService;
use App\Services\Security\JobExecutionLockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    app(PlanConcurrencyService::class)->flushCache();
    $this->seed();
});

function planConcurrencyCustomer(?string $email = null, ?string $username = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => $username ?? "plan_limit_{$suffix}",
        'email' => $email ?? "plan-limit-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'wallet', 'activeServiceSubscription.servicePlan']);
}

function planConcurrencyAdmin(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin-plan-limits@example.com',
            'password' => 'Secret123!',
        ]);
    });
}

function assignServicePlan(Customer $customer, string $code): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan->fresh();
}

it('returns the configured concurrent job limit for the free plan', function () {
    $customer = planConcurrencyCustomer('free-plan-limit@example.com', 'free_plan_limit_user');
    $service = app(PlanConcurrencyService::class);

    expect($service->allowedConcurrentJobsForCustomer($customer->fresh()))
        ->toBe((int) ServicePlan::query()->where('code', 'free')->value('concurrent_jobs_limit'));
});

it('returns the configured concurrent job limit for the student plan', function () {
    $customer = planConcurrencyCustomer('student-plan-limit@example.com', 'student_plan_limit_user');
    $plan = assignServicePlan($customer, 'student');
    $service = app(PlanConcurrencyService::class);

    expect($service->allowedConcurrentJobsForCustomer($customer->fresh()))
        ->toBe((int) $plan->concurrent_jobs_limit);
});

it('returns the configured concurrent job limit for the pro plan', function () {
    $customer = planConcurrencyCustomer('pro-plan-limit@example.com', 'pro_plan_limit_user');
    $plan = assignServicePlan($customer, 'pro');
    $service = app(PlanConcurrencyService::class);

    expect($service->allowedConcurrentJobsForCustomer($customer->fresh()))
        ->toBe((int) $plan->concurrent_jobs_limit);
});

it('returns the configured concurrent job limit for the premium plan', function () {
    $customer = planConcurrencyCustomer('premium-plan-limit@example.com', 'premium_plan_limit_user');
    $plan = assignServicePlan($customer, 'premium');
    $service = app(PlanConcurrencyService::class);

    expect($service->allowedConcurrentJobsForCustomer($customer->fresh()))
        ->toBe((int) $plan->concurrent_jobs_limit);
});

it('updates plan concurrency limits from the admin page and refreshes cached runtime lookups', function () {
    $admin = planConcurrencyAdmin();
    $plan = ServicePlan::query()->where('code', 'student')->firstOrFail();
    $service = app(PlanConcurrencyService::class);

    expect($service->allowedConcurrentJobsForPlan('student'))->toBe((int) $plan->concurrent_jobs_limit);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.payments.adm-payments-plans')
        ->call('openEditPlanModal', (int) $plan->id)
        ->set('concurrentJobsLimit', 11)
        ->call('savePlan')
        ->assertHasNoErrors();

    expect(ServicePlan::query()->whereKey($plan->id)->value('concurrent_jobs_limit'))->toBe(11)
        ->and($service->allowedConcurrentJobsForPlan('student'))->toBe(11);
});

it('uses the configured concurrent job limit inside app pages instead of a hardcoded match block', function () {
    $customer = planConcurrencyCustomer('student-runtime-page@example.com', 'student_runtime_page_user');
    $plan = assignServicePlan($customer, 'student');
    $plan->update(['concurrent_jobs_limit' => 11]);

    $this->actingAs($customer->fresh(), 'app');

    $component = Livewire::test('app::pages.xtts.app-xtts');

    $allowedLimit = (function (): int {
        return $this->allowedConcurrentJobs();
    })->call($component->instance());

    expect($allowedLimit)->toBe(11);
});

it('enforces the configured concurrent job limit on the xtts page', function () {
    $customer = planConcurrencyCustomer('student-limit-enforcement@example.com', 'student_limit_enforcement_user');
    $plan = assignServicePlan($customer, 'student');
    $plan->update(['concurrent_jobs_limit' => 1]);

    $toolId = (int) Tool::query()->where('code', 'tts')->value('id');

    MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => (int) $customer->id,
        'tool_id' => $toolId,
        'status' => 'running',
        'job_kind' => 'tts',
        'started_at' => now(),
    ]);

    $this->actingAs($customer->fresh(), 'app');

    $component = Livewire::test('app::pages.xtts.app-xtts');
    $component->set('currentJobId', null);
    $component->set('currentStatus', null);
    $component->set('jobFinished', true);

    expect($component->instance()->generateBlockedReason())
        ->toBe(__('You reached your concurrent job limit for the current plan.'));
});

it('uses the configured concurrent job limit in backend lock enforcement', function () {
    $customer = planConcurrencyCustomer('student-lock-enforcement@example.com', 'student_lock_enforcement_user');
    $plan = assignServicePlan($customer, 'student');
    $plan->update(['concurrent_jobs_limit' => 1]);

    MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => (int) $customer->id,
        'status' => 'running',
        'job_kind' => 'wasr',
        'input_hash' => 'existing-audio',
        'lock_expires_at' => now()->addMinutes(30),
        'started_at' => now(),
    ]);

    $pendingJob = MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => (int) $customer->id,
        'status' => 'queued',
        'job_kind' => 'wasr',
        'started_at' => now(),
    ]);

    $session = app('session')->driver();
    $session->start();

    $lock = app(JobExecutionLockService::class)->acquireAsrLock(
        (int) $customer->id,
        (string) $pendingJob->id,
        'new-audio',
        $session,
        'PlanConcurrencyTest',
        '127.0.0.1',
        'wasr'
    );

    expect($lock['ok'] ?? null)->toBeFalse()
        ->and((string) ($lock['message'] ?? ''))->toContain('Please wait for one to finish');
});

it('falls back safely when the plan concurrency configuration is missing', function () {
    $service = app(PlanConcurrencyService::class);

    expect($service->allowedConcurrentJobsForPlan('missing-plan-code'))
        ->toBe(PlanConcurrencyService::DEFAULT_LIMIT);
});
