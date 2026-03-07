<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Models\Customer;

new class extends Component
{
    public ?Customer $customer = null;
    public int $refreshKey = 0;

    public function mount(): void
    {
        $this->loadData();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('xtts-renders-refresh')]
    public function refreshHeader(): void
    {
        $this->loadData();
        $this->refreshKey++;
    }

    private function loadData(): void
    {
        $this->customer = auth('app')->user();

        $this->customer?->loadMissing([
            'usage',
            'wallet',
            'servicePlan',
            'storagePlan',
            'activeServiceSubscription.servicePlan',
            'activeStorageSubscription.storagePlan',
        ]);
    }

    #[Computed]
    public function balance(): int
    {
        return (int) ($this->customer?->wallet?->balance_credits ?? 0);
    }

    #[Computed]
    public function monthly(): int
    {
        return (int) ($this->customer?->servicePlan?->monthly_credits ?? 0);
    }

    #[Computed]
    public function creditsPct(): int
    {
        return $this->monthly > 0
            ? min(100, (int) round(($this->balance / $this->monthly) * 100))
            : 0;
    }

    #[Computed]
    public function quotaMb(): int
    {
        return (int) ($this->customer?->storagePlan?->quota_mb ?? 512);
    }

    #[Computed]
    public function usedBytes(): int
    {
        return (int) ($this->customer?->usage?->storage_used_bytes ?? 0);
    }

    #[Computed]
    public function usedMb(): int
    {
        return (int) round($this->usedBytes / 1024 / 1024);
    }

    #[Computed]
    public function storagePct(): int
    {
        return $this->quotaMb > 0
            ? min(100, (int) round(($this->usedMb / $this->quotaMb) * 100))
            : 0;
    }

    public function render()
    {
        return view('app.partials.components.⚡header-account-chip');
    }
};
?>

<div style="min-width:220px;" class="px-0 mb-3" wire:key="header-chip-{{ $refreshKey }}">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <small class="text-muted">Credits</small>
        <small class="fw-semibold">{{ number_format($this->balance) }} / {{ number_format($this->monthly) }}</small>
    </div>
    <div class="progress" style="height:6px;">
        <div class="progress-bar" style="width: {{ $this->creditsPct }}%;"></div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-2 mb-1">
        <small class="text-muted">Storage</small>
        <small class="fw-semibold">{{ number_format($this->usedMb) }}MB / {{ number_format($this->quotaMb) }}MB</small>
    </div>
    <div class="progress" style="height:6px;">
        <div class="progress-bar bg-info" style="width: {{ $this->storagePct }}%;"></div>
    </div>
</div>