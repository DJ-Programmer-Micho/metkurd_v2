{{-- resources/views/app/pages/storage-plan/⚡storage-plan.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
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
    public string $displayCurrencyCode = 'IQD';

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

        $this->plans = StoragePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($p) use ($currency, $customer) {
                $priceIqd = $p->priceIqdAmount();

                return [
                    'id' => (int) $p->id,
                    'code' => (string) $p->code,
                    'name' => (string) $p->name,
                    'quota_mb' => (int) ($p->quota_mb ?? 0),
                    'price_iqd' => $priceIqd,
                    'price_display' => $currency->priceDataForBaseAmountIqd($priceIqd, $customer),
                ];
            })
            ->values()
            ->all();

        $this->currentPlanId = $customer->storagePlan()->first()?->id;
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
        $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);

        if (!$this->selectedPlanId) {
            return;
        }

        if (! $selectedPlan) {
            $this->message = 'Selected storage plan was not found.';
            return;
        }

        if ((int) $this->selectedPlanId === (int) $this->currentPlanId) {
            $this->message = 'This is already your current storage plan.';
            return;
        }

        $this->processing = true;
        $this->message = '';
        $selectedPriceDisplay = app(BillingCurrencyService::class)->priceDataForBaseAmountIqd((int) $selectedPlan['price_iqd'], $customer);

        try {
            app(PlanSwitcher::class)->switchStoragePlan($customer, (int) $this->selectedPlanId, [
                'ui' => 'storage-plan-page',
            ]);

            $this->loadData();

            $this->showConfirm = false;
            $this->selectedPlanId = null;
            $this->message = 'Storage plan updated successfully.';

            $freshCustomer = $customer->fresh(['profile']);

            TelegramPaymentNotifier::send(
                $freshCustomer,
                'Storage Plan',
                (string) $selectedPlan['name'],
                [
                    'Plan Code' => strtoupper((string) $selectedPlan['code']),
                    'Storage Quota (MB)' => number_format((int) $selectedPlan['quota_mb']),
                    'Amount (IQD)' => $selectedPriceDisplay['iqd_label'],
                    ...($selectedPriceDisplay['has_localized_estimate']
                        ? ['Estimated Local Price' => $selectedPriceDisplay['display_label']]
                        : []),
                    'Provider' => 'fake',
                ],
                'Storage plan page'
            );

            CustomerEmailNotifier::sendStorageThankYou(
                $freshCustomer,
                [
                    'plan_name' => (string) $selectedPlan['name'],
                    'quota_mb' => (int) $selectedPlan['quota_mb'],
                    'amount_label' => (string) $selectedPriceDisplay['iqd_label'],
                    'activated_on' => now()->format('F d, Y'),
                ],
                'Storage plan page'
            );

            $this->dispatch('header:refresh');
            $this->dispatch('customerStorageUpdated');
        } catch (\Throwable $e) {
            $this->message = 'Failed: ' . $e->getMessage();
        } finally {
            $this->processing = false;
        }
    }

    public function render()
    {
        $customer = auth('app')->user()->fresh();

        $usedBytes = (int) $customer->storageUsedBytes();
        $quotaMb = (int) $customer->storageQuotaMb(512);
        $quotaBytes = $quotaMb * 1024 * 1024;

        $usedMb = (int) floor($usedBytes / 1024 / 1024);
        $storagePct = $quotaMb > 0
            ? min(100, (int) round(($usedMb / $quotaMb) * 100))
            : 0;

        return view('app.pages.storage-plan.⚡storage-plan', [
            'usedBytes' => $usedBytes,
            'quotaBytes' => $quotaBytes,
            'quotaMb' => $quotaMb,
            'usedMb' => $usedMb,
            'storagePct' => $storagePct,
            'overQuota' => $usedBytes > $quotaBytes,
        ]);
    }
};
?>

<x-slot:title>{{ __('Storage Plan') }} | {{ __('MET KURD') }}</x-slot:title>

<div>
    <div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <div class="text-center mb-4 pb-2">
                <h4 class="fs-22">{{ __('Storage Plans') }}</h4>

                <p class="text-muted mb-1 fs-15">
                    {{ __('Used:') }} <b>{{ number_format($usedMb) }} MB</b> /
                    {{ __('Quota:') }} <b>{{ number_format($quotaMb) }} MB</b>
                </p>

                <div class="mx-auto mt-3" style="max-width: 420px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted">{{ __('Storage usage') }}</small>
                        <small class="fw-semibold">{{ $storagePct }}%</small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-info" role="progressbar"
                             style="width: {{ $storagePct }}%;"
                             aria-valuenow="{{ $storagePct }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>

                @if($overQuota)
                    <div class="alert alert-danger mt-3 mb-0">
                        {{ __('You are currently over quota. Uploads should be blocked until you upgrade or delete files.') }}
                    </div>
                @endif

                @if($message)
                    <div class="alert alert-info mt-3 mb-0">{{ $message }}</div>
                @endif

                @if($displayCurrencyCode !== 'IQD')
                    <div class="text-muted small mt-3">
                        {{ __('Local display currency: :currency', ['currency' => $displayCurrencyCode]) }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        @foreach($plans as $p)
            @php
                $isCurrent = (int) $p['id'] === (int) $currentPlanId;
                $showLocalPrice = (bool) data_get($p, 'price_display.has_localized_estimate', false);
            @endphp

            <div class="col-xxl-3 col-lg-6">
                <div class="card pricing-box {{ $isCurrent ? 'border border-success shadow-sm' : '' }}">
                    <div class="card-body bg-light m-2 p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="flex-grow-1">
                                <h5 class="mb-0">{{ $p['name'] }}</h5>
                                <div class="text-muted fs-12">{{ strtoupper($p['code']) }}</div>
                            </div>
                            <div class="ms-auto text-end">
                                <div class="fw-semibold">{{ number_format($p['quota_mb']) }} MB</div>
                                <div class="text-muted fs-12">{{ __('quota') }}</div>
                                <div class="fw-semibold mt-2">{{ data_get($p, 'price_display.iqd_label') }}</div>
                                <div class="text-muted fs-12">{{ __('per change') }}</div>
                                @if($showLocalPrice)
                                    <div class="text-muted fs-12 mt-1">{{ data_get($p, 'price_display.estimated_label') }}</div>
                                @endif
                            </div>
                        </div>

                        <p class="text-muted mb-3">
                            {{ __('Storage quota for uploads and generated outputs.') }}
                        </p>

                        <div class="mt-3 pt-2">
                            @if($isCurrent)
                                <button class="btn btn-success w-100" disabled>
                                    {{ __('Your Current Plan') }}
                                </button>
                            @else
                                <button class="btn btn-info w-100"
                                        wire:click="openConfirm({{ $p['id'] }})"
                                        wire:loading.attr="disabled">
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
            $selectedDisplay = is_array($selected) ? ($selected['price_display'] ?? null) : null;
        @endphp

        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Confirm Storage Change') }}</h5>
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
                                {{ __('New quota:') }}
                                <b>{{ number_format($selected['quota_mb']) }} MB</b>
                            </p>
                            <p class="mb-2">
                                {{ __('Price:') }}
                                <b>{{ data_get($selectedDisplay, 'iqd_label') }}</b>
                            </p>
                            @if((bool) data_get($selectedDisplay, 'has_localized_estimate', false))
                                <p class="mb-2">
                                    {{ __('Estimated local display:') }}
                                    <b>{{ data_get($selectedDisplay, 'display_label') }}</b>
                                </p>
                            @endif
                        @endif

                        <div class="small text-muted">
                            {{ __('If you downgrade below your used storage, the plan can still be activated, but your account will remain marked as over quota until you delete files or upgrade again.') }}
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
