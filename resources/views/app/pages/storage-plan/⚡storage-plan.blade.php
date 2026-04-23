{{-- resources/views/app/pages/storage-plan/⚡storage-plan.blade.php --}}
<?php

use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\Billing\ScheduleStoragePlanCancellation;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public array $plans = [];

    public ?int $currentPlanId = null;

    public ?int $selectedPlanId = null;

    public string $displayCurrencyCode = 'IQD';

    public string $billingCycle = 'monthly';

    public bool $hourlyTestingEnabled = false;

    public bool $showConfirm = false;

    public bool $showCancelConfirm = false;

    public bool $processing = false;

    public bool $canCancelCurrentPlan = false;

    public bool $currentPlanCancellationScheduled = false;

    public bool $overQuota = false;

    public bool $projectedOverQuotaAfterDowngrade = false;

    public int $usedBytes = 0;

    public int $usedMb = 0;

    public int $quotaMb = 512;

    public int $quotaBytes = 512 * 1024 * 1024;

    public int $futureQuotaMb = 512;

    public int $storagePct = 0;

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
        $customer = $customer?->fresh(['profile', 'usage']);

        if (! $customer) {
            return;
        }

        $currency = app(BillingCurrencyService::class);
        $fibSubscriptions = app(FibSubscriptionService::class);
        $displayContext = $currency->resolveDisplayContext($customer);
        $state = app(CustomerBillingStateService::class)->storageQuotaState($customer);

        $this->displayCurrencyCode = (string) ($displayContext['currency_code'] ?? 'IQD');
        $this->hourlyTestingEnabled = $fibSubscriptions->hourlyTestingEnabled();
        $this->billingCycle = $this->normalizeBillingCycle($this->billingCycle);
        $this->syncStorageState($state);

        $this->plans = StoragePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (StoragePlan $plan) use ($currency, $customer) {
                $priceIqd = $plan->priceIqdAmount();
                $isCurrent = (int) $plan->id === (int) $this->currentPlanId;

                return [
                    'id' => (int) $plan->id,
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'quota_mb' => (int) ($plan->quota_mb ?? 0),
                    'price_iqd' => $priceIqd,
                    'price_display' => $currency->priceDataForBaseAmountIqd($priceIqd, $customer),
                    'is_current' => $isCurrent,
                    'can_cancel' => $isCurrent && $this->canCancelCurrentPlan,
                    'cancellation_scheduled' => $isCurrent && $this->currentPlanCancellationScheduled,
                    'access_until_label' => $isCurrent ? $this->currentPlanEndsAtLabel : null,
                ];
            })
            ->values()
            ->all();
    }

    protected function syncStorageState(array $state): void
    {
        $this->currentPlanId = (int) ($state['current_plan_id'] ?? 0) ?: null;
        $this->canCancelCurrentPlan = (bool) ($state['cancelable'] ?? false);
        $this->currentPlanCancellationScheduled = (bool) ($state['cancellation_scheduled'] ?? false);
        $this->usedBytes = (int) ($state['used_bytes'] ?? 0);
        $this->usedMb = (int) ($state['used_mb'] ?? 0);
        $this->quotaMb = (int) ($state['current_limit_mb'] ?? 512);
        $this->quotaBytes = (int) ($state['current_limit_bytes'] ?? (512 * 1024 * 1024));
        $this->futureQuotaMb = (int) ($state['future_limit_mb'] ?? $this->quotaMb);
        $this->overQuota = (bool) ($state['over_quota'] ?? false);
        $this->projectedOverQuotaAfterDowngrade = (bool) ($state['projected_over_quota_after_downgrade'] ?? false);
        $this->storagePct = $this->quotaMb > 0
            ? min(100, (int) round(($this->usedMb / $this->quotaMb) * 100))
            : 0;
        $this->currentPlanEndsAtLabel = $this->formatDateLabel($state['period_ends_at'] ?? null);
    }

    public function openConfirm(int $planId): void
    {
        $this->selectedPlanId = $planId;
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
            app(ScheduleStoragePlanCancellation::class)->handle($customer);

            $this->closeCancelConfirm();
            $this->loadData();

            $this->messageType = 'success';
            $this->message = __('Cancellation scheduled. Your storage plan remains active until :date.', [
                'date' => $this->currentPlanEndsAtLabel ?: __('the end of the current billing period'),
            ]);

            if ($this->projectedOverQuotaAfterDowngrade) {
                $this->message .= ' ' . __('After the downgrade, uploads and storage-growing actions will stay blocked until you delete files or upgrade again.');
            }
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->messageType = 'danger';
            $this->message = collect($exception->errors())->flatten()->first() ?: __('Could not schedule the storage cancellation.');
        } catch (\Throwable $exception) {
            $this->messageType = 'danger';
            $this->message = __('Failed to schedule the storage cancellation: :message', ['message' => $exception->getMessage()]);
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
            $this->message = __('Selected storage plan was not found.');

            return;
        }

        if ((int) $this->selectedPlanId === (int) $this->currentPlanId) {
            $this->messageType = 'info';
            $this->message = __('This is already your current storage plan.');

            return;
        }

        $this->processing = true;
        $this->message = '';
        $this->messageType = 'info';

        try {
            $payment = app(CreateStorageSubscriptionPayment::class)->handle(
                $customer,
                (int) $this->selectedPlanId,
                $this->billingCycle,
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
            $this->messageType = 'danger';
            $this->message = __('Failed to start the payment: :message', ['message' => $exception->getMessage()]);
        } finally {
            $this->processing = false;
        }
    }

    public function render()
    {
        return view('app.pages.storage-plan.⚡storage-plan', [
            'usedBytes' => $this->usedBytes,
            'quotaBytes' => $this->quotaBytes,
            'quotaMb' => $this->quotaMb,
            'usedMb' => $this->usedMb,
            'storagePct' => $this->storagePct,
            'overQuota' => $this->overQuota,
            'currentPlanCancellationScheduled' => $this->currentPlanCancellationScheduled,
            'currentPlanEndsAtLabel' => $this->currentPlanEndsAtLabel,
            'futureQuotaMb' => $this->futureQuotaMb,
            'projectedOverQuotaAfterDowngrade' => $this->projectedOverQuotaAfterDowngrade,
        ]);
    }

    protected function formatDateLabel(mixed $date): ?string
    {
        return method_exists($date, 'timezone')
            ? $date->timezone(config('app.timezone'))->format('Y-m-d H:i')
            : null;
    }

    protected function normalizeBillingCycle(?string $cycle): string
    {
        $cycle = strtolower(trim((string) $cycle));
        $allowed = ['monthly'];

        if ($this->hourlyTestingEnabled) {
            $allowed[] = 'hourly';
        }

        return in_array($cycle, $allowed, true) ? $cycle : 'monthly';
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

                @if($currentPlanCancellationScheduled && $currentPlanEndsAtLabel)
                    <div class="alert alert-warning mt-3 mb-0 text-start">
                        <div><b>{{ __('Cancellation scheduled') }}</b></div>
                        <div>{{ __('Your current storage entitlement remains active until :date.', ['date' => $currentPlanEndsAtLabel]) }}</div>
                        <div>{{ __('After that, your storage limit will return to :limit MB.', ['limit' => number_format($futureQuotaMb)]) }}</div>
                    </div>
                @endif

                @if($projectedOverQuotaAfterDowngrade && $currentPlanCancellationScheduled)
                    <div class="alert alert-danger mt-3 mb-0 text-start">
                        {{ __('After the scheduled downgrade, your current usage will be above the new quota. Existing files stay preserved, but uploads and storage-growing actions will be blocked until usage drops below the limit or you upgrade again.') }}
                    </div>
                @endif

                @if($overQuota)
                    <div class="alert alert-danger mt-3 mb-0">
                        {{ __('You are currently over quota. Uploads should be blocked until you upgrade or delete files.') }}
                    </div>
                @endif

                @if($message)
                    <div class="alert alert-{{ $messageType }} mt-3 mb-0">{{ $message }}</div>
                @endif

                @if($displayCurrencyCode !== 'IQD')
                    <div class="text-muted small mt-3">
                        {{ __('Local display currency: :currency', ['currency' => $displayCurrencyCode]) }}
                    </div>
                @endif

                @if($hourlyTestingEnabled)
                    <div class="alert alert-info mt-3 mb-0 text-start">
                        <div class="fw-semibold">{{ __('Hourly renewal is enabled for testing only.') }}</div>
                        <div class="small mt-1">{{ __('This keeps the existing storage plan price but uses a fast hourly recurring interval so renewals and cancel-at-period-end behavior can be verified quickly.') }}</div>
                    </div>

                    <div class="d-flex justify-content-center mt-4">
                        <div class="btn-group" role="group" aria-label="{{ __('Billing cycle') }}">
                            <button type="button"
                                    class="btn {{ $billingCycle === 'monthly' ? 'btn-primary' : 'btn-outline-primary' }}"
                                    wire:click="$set('billingCycle', 'monthly')">
                                {{ __('Monthly') }}
                            </button>
                            <button type="button"
                                    class="btn {{ $billingCycle === 'hourly' ? 'btn-primary' : 'btn-outline-primary' }}"
                                    wire:click="$set('billingCycle', 'hourly')">
                                {{ __('Hourly Test') }}
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        @foreach($plans as $p)
            @php
                $showLocalPrice = (bool) data_get($p, 'price_display.has_localized_estimate', false);
            @endphp

            <div class="col-xxl-3 col-lg-6">
                <div class="card pricing-box {{ $p['is_current'] ? 'border border-success shadow-sm' : '' }}">
                    <div class="card-body bg-light m-2 p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="flex-grow-1">
                                <h5 class="mb-0">{{ $p['name'] }}</h5>
                                <div class="text-muted fs-12">{{ strtoupper($p['code']) }}</div>
                                @if($p['cancellation_scheduled'])
                                    <span class="badge bg-soft-warning text-warning mt-2">{{ __('Cancellation Scheduled') }}</span>
                                @endif
                            </div>
                            <div class="ms-auto text-end">
                                <div class="fw-semibold">{{ number_format($p['quota_mb']) }} MB</div>
                                <div class="text-muted fs-12">{{ __('quota') }}</div>
                                <div class="fw-semibold mt-2">{{ data_get($p, 'price_display.iqd_label') }}</div>
                                <div class="text-muted fs-12">{{ $billingCycle === 'hourly' ? __('per test hour') : __('per month') }}</div>
                                @if($showLocalPrice)
                                    <div class="text-muted fs-12 mt-1">{{ data_get($p, 'price_display.estimated_label') }}</div>
                                @endif
                            </div>
                        </div>

                        <p class="text-muted mb-3">
                            {{ __('Storage quota for uploads and generated outputs.') }}
                        </p>

                        <div class="mt-3 pt-2">
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
                                        wire:click="openConfirm({{ $p['id'] }})"
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
                        @if($selected)
                            <p class="mb-2">
                                {{ __('You are switching to:') }}
                                <b>{{ $selected['name'] }}</b>
                                ({{ strtoupper($selected['code']) }})
                            </p>
                            <p class="mb-2">
                                {{ __('Billing cycle:') }}
                                <b>{{ $billingCycle === 'hourly' ? __('Hourly Test') : __('Monthly') }}</b>
                            </p>
                            <p class="mb-2">
                                {{ __('New quota:') }}
                                <b>{{ number_format($selected['quota_mb']) }} MB</b>
                            </p>
                            <p class="mb-2">
                                {{ __('Price:') }}
                                <b>{{ data_get($selectedDisplay, 'iqd_label') }}</b>
                            </p>
                            @if($billingCycle === 'hourly')
                                <p class="mb-2 text-info small">
                                    {{ __('Hourly billing is a testing-only cycle and reuses the existing storage plan price for fast recurring verification.') }}
                                </p>
                            @endif
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

                        <div class="alert alert-warning mt-3 mb-0">
                            <div class="fw-semibold mb-2">{{ __('Next step: complete payment in First Iraqi Bank') }}</div>
                            <div>{{ __('We will open a dedicated FIB subscription page with QR scan, manual code entry, automatic status refresh, and cancel controls.') }}</div>
                            <div class="small mt-2">
                                {{ __('Storage subscriptions now use the dedicated FIB recurring subscription API, while downgrade and over-quota handling remain server-side after confirmation.') }}
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
                                {{ __('Open FIB Subscription') }}
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
                        <h5 class="modal-title">{{ __('Cancel Storage Plan') }}</h5>
                        <button type="button" class="btn-close" wire:click="closeCancelConfirm" @disabled($processing)></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-2">
                            {{ __('Your current paid storage remains active until:') }}
                            <b>{{ $currentPlanEndsAtLabel ?: __('the end of the current billing period') }}</b>
                        </p>
                        <p class="mb-2">
                            {{ __('Current usage:') }}
                            <b>{{ number_format($usedMb) }} MB</b>
                        </p>
                        <p class="mb-2">
                            {{ __('Current plan limit:') }}
                            <b>{{ number_format($quotaMb) }} MB</b>
                        </p>
                        <p class="mb-2">
                            {{ __('Future limit after downgrade:') }}
                            <b>{{ number_format($futureQuotaMb) }} MB</b>
                        </p>

                        <div class="alert alert-warning mb-0">
                            {{ __('If your usage is above the future limit after the billing period ends, existing files will stay preserved, but uploads and storage-growing actions will be blocked until you delete files or upgrade again.') }}
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
