{{-- resources/views/app/pages/subscription-plan/⚡subscription-plan.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\ServicePlan;
use App\Services\Billing\PlanSwitcher;

new
#[Layout('app::layouts.app')]
#[Title('Subscription Plan | METKURD')]
class extends Component
{
    public array $plans = [];
    public ?int $currentPlanId = null;
    public ?int $selectedPlanId = null;

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

    public function openConfirm(int $planId): void
    {
        $this->selectedPlanId = $planId;
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
    }

    public function confirmChange(): void
    {
        $customer = auth('app')->user();

        if (!$this->selectedPlanId) {
            return;
        }

        if ((int) $this->selectedPlanId === (int) $this->currentPlanId) {
            $this->message = 'This is already your current plan.';
            return;
        }

        $this->processing = true;
        $this->message = '';

        try {
            app(PlanSwitcher::class)->switchServicePlan($customer, (int) $this->selectedPlanId, [
                'ui' => 'subscription-plan-page',
            ]);

            $this->loadData();

            $this->showConfirm = false;
            $this->selectedPlanId = null;
            $this->message = 'Service plan updated successfully. Your monthly subscription credits were reset for the new cycle, while add-on credits were kept.';

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
};
?>

<div>
    <div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <div class="text-center mb-4 pb-2">
                <h4 class="fs-22">Subscription (Service Credits)</h4>
                <p class="text-muted mb-2 fs-15">
                    Current plan: <b>{{ strtoupper($planCode) }}</b> — {{ $planName }}
                </p>

                <div class="d-flex justify-content-center flex-wrap gap-3 small">
                    <div>
                        <span class="text-muted">Combined Balance:</span>
                        <b>{{ number_format($combinedBalance) }}</b>
                    </div>
                    <div>
                        <span class="text-muted">Subscription Credits:</span>
                        <b>{{ number_format($subscriptionBalance) }}</b>
                    </div>
                    <div>
                        <span class="text-muted">Add-on Credits:</span>
                        <b>{{ number_format($addonBalance) }}</b>
                    </div>
                </div>

                <div class="mx-auto mt-3" style="max-width: 420px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted">Monthly subscription bucket</small>
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
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-12">
            <div class="row">
                @foreach($plans as $p)
                    @php
                        $isCurrent = (int) $p['id'] === (int) $currentPlanId;
                    @endphp

                    <div class="col-lg-3 col-md-6">
                        <div class="card pricing-box {{ $isCurrent ? 'border border-success shadow-sm' : '' }}">
                            <div class="card-body p-4 m-2">
                                <div class="d-flex align-items-start">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">{{ $p['name'] }}</h5>
                                        <p class="text-muted mb-1">{{ strtoupper($p['code']) }}</p>

                                        @if($p['is_free'])
                                            <span class="badge bg-soft-success text-success">Free</span>
                                        @else
                                            <span class="badge bg-soft-info text-info">Paid Plan</span>
                                        @endif
                                    </div>

                                    <div class="ms-auto text-end">
                                        <div class="fw-semibold">{{ number_format($p['monthly_credits']) }} credits</div>
                                        <div class="text-muted fs-12">per month</div>

                                        @if(!$p['is_free'])
                                            <div class="mt-1 text-muted fs-12">
                                                ${{ number_format($p['price_usd_monthly'], 2) }}/mo
                                            </div>
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
                                        <li class="text-muted">No features listed.</li>
                                    @endforelse
                                </ul>

                                <div class="mt-4">
                                    @if($isCurrent)
                                        <button class="btn btn-success w-100" disabled>
                                            Your Current Plan
                                        </button>
                                    @else
                                        <button class="btn btn-info w-100"
                                                wire:click="openConfirm({{ $p['id'] }})"
                                                wire:loading.attr="disabled">
                                            Change Plan (Fake Pay)
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
                @endphp

                <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Confirm Plan Change</h5>
                                <button type="button" class="btn-close" wire:click="closeConfirm" @disabled($processing)></button>
                            </div>

                            <div class="modal-body">
                                <div class="alert alert-warning mb-3">
                                    This is a <b>fake payment</b> for testing.
                                </div>

                                @if($selected)
                                    <p class="mb-2">
                                        You are switching to:
                                        <b>{{ $selected['name'] }}</b>
                                        ({{ strtoupper($selected['code']) }})
                                    </p>
                                    <p class="mb-2">
                                        New monthly subscription credits:
                                        <b>{{ number_format($selected['monthly_credits']) }}</b>
                                    </p>
                                @endif

                                <div class="small text-muted">
                                    Your <b>subscription credit bucket</b> will be reset to the new plan monthly credits for a fresh cycle.
                                    Your <b>add-on credits</b> will remain unchanged.
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button class="btn btn-light" wire:click="closeConfirm" @disabled($processing)>Cancel</button>
                                <button class="btn btn-primary" wire:click="confirmChange" @disabled($processing)>
                                    @if($processing)
                                        Processing...
                                    @else
                                        Confirm
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