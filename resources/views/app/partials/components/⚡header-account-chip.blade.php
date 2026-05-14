<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Support\AppShellData;

new class extends Component
{
    public array $shell = [];
    public int $refreshKey = 0;

    public function mount(): void
    {
        $this->loadData();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('xtts-renders-refresh')]
    #[On('f5tts-renders-refresh')]
    #[On('clone-xtts-renders-refresh')]
    #[On('wasr-renders-refresh')]
    #[On('qasr-renders-refresh')]
    #[On('caption-renders-refresh')]
    #[On('tran-renders-refresh')]
    #[On('stem-renders-refresh')]
    #[On('ocr-renders-refresh')]
    public function refreshHeader(): void
    {
        $this->loadData(forceRefresh: true);
        $this->refreshKey++;
    }

    private function loadData(bool $forceRefresh = false): void
    {
        $this->shell = app(AppShellData::class)->forCurrentCustomer($forceRefresh);
    }

    #[Computed]
    public function balance(): int
    {
        return (int) ($this->shell['credit_balance'] ?? 0);
    }

    #[Computed]
    public function monthly(): int
    {
        return (int) ($this->shell['monthly_credits'] ?? 0);
    }

    #[Computed]
    public function creditsPct(): int
    {
        return (int) ($this->shell['credits_pct'] ?? 0);
    }

    #[Computed]
    public function quotaMb(): int
    {
        return (int) ($this->shell['storage_quota_mb'] ?? 512);
    }

    #[Computed]
    public function usedBytes(): int
    {
        return (int) (($this->usedMb ?? 0) * 1024 * 1024);
    }

    #[Computed]
    public function usedMb(): int
    {
        return (int) ($this->shell['storage_used_mb'] ?? 0);
    }

    #[Computed]
    public function storagePct(): int
    {
        return (int) ($this->shell['storage_pct'] ?? 0);
    }

    public function render()
    {
        return view('app.partials.components.⚡header-account-chip');
    }
};
?>

<div style="min-width:220px;" class="px-0 mb-3" wire:key="header-chip-{{ $refreshKey }}">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <small class="text-muted">{{ __('Credits') }}</small>
        <small class="fw-semibold">{{ number_format($this->balance) }} / {{ number_format($this->monthly) }}</small>
    </div>
    <div class="progress" style="height:6px;">
        <div class="progress-bar" style="width: {{ $this->creditsPct }}%;"></div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-2 mb-1">
        <small class="text-muted">{{ __('Storage') }}</small>
        <small class="fw-semibold">{{ __(':used MB / :total MB', ['used' => number_format($this->usedMb), 'total' => number_format($this->quotaMb)]) }}</small>
    </div>
    <div class="progress" style="height:6px;">
        <div class="progress-bar bg-info" style="width: {{ $this->storagePct }}%;"></div>
    </div>
</div>
