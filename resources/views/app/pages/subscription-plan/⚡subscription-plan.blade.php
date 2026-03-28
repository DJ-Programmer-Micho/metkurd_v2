{{-- resources/views/app/pages/subscription-plan/⚡subscription-plan.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\ServicePlan;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;
use App\Services\Billing\PlanSwitcher;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public array $plans = [];
    public ?int $currentPlanId = null;
    public ?int $selectedPlanId = null;
    public string $billingCycle = 'monthly';
    public string $selectedBillingCycle = 'monthly';

    public bool $showConfirm = false;
    public bool $processing = false;
    public string $message = '';

    public function mount(): void
    {
        $this->loadData();
    }

    protected function loadData(): void
    {
        $customer = auth('app')->user();

        $this->plans = ServicePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => (int) $p->id,
                    'code' => (string) $p->code,
                    'name' => (string) $p->name,
                    'billing_interval' => (string) ($p->billing_interval ?? 'monthly'),
                    'monthly_credits' => (int) ($p->monthly_credits ?? 0),
                    'is_free' => (bool) ($p->is_free ?? false),
                    'price_usd_monthly' => (float) ($p->price_usd_monthly ?? 0),
                    'price_usd_yearly' => (float) ($p->price_usd_yearly ?? 0),
                    'ui_features' => is_array($p->ui_features) ? $p->ui_features : (array) ($p->ui_features ?? []),
                ];
            })
            ->values()
            ->all();

        $this->currentPlanId = $customer->servicePlan()->first()?->id;
    }

    public function openConfirm(int $planId, string $billingCycle = 'monthly'): void
    {
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

        $this->processing = true;
        $this->message = '';

        try {
            app(PlanSwitcher::class)->switchServicePlan($customer, (int) $this->selectedPlanId, [
                'ui' => 'subscription-plan-page',
                'billing_cycle' => $billingCycle,
            ]);

            $this->loadData();

            $this->showConfirm = false;
            $this->selectedPlanId = null;
            $this->message = 'Service plan updated successfully. Your subscription credits were reset for the new billing cycle, while add-on credits were kept.';

            $freshCustomer = $customer->fresh(['profile']);

            TelegramPaymentNotifier::send(
                $freshCustomer,
                'Subscription Plan',
                (string) $selectedPlan['name'],
                [
                    'Billing Cycle' => $billingCycleLabel,
                    'Plan Code' => strtoupper((string) $selectedPlan['code']),
                    'Monthly Credits' => number_format((int) $selectedPlan['monthly_credits']),
                    'Price (USD)' => '$' . number_format($selectedPrice, 2),
                    'Provider' => 'fake',
                ],
                'Subscription plan page'
            );

            CustomerEmailNotifier::sendSubscriptionThankYou(
                $freshCustomer,
                [
                    'plan_name' => (string) $selectedPlan['name'],
                    'billing_cycle' => $billingCycleLabel,
                    'monthly_credits' => (int) $selectedPlan['monthly_credits'],
                    'activated_on' => now()->format('F d, Y'),
                ],
                'Subscription plan page'
            );

            $this->dispatch('header:refresh');
            $this->dispatch('customerPlanUpdated');
        } catch (\Throwable $e) {
            $this->message = 'Failed: ' . $e->getMessage();
        } finally {
            $this->processing = false;
        }
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

    public function planPriceForCycle(array $plan, string $cycle): float
    {
        return match ($cycle) {
            'yearly' => (float) ($plan['price_usd_yearly'] ?? 0),
            default => (float) ($plan['price_usd_monthly'] ?? 0),
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
                        $monthlyPrice = number_format((float) ($p['price_usd_monthly'] ?? 0), 2);
                        $yearlyPrice = number_format((float) ($p['price_usd_yearly'] ?? 0), 2);
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
                                                <div class="mt-1 text-muted fs-12">${{ $monthlyPrice }}</div>
                                            @else
                                                <div class="mt-1 text-muted fs-12"
                                                     x-text="'$' + (billingCycle === 'yearly' ? '{{ $yearlyPrice }}' : '{{ $monthlyPrice }}') + (billingCycle === 'yearly' ? '/yr' : '/mo')">
                                                    ${{ $monthlyPrice }}/mo
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                </div>

                                <hr class="my-4 text-muted">

                                <ul class="list-unstyled text-muted vstack gap-2 mb-0">
                                    @forelse($p['ui_features'] as $f)
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
                                            {{ __('Change Plan (Fake Pay)') }}
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
                @endphp

                <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">{{ __('Confirm Plan Change') }}</h5>
                                <button type="button" class="btn-close" wire:click="closeConfirm" @disabled($processing)></button>
                            </div>

                            <div class="modal-body">
                                <div class="alert alert-warning mb-3">
                                    {{ __('This is a fake payment for testing.') }}
                                </div>

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
                                        <b>${{ number_format($selectedPrice, 2) }}</b>
                                    </p>
                                @endif

                                <div class="small text-muted">
                                    {{ __('Your subscription credit bucket will be reset to the new plan credits for a fresh cycle.') }}
                                    {{ __('Your add-on credits will remain unchanged.') }}
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button class="btn btn-light" wire:click="closeConfirm" @disabled($processing)>{{ __('Cancel') }}</button>
                                <button class="btn btn-primary" wire:click="confirmChange" @disabled($processing)>
                                    @if($processing)
                                        {{ __('Processing...') }}
                                    @else
                                        {{ __('Confirm') }}
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
