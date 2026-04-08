{{-- resources/views/app/pages/subscription-plan/⚡subscription-plan.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\PaymentIntent;
use App\Models\ServicePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Payments\CheckoutAuthorizationService;
use App\Services\Payments\PaymentIntentLifecycleService;
use App\Services\Payments\PaymentIntentService;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public array $plans = [];
    public array $paymentMethods = [];
    public ?int $currentPlanId = null;
    public ?int $selectedPlanId = null;
    public string $billingCycle = 'monthly';
    public string $selectedBillingCycle = 'monthly';
    public string $selectedPaymentMethodCode = '';
    public string $displayCurrencyCode = 'IQD';
    public string $displayCurrencySource = 'default';
    public ?int $activeCheckoutIntentId = null;
    public array $checkoutAction = [];

    public bool $showConfirm = false;
    public bool $processing = false;
    public string $message = '';

    public function mount(): void
    {
        $this->loadData();
    }

    protected function loadData(): void
    {
        $customer = auth('app')->user()?->loadMissing('profile');
        $currency = app(BillingCurrencyService::class);
        $displayContext = $currency->resolveDisplayContext($customer);
        $this->displayCurrencyCode = (string) ($displayContext['currency_code'] ?? 'IQD');
        $this->displayCurrencySource = (string) ($displayContext['source'] ?? 'default');

        $this->plans = ServicePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($p) use ($currency, $customer) {
                $monthlyDisplay = $currency->priceDataForBaseAmountIqd($p->priceIqdForCycle('monthly'), $customer);
                $yearlyDisplay = $currency->priceDataForBaseAmountIqd($p->priceIqdForCycle('yearly'), $customer);
                $ui = $p->localizedUiFeatures();

                return [
                    'id' => (int) $p->id,
                    'code' => (string) $p->code,
                    'name' => (string) $p->name,
                    'billing_interval' => (string) ($p->billing_interval ?? 'monthly'),
                    'monthly_credits' => (int) ($p->monthly_credits ?? 0),
                    'is_free' => (bool) ($p->is_free ?? false),
                    'price_iqd_monthly' => $p->priceIqdForCycle('monthly'),
                    'price_iqd_yearly' => $p->priceIqdForCycle('yearly'),
                    'display_monthly' => $monthlyDisplay,
                    'display_yearly' => $yearlyDisplay,
                    'ui_features' => $ui,
                    'feature_list' => is_array($ui['features'] ?? null) ? array_values($ui['features']) : [],
                ];
            })
            ->values()
            ->all();

        $this->currentPlanId = $customer->servicePlan()->first()?->id;
        $this->paymentMethods = app(CheckoutAuthorizationService::class)->paymentMethodOptions('service_plan');

        if ($this->selectedPaymentMethodCode === '' || ! collect($this->paymentMethods)->contains('code', $this->selectedPaymentMethodCode)) {
            $this->selectedPaymentMethodCode = (string) (data_get($this->paymentMethods, '0.code') ?? '');
        }
    }

    public function openConfirm(int $planId, string $billingCycle = 'monthly'): void
    {
        if ($this->paymentMethods === []) {
            $this->message = __('No checkout-ready payment method is available right now.');
            return;
        }

        $this->selectedPlanId = $planId;
        $this->billingCycle = $this->normalizeBillingCycle($billingCycle);
        $this->selectedBillingCycle = $this->billingCycle;
        $this->message = '';
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
        $this->activeCheckoutIntentId = null;
        $this->checkoutAction = [];
    }

    public function confirmChange(): void
    {
        $customer = auth('app')->user();
        $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);

        if (!$this->selectedPlanId) {
            return;
        }

        if (! $selectedPlan) {
            $this->message = 'Selected service plan was not found.';
            return;
        }

        if ((int) $this->selectedPlanId === (int) $this->currentPlanId) {
            $this->message = 'This is already your current plan.';
            return;
        }

        $billingCycle = $this->resolvePlanBillingCycle($selectedPlan, $this->selectedBillingCycle);
        $billingCycleLabel = $this->billingCycleLabel($billingCycle);
        $selectedPrice = $this->planPriceForCycle($selectedPlan, $billingCycle);
        $selectedPriceDisplay = app(BillingCurrencyService::class)->priceDataForBaseAmountIqd($selectedPrice, $customer);

        $this->processing = true;
        $this->message = '';

        try {
            $intent = app(PaymentIntentService::class)->startCheckout($customer, 'service_plan', (int) $this->selectedPlanId, [
                'ui' => 'subscription-plan-page',
                'billing_cycle' => $billingCycle,
                'payment_method' => $this->selectedPaymentMethodCode,
                'redirect_url' => url()->current(),
            ]);

            if ($intent->requiresCustomerAction()) {
                $this->activeCheckoutIntentId = (int) $intent->id;
                $this->checkoutAction = $intent->checkoutAction();
                $this->message = __('Payment created. Complete it in FIB, then return here and check the payment status.');

                return;
            }

            $this->completeSuccessfulPlanCheckout($customer, $intent, $selectedPlan, $billingCycleLabel, $selectedPriceDisplay);
        } catch (\Throwable $e) {
            $this->message = 'Failed: ' . $e->getMessage();
        } finally {
            $this->processing = false;
        }
    }

    public function refreshCheckoutStatus(): void
    {
        if (! $this->activeCheckoutIntentId) {
            return;
        }

        $this->processing = true;

        try {
            $intent = app(PaymentIntentService::class)->syncCheckout($this->activeCheckoutIntentId, [
                'ui' => 'subscription-plan-page-status',
            ]);

            if ((string) $intent->status === 'paid') {
                $selectedPlan = collect($this->plans)->firstWhere('id', (int) $intent->purpose_id);

                if (! is_array($selectedPlan)) {
                    $plan = ServicePlan::query()->findOrFail((int) $intent->purpose_id);
                    $ui = $plan->localizedUiFeatures();
                    $selectedPlan = [
                        'id' => (int) $plan->id,
                        'code' => (string) $plan->code,
                        'name' => (string) $plan->name,
                        'monthly_credits' => (int) ($plan->monthly_credits ?? 0),
                        'price_iqd_monthly' => $plan->priceIqdForCycle('monthly'),
                        'price_iqd_yearly' => $plan->priceIqdForCycle('yearly'),
                        'ui_features' => $ui,
                    ];
                }

                $billingCycle = $this->resolvePlanBillingCycle($selectedPlan, (string) ($intent->billing_interval ?? 'monthly'));
                $selectedPriceDisplay = app(BillingCurrencyService::class)->priceDataForBaseAmountIqd(
                    $this->planPriceForCycle($selectedPlan, $billingCycle),
                    auth('app')->user()
                );

                $this->completeSuccessfulPlanCheckout(
                    auth('app')->user(),
                    $intent,
                    $selectedPlan,
                    $this->billingCycleLabel($billingCycle),
                    $selectedPriceDisplay,
                );

                return;
            }

            $this->checkoutAction = $intent->checkoutAction();
            $this->message = match ((string) $intent->status) {
                'failed' => __('Payment was declined by FIB.'),
                'canceled' => __('Payment was canceled before completion.'),
                'expired' => __('This FIB payment expired. Please start a new checkout.'),
                default => __('Payment is still waiting to be completed.'),
            };
        } catch (\Throwable $e) {
            $this->message = __('Failed to refresh payment status: :message', ['message' => $e->getMessage()]);
        } finally {
            $this->processing = false;
        }
    }

    public function cancelPendingCheckout(): void
    {
        if (! $this->activeCheckoutIntentId) {
            return;
        }

        $this->processing = true;

        try {
            $intent = app(PaymentIntentLifecycleService::class)->cancel($this->activeCheckoutIntentId, [
                'reason' => 'Customer canceled pending checkout from subscription modal.',
                'source' => 'subscription_plan_modal',
            ]);

            if ((string) $intent->status === 'canceled') {
                $this->showConfirm = false;
                $this->selectedPlanId = null;
                $this->activeCheckoutIntentId = null;
                $this->checkoutAction = [];
                $this->message = __('Payment was canceled before completion.');

                return;
            }

            $this->checkoutAction = $intent->checkoutAction();
            $this->message = __('Payment could not be canceled automatically. Please refresh the status or wait for expiry.');
        } catch (\Throwable $e) {
            $this->message = __('Failed to cancel payment: :message', ['message' => $e->getMessage()]);
        } finally {
            $this->processing = false;
        }
    }

    protected function completeSuccessfulPlanCheckout($customer, PaymentIntent $intent, array $selectedPlan, string $billingCycleLabel, array $selectedPriceDisplay): void
    {
        $this->loadData();

        $this->showConfirm = false;
        $this->selectedPlanId = null;
        $this->activeCheckoutIntentId = null;
        $this->checkoutAction = [];
        $this->message = __('Service plan updated successfully. Your subscription credits were reset for the new billing cycle, while add-on credits were kept.');

        $freshCustomer = $customer->fresh(['profile']);

        TelegramPaymentNotifier::send(
            $freshCustomer,
            'Subscription Plan',
            (string) $selectedPlan['name'],
            [
                'Billing Cycle' => $billingCycleLabel,
                'Plan Code' => strtoupper((string) $selectedPlan['code']),
                'Monthly Credits' => number_format((int) $selectedPlan['monthly_credits']),
                'Price (IQD)' => $selectedPriceDisplay['iqd_label'],
                ...($selectedPriceDisplay['has_localized_estimate']
                    ? ['Estimated Local Price' => $selectedPriceDisplay['display_label']]
                    : []),
                'Provider' => strtoupper((string) $intent->provider),
                'Reference' => (string) ($intent->merchant_transaction_id ?? $intent->provider_payment_id ?? $intent->uuid),
            ],
            'Subscription plan page'
        );

        CustomerEmailNotifier::sendSubscriptionThankYou(
            $freshCustomer,
            [
                'plan_name' => (string) $selectedPlan['name'],
                'billing_cycle' => $billingCycleLabel,
                'monthly_credits' => (int) $selectedPlan['monthly_credits'],
                'amount_label' => (string) $selectedPriceDisplay['iqd_label'],
                'activated_on' => now()->format('F d, Y'),
            ],
            'Subscription plan page'
        );

        $this->dispatch('header:refresh');
        $this->dispatch('customerPlanUpdated');
    }

    public function render()
    {
        $customer = auth('app')->user()->fresh();

        $wallet = $customer->wallet()->first();

        $combinedBalance = (int) ($wallet?->balance_credits ?? 0);
        $subscriptionBalance = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addonBalance = (int) ($wallet?->addon_balance_credits ?? 0);

        $currentPlan = $customer->servicePlan()->first();
        $monthlyCredits = (int) ($currentPlan?->monthly_credits ?? 0);
        $planCode = (string) ($currentPlan?->code ?? 'free');
        $planName = (string) ($currentPlan?->name ?? 'Free');

        $creditsPct = $monthlyCredits > 0
            ? min(100, (int) round(($subscriptionBalance / $monthlyCredits) * 100))
            : 0;

        return view('app.pages.subscription-plan.⚡subscription-plan', [
            'combinedBalance' => $combinedBalance,
            'subscriptionBalance' => $subscriptionBalance,
            'addonBalance' => $addonBalance,
            'monthlyCredits' => $monthlyCredits,
            'creditsPct' => $creditsPct,
            'planCode' => $planCode,
            'planName' => $planName,
        ]);
    }
    private function normalizeBillingCycle(?string $cycle): string
    {
        $cycle = strtolower(trim((string) $cycle));

        return in_array($cycle, ['monthly', 'yearly'], true) ? $cycle : 'monthly';
    }

    public function resolvePlanBillingCycle(array $plan, ?string $requestedCycle = null): string
    {
        $planInterval = strtolower((string) ($plan['billing_interval'] ?? 'monthly'));

        if ($planInterval === 'lifetime') {
            return 'lifetime';
        }

        return $this->normalizeBillingCycle($requestedCycle);
    }

    public function billingCycleLabel(string $cycle): string
    {
        return match ($cycle) {
            'yearly' => 'Yearly',
            'lifetime' => 'Lifetime',
            default => 'Monthly',
        };
    }

    public function billingCycleSuffix(string $cycle): string
    {
        return match ($cycle) {
            'yearly' => '/yr',
            'lifetime' => '',
            default => '/mo',
        };
    }

    public function billingCycleCreditNote(string $cycle): string
    {
        return match ($cycle) {
            'yearly' => 'per year plan',
            'lifetime' => 'lifetime plan',
            default => 'per month',
        };
    }

    public function planPriceForCycle(array $plan, string $cycle): int
    {
        return match ($cycle) {
            'yearly' => (int) ($plan['price_iqd_yearly'] ?? 0),
            default => (int) ($plan['price_iqd_monthly'] ?? 0),
        };
    }
};
?>

<x-slot:title>{{ __('Subscription Plan') }} | {{ __('MET KURD') }}</x-slot:title>

<div x-data="{ billingCycle: @js($billingCycle) }">
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

                @if($message)
                    <div class="alert alert-info mt-3 mb-0">{{ $message }}</div>
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
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-12">
            <div class="row">
                @foreach($plans as $p)
                    @php
                        $isCurrent = (int) $p['id'] === (int) $currentPlanId;
                        $planInterval = strtolower((string) ($p['billing_interval'] ?? 'monthly'));
                        $isLifetimePlan = $planInterval === 'lifetime';
                        $monthlyPrice = (string) data_get($p, 'display_monthly.display_label', data_get($p, 'display_monthly.iqd_label', ''));
                        $yearlyPrice = (string) data_get($p, 'display_yearly.display_label', data_get($p, 'display_yearly.iqd_label', ''));
                        $monthlyBase = (string) data_get($p, 'display_monthly.iqd_label', '');
                        $yearlyBase = (string) data_get($p, 'display_yearly.iqd_label', '');
                        $showMonthlyBase = (bool) data_get($p, 'display_monthly.has_localized_estimate', false);
                        $showYearlyBase = (bool) data_get($p, 'display_yearly.has_localized_estimate', false);
                    @endphp

                    <div class="col-lg-3 col-md-6">
                        <div class="card pricing-box {{ $isCurrent ? 'border border-success shadow-sm' : '' }}">
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

                                        @if($isLifetimePlan)
                                            <span class="badge bg-soft-secondary text-secondary">{{ __('Lifetime') }}</span>
                                        @else
                                            <span class="badge bg-soft-secondary text-secondary"
                                                  x-text="billingCycle === 'yearly' ? @js(__('Yearly')) : @js(__('Monthly'))">
                                                {{ __('Monthly') }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="ms-auto text-end">
                                        <div class="fw-semibold">{{ number_format($p['monthly_credits']) }} {{ __('credits') }}</div>
                                        @if($isLifetimePlan)
                                            <div class="text-muted fs-12">{{ __('lifetime plan') }}</div>
                                        @else
                                            <div class="text-muted fs-12"
                                                 x-text="billingCycle === 'yearly' ? @js(__('per year plan')) : @js(__('per month'))">
                                                {{ __('per month') }}
                                            </div>
                                        @endif

                                        @if(!$p['is_free'])
                                            @if($isLifetimePlan)
                                                <div class="mt-1 text-muted fs-12">{{ $monthlyPrice }}</div>
                                                @if($showMonthlyBase)
                                                    <div class="mt-1 text-muted fs-12">{{ $monthlyBase }}</div>
                                                @endif
                                            @else
                                                <div class="mt-1 text-muted fs-12"
                                                     x-text="(billingCycle === 'yearly' ? '{{ $yearlyPrice }}' : '{{ $monthlyPrice }}') + (billingCycle === 'yearly' ? '/yr' : '/mo')">
                                                    {{ $monthlyPrice }}/mo
                                                </div>
                                                @if($showMonthlyBase || $showYearlyBase)
                                                    <div class="mt-1 text-muted fs-12"
                                                         x-text="billingCycle === 'yearly' ? '{{ $yearlyBase }}' : '{{ $monthlyBase }}'">
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
                                    @if($isCurrent)
                                        <button class="btn btn-success w-100" disabled>
                                            {{ __('Your Current Plan') }}
                                        </button>
                                    @else
                                        <button class="btn btn-info w-100"
                                                x-on:click="$wire.openConfirm({{ $p['id'] }}, billingCycle)"
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
                        ? ($selectedCycle === 'yearly' ? ($selected['display_yearly'] ?? null) : ($selected['display_monthly'] ?? null))
                        : null;
                @endphp

                <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">{{ __('Confirm Plan Change') }}</h5>
                                <button type="button" class="btn-close" wire:click="closeConfirm" @disabled($processing)></button>
                            </div>

                            <div class="modal-body">
                                @if ($checkoutAction === [] && $paymentMethods !== [])
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">{{ __('Payment Method') }}</label>
                                        <div class="d-flex flex-column gap-2">
                                            @foreach ($paymentMethods as $method)
                                                <label class="border rounded-3 p-3 d-flex align-items-start gap-2">
                                                    <input class="form-check-input mt-1" type="radio" wire:model.live="selectedPaymentMethodCode" value="{{ $method['code'] }}">
                                                    <div class="flex-grow-1">
                                                        <div class="fw-semibold">{{ $method['name'] }}</div>
                                                        @if (!empty($method['description']))
                                                            <div class="text-muted small">{{ $method['description'] }}</div>
                                                        @endif
                                                    </div>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @elseif($checkoutAction === [])
                                    <div class="alert alert-warning mb-3">
                                        {{ __('No checkout-ready payment method is available right now.') }}
                                    </div>
                                @endif

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
                                        {{ __('New monthly subscription credits:') }}
                                        <b>{{ number_format($selected['monthly_credits']) }}</b>
                                    </p>
                            <p class="mb-2">
                                {{ __('Price:') }}
                                <b>{{ data_get($selectedDisplay, 'display_label', data_get($selectedDisplay, 'iqd_label')) }}</b>
                            </p>
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

                                @if($checkoutAction !== [])
                                    @php
                                        $checkoutLinks = (array) data_get($checkoutAction, 'links', []);
                                        $validUntilLabel = data_get($checkoutAction, 'valid_until')
                                            ? \Illuminate\Support\Carbon::parse((string) data_get($checkoutAction, 'valid_until'))->timezone(config('app.timezone'))->format('Y-m-d H:i')
                                            : null;
                                    @endphp

                                    <div class="alert alert-warning mt-3 mb-0">
                                        <div class="fw-semibold mb-2">{{ __('Complete the payment in First Iraqi Bank') }}</div>

                                        @if(!empty($checkoutAction['readable_code']))
                                            <div class="mb-2">
                                                {{ __('Readable code:') }}
                                                <b>{{ $checkoutAction['readable_code'] }}</b>
                                            </div>
                                        @endif

                                        @if($validUntilLabel)
                                            <div class="mb-2">
                                                {{ __('Valid until:') }}
                                                <b>{{ $validUntilLabel }}</b>
                                            </div>
                                        @endif

                                        @if(!empty($checkoutAction['qr_code']))
                                            <div class="text-center py-2">
                                                <img src="{{ $checkoutAction['qr_code'] }}"
                                                     alt="{{ __('FIB payment QR code') }}"
                                                     class="img-fluid rounded border bg-white p-2"
                                                     style="max-width: 220px;">
                                            </div>
                                        @endif

                                        @if($checkoutLinks !== [])
                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                @foreach($checkoutLinks as $linkLabel => $linkUrl)
                                                    @if(is_string($linkUrl) && $linkUrl !== '')
                                                        <a href="{{ $linkUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                                                            {{ __(ucfirst((string) $linkLabel) . ' App') }}
                                                        </a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif

                                        <div class="small mt-3">
                                            {{ __('After you pay in FIB, click "Check Status" here to sync the payment if the callback has not arrived yet.') }}
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div class="modal-footer">
                                <button class="btn btn-light" wire:click="closeConfirm" @disabled($processing)>
                                    {{ $checkoutAction !== [] ? __('Close') : __('Cancel') }}
                                </button>

                                @if($checkoutAction !== [])
                                    <button class="btn btn-outline-danger" wire:click="cancelPendingCheckout" @disabled($processing)>
                                        @if($processing)
                                            {{ __('Canceling...') }}
                                        @else
                                            {{ __('Cancel Payment') }}
                                        @endif
                                    </button>
                                    <button class="btn btn-primary" wire:click="refreshCheckoutStatus" @disabled($processing)>
                                        @if($processing)
                                            {{ __('Checking...') }}
                                        @else
                                            {{ __('Check Status') }}
                                        @endif
                                    </button>
                                @else
                                    <button class="btn btn-primary" wire:click="confirmChange" @disabled($processing)>
                                        @if($processing)
                                            {{ __('Processing...') }}
                                        @else
                                            {{ __('Continue') }}
                                        @endif
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
