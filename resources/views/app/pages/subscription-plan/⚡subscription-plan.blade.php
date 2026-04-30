{{-- resources/views/app/pages/subscription-plan/⚡subscription-plan.blade.php --}}
<?php

use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Models\ServicePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\Billing\ScheduleServicePlanCancellation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public array $plans = [];

    public ?int $currentPlanId = null;

    public ?int $selectedPlanId = null;

    public string $billingCycle = 'monthly';

    public string $selectedBillingCycle = 'monthly';

    public string $displayCurrencyCode = 'IQD';

    public string $displayCurrencySource = 'default';

    public bool $hourlyTestingEnabled = false;

    public bool $showConfirm = false;

    public bool $showCancelConfirm = false;

    public bool $processing = false;

    public bool $canCancelCurrentPlan = false;

    public bool $currentPlanCancellationScheduled = false;

    public ?string $currentPlanEndsAtLabel = null;

    public string $message = '';

    public string $messageType = 'info';

    public function mount(): void
    {
        $this->loadData();
    }

    protected function loadData(): void
    {
        $customer = auth('app')->user();
        $customer = $customer?->fresh(['profile']);

        if (! $customer) {
            return;
        }

        $currency = app(BillingCurrencyService::class);
        $fibSubscriptions = app(FibSubscriptionService::class);
        $displayContext = $currency->resolveDisplayContext($customer);
        $state = app(CustomerBillingStateService::class)->servicePlanState($customer);

        $this->displayCurrencyCode = (string) ($displayContext['currency_code'] ?? 'IQD');
        $this->displayCurrencySource = (string) ($displayContext['source'] ?? 'default');
        $this->hourlyTestingEnabled = $fibSubscriptions->hourlyTestingEnabled();
        $this->billingCycle = $this->normalizeBillingCycle($this->billingCycle);
        $this->selectedBillingCycle = $this->normalizeBillingCycle($this->selectedBillingCycle);
        $this->syncCurrentPlanState($state);

        $hideFreePlan = (bool) ($state['should_hide_free_plan'] ?? false);

        $this->plans = ServicePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->reject(fn (ServicePlan $plan) => $hideFreePlan && (bool) $plan->is_free)
            ->map(function (ServicePlan $plan) use ($currency, $customer) {
                $monthlyDisplay = $currency->priceDataForBaseAmountIqd($plan->priceIqdForCycle('monthly'), $customer);
                $yearlyDisplay = $currency->priceDataForBaseAmountIqd($plan->priceIqdForCycle('yearly'), $customer);
                $ui = $plan->localizedUiFeatures();
                $isCurrent = (int) $plan->id === (int) $this->currentPlanId;

                return [
                    'id' => (int) $plan->id,
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'billing_intervals' => $plan->billingIntervals(),
                    'billing_interval' => (string) ($plan->billing_interval ?? 'monthly'),
                    'payment_mode' => $plan->checkoutPaymentModeValue(),
                    'monthly_credits' => (int) ($plan->monthly_credits ?? 0),
                    'is_free' => (bool) ($plan->is_free ?? false),
                    'price_iqd_monthly' => $plan->priceIqdForCycle('monthly'),
                    'price_iqd_yearly' => $plan->priceIqdForCycle('yearly'),
                    'price_iqd_hourly' => $plan->priceIqdForCycle('monthly'),
                    'display_monthly' => $monthlyDisplay,
                    'display_yearly' => $yearlyDisplay,
                    'display_hourly' => $monthlyDisplay,
                    'ui_features' => $ui,
                    'feature_list' => is_array($ui['features'] ?? null) ? array_values($ui['features']) : [],
                    'is_current' => $isCurrent,
                    'can_cancel' => $isCurrent && $this->canCancelCurrentPlan,
                    'cancellation_scheduled' => $isCurrent && $this->currentPlanCancellationScheduled,
                    'access_until_label' => $isCurrent ? $this->currentPlanEndsAtLabel : null,
                ];
            })
            ->values()
            ->all();
    }

    protected function syncCurrentPlanState(array $state): void
    {
        $this->currentPlanId = (int) ($state['current_plan_id'] ?? 0) ?: null;
        $this->canCancelCurrentPlan = (bool) ($state['cancelable'] ?? false);
        $this->currentPlanCancellationScheduled = (bool) ($state['cancellation_scheduled'] ?? false);
        $this->currentPlanEndsAtLabel = $this->formatDateLabel($state['period_ends_at'] ?? null);
    }

    public function openConfirm(int $planId, string $billingCycle = 'monthly'): void
    {
        $selectedPlan = collect($this->plans)->firstWhere('id', $planId);

        if (! is_array($selectedPlan)) {
            return;
        }

        $resolvedCycle = $this->normalizeBillingCycle($billingCycle);

        if (! $this->planSupportsCycle($selectedPlan, $resolvedCycle)) {
            if ($this->planSupportsCycle($selectedPlan, 'monthly')) {
                $resolvedCycle = 'monthly';
            } elseif ($this->planSupportsCycle($selectedPlan, 'yearly')) {
                $resolvedCycle = 'yearly';
            } elseif ($this->planSupportsCycle($selectedPlan, 'lifetime')) {
                $resolvedCycle = 'lifetime';
            } else {
                $this->messageType = 'danger';
                $this->message = __('This billing cycle is not available for the selected plan.');

                return;
            }
        }

        $this->selectedPlanId = $planId;
        $this->billingCycle = $resolvedCycle;
        $this->selectedBillingCycle = $this->billingCycle;
        $this->message = '';
        $this->messageType = 'info';
        $this->showConfirm = true;
    }

    public function closeConfirm(): void
    {
        if ($this->processing) {
            return;
        }

        $this->showConfirm = false;
        $this->selectedPlanId = null;
        $this->selectedBillingCycle = $this->normalizeBillingCycle($this->billingCycle);
    }

    public function openCancelConfirm(): void
    {
        if ($this->processing || ! $this->canCancelCurrentPlan) {
            return;
        }

        $this->message = '';
        $this->messageType = 'info';
        $this->showCancelConfirm = true;
    }

    public function closeCancelConfirm(): void
    {
        if ($this->processing) {
            return;
        }

        $this->showCancelConfirm = false;
    }

    public function confirmCancelPlan(): void
    {
        if ($this->processing) {
            return;
        }

        $customer = auth('app')->user();

        if (! $customer) {
            return;
        }

        $this->processing = true;
        $this->message = '';

        try {
            app(ScheduleServicePlanCancellation::class)->handle($customer);

            $this->closeCancelConfirm();
            $this->loadData();

            $this->messageType = 'success';
            $this->message = __('Cancellation scheduled. Your paid access remains active until :date.', [
                'date' => $this->currentPlanEndsAtLabel ?? __('the end of the current billing period'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->messageType = 'danger';
            $this->message = collect($exception->errors())->flatten()->first() ?: __('Could not schedule the cancellation.');
        } catch (\Throwable $exception) {
            $this->messageType = 'danger';
            $this->message = __('Failed to schedule the cancellation: :message', ['message' => $exception->getMessage()]);
        } finally {
            $this->processing = false;
        }
    }

    public function confirmChange()
    {
        $customer = auth('app')->user();
        $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);

        if (! $this->selectedPlanId) {
            return;
        }

        if (! $selectedPlan) {
            $this->messageType = 'danger';
            $this->message = __('Selected service plan was not found.');

            return;
        }

        if ((int) $this->selectedPlanId === (int) $this->currentPlanId) {
            $this->messageType = 'info';
            $this->message = __('This is already your current plan.');

            return;
        }

        $billingCycle = $this->resolvePlanBillingCycle($selectedPlan, $this->selectedBillingCycle);

        $this->processing = true;
        $this->message = '';
        $this->messageType = 'info';

        try {
            $payment = app(CreatePlanSubscriptionPayment::class)->handle(
                $customer,
                (int) $this->selectedPlanId,
                $billingCycle,
            );

            $this->showConfirm = false;

            return $this->redirectRoute('payments.fib.show', [
                'locale' => app()->getLocale(),
                'payment' => $payment,
            ], navigate: true);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->messageType = 'danger';
            $this->message = collect($exception->errors())->flatten()->first() ?: __('Could not start the payment.');
        } catch (\Throwable $exception) {
            Log::error('Failed to start service plan checkout.', [
                'customer_id' => (int) ($customer?->id ?? 0),
                'service_plan_id' => (int) $this->selectedPlanId,
                'billing_cycle' => $billingCycle,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $this->messageType = 'danger';
            $this->message = __('Failed to start the payment right now. Please try again shortly.');
        } finally {
            $this->processing = false;
        }
    }

    public function render()
    {
        $customer = auth('app')->user()->fresh(['wallet']);
        $wallet = $customer->wallet()->first();
        $state = app(CustomerBillingStateService::class)->servicePlanState($customer);
        $currentPlan = $state['current_plan'];

        $combinedBalance = (int) ($wallet?->balance_credits ?? 0);
        $subscriptionBalance = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addonBalance = (int) ($wallet?->addon_balance_credits ?? 0);
        $monthlyCredits = (int) ($currentPlan?->monthly_credits ?? 0);
        $creditsPct = $monthlyCredits > 0
            ? min(100, (int) round(($subscriptionBalance / $monthlyCredits) * 100))
            : 0;

        return view('app.pages.subscription-plan.⚡subscription-plan', [
            'combinedBalance' => $combinedBalance,
            'subscriptionBalance' => $subscriptionBalance,
            'addonBalance' => $addonBalance,
            'monthlyCredits' => $monthlyCredits,
            'creditsPct' => $creditsPct,
            'planCode' => (string) ($currentPlan?->code ?? 'free'),
            'planName' => (string) ($currentPlan?->name ?? __('Free')),
            'currentPlanCancellationScheduled' => (bool) ($state['cancellation_scheduled'] ?? false),
            'currentPlanEndsAtLabel' => $this->formatDateLabel($state['period_ends_at'] ?? null),
        ]);
    }

    private function normalizeBillingCycle(?string $cycle): string
    {
        $cycle = strtolower(trim((string) $cycle));
        $allowed = ['monthly', 'yearly'];

        if ($this->hourlyTestingEnabled) {
            $allowed[] = 'hourly';
        }

        return in_array($cycle, $allowed, true) ? $cycle : 'monthly';
    }

    public function resolvePlanBillingCycle(array $plan, ?string $requestedCycle = null): string
    {
        $requested = $this->normalizeBillingCycle($requestedCycle);

        if ($this->planSupportsCycle($plan, $requested)) {
            return $requested;
        }

        foreach (['monthly', 'yearly', 'lifetime'] as $fallback) {
            if ($this->planSupportsCycle($plan, $fallback)) {
                return $fallback;
            }
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            'billing_cycle' => __('This billing cycle is not available for the selected plan.'),
        ]);
    }

    public function planSupportsCycle(array $plan, string $cycle): bool
    {
        $normalized = strtolower(trim($cycle));
        $normalized = $normalized === 'hourly' ? 'monthly' : $normalized;
        $allowed = ['monthly', 'yearly', 'lifetime'];

        if (! in_array($normalized, $allowed, true)) {
            return false;
        }

        $intervals = collect(data_get($plan, 'billing_intervals', []))
            ->map(fn ($interval) => strtolower(trim((string) $interval)))
            ->filter(fn (string $interval) => in_array($interval, $allowed, true))
            ->unique()
            ->values()
            ->all();

        if ($intervals === []) {
            $legacy = strtolower(trim((string) data_get($plan, 'billing_interval', 'monthly')));
            $intervals = in_array($legacy, $allowed, true) ? [$legacy] : ['monthly'];
        }

        return in_array($normalized, $intervals, true);
    }

    public function billingCycleLabel(string $cycle): string
    {
        return match ($cycle) {
            'hourly' => 'Hourly Test',
            'yearly' => 'Yearly',
            'lifetime' => 'Lifetime',
            default => 'Monthly',
        };
    }

    public function planPriceForCycle(array $plan, string $cycle): int
    {
        return match ($cycle) {
            'yearly' => (int) ($plan['price_iqd_yearly'] ?? 0),
            'hourly' => (int) ($plan['price_iqd_hourly'] ?? $plan['price_iqd_monthly'] ?? 0),
            default => (int) ($plan['price_iqd_monthly'] ?? 0),
        };
    }

    protected function formatDateLabel(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date)->timezone(config('app.timezone'))->format('Y-m-d H:i');
        }

        if (is_scalar($date) && trim((string) $date) !== '') {
            try {
                return Carbon::parse((string) $date)
                    ->timezone(config('app.timezone'))
                    ->format('Y-m-d H:i');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

};
?>

<x-slot:title>{{ __('Subscription Plan') }} | {{ __('MET KURD') }}</x-slot:title>

@php
    $planCollection = collect($plans);
    $extractIntervals = static function (array $plan): array {
        $allowed = ['monthly', 'yearly', 'lifetime'];
        $intervals = collect($plan['billing_intervals'] ?? [])
            ->map(fn ($interval) => strtolower(trim((string) $interval)))
            ->filter(fn (string $interval) => in_array($interval, $allowed, true))
            ->unique()
            ->values()
            ->all();

        if ($intervals === []) {
            $legacy = strtolower(trim((string) ($plan['billing_interval'] ?? 'monthly')));
            $intervals = in_array($legacy, $allowed, true) ? [$legacy] : ['monthly'];
        }

        return $intervals;
    };
    $monthlyPlanCount = $planCollection
        ->filter(fn (array $plan) => in_array('monthly', $extractIntervals($plan), true) || in_array('lifetime', $extractIntervals($plan), true))
        ->count();
    $yearlyPlanCount = $planCollection
        ->filter(fn (array $plan) => in_array('yearly', $extractIntervals($plan), true) || in_array('lifetime', $extractIntervals($plan), true))
        ->count();
    $defaultBillingCycle = $monthlyPlanCount > 0
        ? 'monthly'
        : ($yearlyPlanCount > 0 ? 'yearly' : 'monthly');
@endphp

<div x-data="{ billingCycle: @js($defaultBillingCycle) }">
    <div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <div class="text-center mb-4 pb-2">
                <h4 class="fs-22">{{ __('Subscription (Service Credits)') }}</h4>
                <p class="text-muted mb-2 fs-15">
                    {{ __('Current plan: :code - :name', ['code' => strtoupper($planCode), 'name' => $planName]) }}
                </p>

                <div class="d-flex justify-content-center flex-wrap gap-3 small">
                    <div>
                        <span class="text-muted">{{ __('Combined Balance:') }}</span>
                        <b>{{ number_format($combinedBalance) }}</b>
                    </div>
                    <div>
                        <span class="text-muted">{{ __('Subscription Credits:') }}</span>
                        <b>{{ number_format($subscriptionBalance) }}</b>
                    </div>
                    <div>
                        <span class="text-muted">{{ __('Add-on Credits:') }}</span>
                        <b>{{ number_format($addonBalance) }}</b>
                    </div>
                </div>

                <div class="mx-auto mt-3" style="max-width: 420px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted">{{ __('Monthly subscription bucket') }}</small>
                        <small class="fw-semibold">{{ number_format($subscriptionBalance) }} / {{ number_format($monthlyCredits) }}</small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar" role="progressbar"
                             style="width: {{ $creditsPct }}%;"
                             aria-valuenow="{{ $creditsPct }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>

                @if($currentPlanCancellationScheduled && $currentPlanEndsAtLabel)
                    <div class="alert alert-warning mt-3 mb-0">
                        {{ __('Cancellation scheduled. Your paid access remains active until :date.', ['date' => $currentPlanEndsAtLabel]) }}
                    </div>
                @endif

                @if($message)
                    <div class="alert alert-{{ $messageType }} mt-3 mb-0">{{ $message }}</div>
                @endif

                @if($displayCurrencyCode !== 'IQD')
                    <div class="text-muted small mt-3">
                        {{ __('Local display currency: :currency', ['currency' => $displayCurrencyCode]) }}
                        <span class="ms-1">{{ __('resolved from :source', ['source' => str_replace('_', ' ', $displayCurrencySource)]) }}</span>
                    </div>
                @else
                    <div class="text-muted small mt-3">
                        {{ __('Pricing is shown in IQD as the canonical billing currency.') }}
                    </div>
                @endif

                @if($hourlyTestingEnabled)
                    <div class="alert alert-info mt-3 mb-0 text-start">
                        <div class="fw-semibold">{{ __('Hourly renewal is enabled for testing only.') }}</div>
                        <div class="small mt-1">{{ __('This uses the existing plan price with a fast hourly provider interval so recurring renewals, cancel-at-period-end, and downgrade behavior can be verified without waiting for a monthly cycle.') }}</div>
                    </div>
                @endif

                <div class="d-flex justify-content-center mt-4">
                    <div class="btn-group" role="group" aria-label="{{ __('Billing cycle') }}">
                        <button type="button"
                                class="btn"
                                :class="billingCycle === 'monthly' ? 'btn-primary' : 'btn-outline-primary'"
                                x-on:click="billingCycle = 'monthly'">
                            {{ __('Monthly') }}
                        </button>
                        <button type="button"
                                class="btn"
                                :class="billingCycle === 'yearly' ? 'btn-primary' : 'btn-outline-primary'"
                                x-on:click="billingCycle = 'yearly'">
                            {{ __('Yearly') }}
                        </button>
                        @if($hourlyTestingEnabled)
                            <button type="button"
                                    class="btn"
                                    :class="billingCycle === 'hourly' ? 'btn-primary' : 'btn-outline-primary'"
                                    x-on:click="billingCycle = 'hourly'">
                                {{ __('Hourly Test') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center ">
        <div class="col-xl-12">
            <div class="row justify-content-center">
                @foreach($plans as $p)
                    @php
                        $planMode = strtolower((string) ($p['payment_mode'] ?? 'one_time'));
                        $planIntervals = $extractIntervals($p);
                        $supportsMonthly = in_array('monthly', $planIntervals, true);
                        $supportsYearly = in_array('yearly', $planIntervals, true);
                        $supportsLifetime = in_array('lifetime', $planIntervals, true);
                        $isManualPayment = $planMode === 'one_time';
                        $isAutoRenewal = $planMode === 'recurring';
                        $isLifetimeOnlyPlan = $supportsLifetime && ! $supportsMonthly && ! $supportsYearly;
                        $showInMonthlyTab = $supportsMonthly || $supportsLifetime;
                        $showInYearlyTab = $supportsYearly || $supportsLifetime;
                        $showInHourlyTab = $hourlyTestingEnabled && $supportsMonthly && $isAutoRenewal;
                        $monthlyPrice = (string) data_get($p, 'display_monthly.display_label', data_get($p, 'display_monthly.iqd_label', ''));
                        $yearlyPrice = (string) data_get($p, 'display_yearly.display_label', data_get($p, 'display_yearly.iqd_label', ''));
                        $hourlyPrice = (string) data_get($p, 'display_hourly.display_label', data_get($p, 'display_hourly.iqd_label', ''));
                        $monthlyBase = (string) data_get($p, 'display_monthly.iqd_label', '');
                        $yearlyBase = (string) data_get($p, 'display_yearly.iqd_label', '');
                        $hourlyBase = (string) data_get($p, 'display_hourly.iqd_label', '');
                        $showMonthlyBase = (bool) data_get($p, 'display_monthly.has_localized_estimate', false);
                        $showYearlyBase = (bool) data_get($p, 'display_yearly.has_localized_estimate', false);
                        $showHourlyBase = (bool) data_get($p, 'display_hourly.has_localized_estimate', false);
                        $confirmCycleLiteral = $isLifetimeOnlyPlan
                            ? "'lifetime'"
                            : "(billingCycle === 'hourly' ? 'hourly' : billingCycle)";
                    @endphp

                    <div class="col-xl-3 col-lg-4 col-md-6"
                         x-show="(billingCycle === 'monthly' && @js($showInMonthlyTab))
                              || (billingCycle === 'yearly' && @js($showInYearlyTab))
                              || (billingCycle === 'hourly' && @js($showInHourlyTab))">
                        <div class="card pricing-box {{ $p['is_current'] ? 'border border-success shadow-sm' : '' }}">
                            <div class="card-body p-4 m-2">
                                <div class="d-flex align-items-start">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">{{ $p['name'] }}</h5>
                                        <p class="text-muted mb-1">{{ strtoupper($p['code']) }}</p>

                                        @if($p['is_free'])
                                            <span class="badge bg-soft-success text-success">{{ __('Free') }}</span>
                                        @else
                                            <span class="badge bg-soft-info text-info">{{ __('Paid Plan') }}</span>
                                        @endif

                                        @if($p['cancellation_scheduled'])
                                            <span class="badge bg-soft-warning text-warning">{{ __('Cancellation Scheduled') }}</span>
                                        @endif

                                        @if($isLifetimeOnlyPlan)
                                            <span class="badge bg-soft-secondary text-secondary">{{ __('Lifetime') }}</span>
                                        @else
                                            <span class="badge bg-soft-secondary text-secondary"
                                                  x-text="billingCycle === 'yearly' ? @js(__('Yearly')) : @js(__('Monthly'))">
                                                {{ __('Monthly') }}
                                            </span>
                                        @endif

                                        @if($isAutoRenewal)
                                            <span class="badge bg-soft-primary text-primary">{{ __('Auto Renewal') }}</span>
                                        @else
                                            <span class="badge bg-soft-warning text-warning">{{ __('Manual Payment') }}</span>
                                            <span class="badge bg-soft-secondary text-secondary">{{ __('No Auto-Renew') }}</span>
                                        @endif
                                    </div>

                                    <div class="ms-auto text-end">
                                        <div class="fw-semibold">{{ number_format($p['monthly_credits']) }} {{ __('credits') }}</div>
                                        @if($isLifetimeOnlyPlan)
                                            <div class="text-muted fs-12">{{ __('lifetime plan') }}</div>
                                        @else
                                            <div class="text-muted fs-12"
                                                 x-text="billingCycle === 'hourly' ? @js(__('per test hour')) : (billingCycle === 'yearly' ? @js(__('per year')) : @js(__('per month')))">
                                                {{ __('per month') }}
                                            </div>
                                        @endif
                                        @if($isManualPayment && ! $isLifetimeOnlyPlan)
                                            <div class="text-muted fs-12"
                                                 x-text="billingCycle === 'yearly' ? @js(__('Pay manually each year')) : @js(__('Pay manually each month'))">
                                                {{ __('Pay manually each month') }}
                                            </div>
                                        @endif

                                        @if(!$p['is_free'])
                                            @if($isLifetimeOnlyPlan)
                                                <div class="mt-1 text-muted fs-12">{{ $monthlyPrice }}</div>
                                                @if($showMonthlyBase)
                                                    <div class="mt-1 text-muted fs-12">{{ $monthlyBase }}</div>
                                                @endif
                                            @else
                                                <div class="mt-1 text-muted fs-12"
                                                     x-text="(billingCycle === 'yearly' ? '{{ $yearlyPrice }}' : (billingCycle === 'hourly' ? '{{ $hourlyPrice }}' : '{{ $monthlyPrice }}')) + (billingCycle === 'yearly' ? '/yr' : (billingCycle === 'hourly' ? '/hr' : '/mo'))">
                                                    {{ $monthlyPrice }}/mo
                                                </div>
                                                @if($showMonthlyBase || $showYearlyBase || $showHourlyBase)
                                                    <div class="mt-1 text-muted fs-12"
                                                         x-text="billingCycle === 'yearly' ? '{{ $yearlyBase }}' : (billingCycle === 'hourly' ? '{{ $hourlyBase }}' : '{{ $monthlyBase }}')">
                                                        {{ $monthlyBase }}
                                                    </div>
                                                @endif
                                            @endif
                                        @endif
                                    </div>
                                </div>

                                <hr class="my-4 text-muted">

                                <ul class="list-unstyled text-muted vstack gap-2 mb-0">
                                    @forelse($p['feature_list'] as $f)
                                        <li class="d-flex">
                                            <div class="flex-shrink-0 text-success me-1">
                                                <i class="ri-checkbox-circle-fill fs-15 align-middle"></i>
                                            </div>
                                            <div class="flex-grow-1">{{ $f }}</div>
                                        </li>
                                    @empty
                                        <li class="text-muted">{{ __('No features listed.') }}</li>
                                    @endforelse
                                </ul>

                                <div class="mt-4">
                                    @if($p['is_current'])
                                        <div class="d-grid gap-2">
                                            <button class="btn btn-success w-100" disabled>
                                                {{ $p['cancellation_scheduled'] ? __('Current Plan') : __('Your Current Plan') }}
                                            </button>

                                            @if($p['cancellation_scheduled'])
                                                <div class="small text-muted text-center">
                                                    {{ __('Access remains until :date', ['date' => $p['access_until_label'] ?: __('the current period end')]) }}
                                                </div>
                                            @elseif($p['can_cancel'])
                                                <button class="btn btn-outline-danger w-100"
                                                        wire:click="openCancelConfirm"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openCancelConfirm,confirmCancelPlan">
                                                    {{ __('Cancel Plan') }}
                                                </button>
                                            @endif
                                        </div>
                                    @else
                                        <button class="btn btn-info w-100"
                                                x-on:click="$wire.openConfirm({{ $p['id'] }}, {!! $confirmCycleLiteral !!})"
                                                wire:loading.attr="disabled"
                                                wire:target="openConfirm">
                                            {{ __('Change Plan') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
                <div class="col-12" x-show="billingCycle === 'monthly' && @js($monthlyPlanCount === 0)">
                    <div class="alert alert-light border text-center text-muted mb-0">
                        {{ __('No Monthly plans are currently available.') }}
                    </div>
                </div>
                <div class="col-12" x-show="billingCycle === 'yearly' && @js($yearlyPlanCount === 0)">
                    <div class="alert alert-light border text-center text-muted mb-0">
                        {{ __('No Yearly plans are currently available.') }}
                    </div>
                </div>
            </div>

            @if($showConfirm)
                @php
                    $selected = collect($plans)->firstWhere('id', $selectedPlanId);
                    $selectedCycle = is_array($selected)
                        ? $this->resolvePlanBillingCycle($selected, $selectedBillingCycle)
                        : 'monthly';
                    $selectedBillingIntervalLabel = __($this->billingCycleLabel($selectedCycle));
                    $selectedPrice = is_array($selected) ? $this->planPriceForCycle($selected, $selectedCycle) : 0;
                    $selectedDisplay = is_array($selected)
                        ? match ($selectedCycle) {
                            'yearly' => $selected['display_yearly'] ?? null,
                            'hourly' => $selected['display_hourly'] ?? null,
                            default => $selected['display_monthly'] ?? null,
                        }
                        : null;
                    $selectedPaymentMode = strtolower((string) data_get($selected, 'payment_mode', 'recurring'));
                    $selectedUsesRecurring = $selectedPaymentMode === 'recurring';
                @endphp

                <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">{{ __('Confirm Plan Change') }}</h5>
                                <button type="button" class="btn-close" wire:click="closeConfirm" @disabled($processing)></button>
                            </div>

                            <div class="modal-body">
                                @if($selected)
                                    <p class="mb-2">
                                        {{ __('You are switching to:') }}
                                        <b>{{ $selected['name'] }}</b>
                                        ({{ strtoupper($selected['code']) }})
                                    </p>
                                    <p class="mb-2">
                                        {{ __('Billing cycle:') }}
                                        <b>{{ $selectedBillingIntervalLabel }}</b>
                                    </p>
                                    <p class="mb-2">
                                        {{ __('Renewal type:') }}
                                        <b>{{ $selectedUsesRecurring ? __('Auto Renewal') : __('Manual Payment') }}</b>
                                    </p>
                                    @if(! $selectedUsesRecurring && $selectedCycle !== 'lifetime')
                                        <p class="mb-2 text-muted small">
                                            {{ $selectedCycle === 'yearly' ? __('Pay manually each year') : __('Pay manually each month') }}
                                        </p>
                                    @endif
                                    <p class="mb-2">
                                        {{ __('New monthly subscription credits:') }}
                                        <b>{{ number_format($selected['monthly_credits']) }}</b>
                                    </p>
                                    <p class="mb-2">
                                        {{ __('Price:') }}
                                        <b>{{ data_get($selectedDisplay, 'display_label', data_get($selectedDisplay, 'iqd_label')) }}</b>
                                    </p>
                                    @if($selectedCycle === 'hourly')
                                        <p class="mb-2 text-info small">
                                            {{ __('Hourly billing is a testing-only cycle and reuses the monthly plan price for fast recurring verification.') }}
                                        </p>
                                    @endif
                                @if((bool) data_get($selectedDisplay, 'has_localized_estimate', false))
                                    <p class="mb-2">
                                        {{ __('Canonical base:') }}
                                        <b>{{ data_get($selectedDisplay, 'iqd_label') }}</b>
                                    </p>
                                @endif

                                @endif

                                <div class="small text-muted">
                                    {{ __('Your subscription credit bucket will be reset to the new plan credits for a fresh cycle.') }}
                                    {{ __('Your add-on credits will remain unchanged.') }}
                                </div>

                                <div class="alert alert-warning mt-3 mb-0">
                                    <div class="fw-semibold mb-2">{{ __('Next step: complete payment in First Iraqi Bank') }}</div>
                                    <div>
                                        {{ $selectedUsesRecurring
                                            ? __('We will open a dedicated FIB subscription page with QR scan, manual code entry, automatic status refresh, and cancel controls.')
                                            : __('We will open a dedicated FIB payment page with QR scan, manual code entry, and automatic status refresh.') }}
                                    </div>
                                    <div class="small mt-2">
                                        {{ $selectedUsesRecurring
                                            ? __('Plan subscriptions now use the dedicated FIB recurring subscription API, while local entitlement activation stays server-side and idempotent.')
                                            : __('This plan is currently configured for manual payment. Auto-renew is disabled, and you can renew again from this page when the billing period ends.') }}
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button class="btn btn-light" wire:click="closeConfirm" @disabled($processing)>
                                    {{ __('Cancel') }}
                                </button>
                                <button class="btn btn-primary" wire:click="confirmChange" @disabled($processing)>
                                    @if($processing)
                                        {{ __('Preparing...') }}
                                    @else
                                        {{ $selectedUsesRecurring ? __('Open FIB Subscription') : __('Open FIB Payment') }}
                                    @endif
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if($showCancelConfirm)
                <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">{{ __('Cancel Current Plan') }}</h5>
                                <button type="button" class="btn-close" wire:click="closeCancelConfirm" @disabled($processing)></button>
                            </div>

                            <div class="modal-body">
                                <p class="mb-2">
                                    {{ __('Your current paid plan will remain active until:') }}
                                    <b>{{ $currentPlanEndsAtLabel ?: __('the end of the current billing period') }}</b>
                                </p>
                                <div class="small text-muted">
                                    {{ __('Auto-renew will stay disabled, and after that date your account will move back to the free plan.') }}
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button class="btn btn-light" wire:click="closeCancelConfirm" @disabled($processing)>
                                    {{ __('Keep Plan') }}
                                </button>
                                <button class="btn btn-danger" wire:click="confirmCancelPlan" @disabled($processing)>
                                    @if($processing)
                                        {{ __('Scheduling...') }}
                                    @else
                                        {{ __('Confirm Cancellation') }}
                                    @endif
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
