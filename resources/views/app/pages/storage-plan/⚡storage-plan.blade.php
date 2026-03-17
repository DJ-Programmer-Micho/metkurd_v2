{{-- resources/views/app/pages/storage-plan/⚡storage-plan.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\StoragePlan;
use App\Services\Billing\PlanSwitcher;

new
#[Layout('app::layouts.app')]
#[Title('Storage Plan | METKURD')]
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

        $this->plans = StoragePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => (int) $p->id,
                    'code' => (string) $p->code,
                    'name' => (string) $p->name,
                    'quota_mb' => (int) ($p->quota_mb ?? 0),
                    'price_usd' => (float) ($p->price_usd ?? 0),
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

        if (!$this->selectedPlanId) {
            return;
        }

        if ((int) $this->selectedPlanId === (int) $this->currentPlanId) {
            $this->message = 'This is already your current storage plan.';
            return;
        }

        $this->processing = true;
        $this->message = '';

        try {
            app(PlanSwitcher::class)->switchStoragePlan($customer, (int) $this->selectedPlanId, [
                'ui' => 'storage-plan-page',
            ]);

            $this->loadData();

            $this->showConfirm = false;
            $this->selectedPlanId = null;
            $this->message = 'Storage plan updated successfully.';

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

<div>
    <div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <div class="text-center mb-4 pb-2">
                <h4 class="fs-22">Storage Plans</h4>

                <p class="text-muted mb-1 fs-15">
                    Used: <b>{{ number_format($usedMb) }} MB</b> /
                    Quota: <b>{{ number_format($quotaMb) }} MB</b>
                </p>

                <div class="mx-auto mt-3" style="max-width: 420px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted">Storage usage</small>
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
                        You are currently <b>over quota</b>. Uploads should be blocked until you upgrade or delete files.
                    </div>
                @endif

                @if($message)
                    <div class="alert alert-info mt-3 mb-0">{{ $message }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        @foreach($plans as $p)
            @php
                $isCurrent = (int) $p['id'] === (int) $currentPlanId;
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
                                <div class="text-muted fs-12">quota</div>
                                <div class="fw-semibold mt-2">${{ number_format($p['price_usd'], 2) }}</div>
                                <div class="text-muted fs-12">per change</div>
                            </div>
                        </div>

                        <p class="text-muted mb-3">
                            Storage quota for uploads and generated outputs.
                        </p>

                        <div class="mt-3 pt-2">
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
                        <h5 class="modal-title">Confirm Storage Change</h5>
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
                                New quota:
                                <b>{{ number_format($selected['quota_mb']) }} MB</b>
                            </p>
                            <p class="mb-2">
                                Price:
                                <b>${{ number_format($selected['price_usd'], 2) }}</b>
                            </p>
                        @endif

                        <div class="small text-muted">
                            If you downgrade below your used storage, the plan can still be activated,
                            but your account will remain marked as <b>over quota</b> until you delete files or upgrade again.
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
