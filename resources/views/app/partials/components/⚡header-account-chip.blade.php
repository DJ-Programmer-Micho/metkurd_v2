<?php

use App\Support\AppShellData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

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
    #[On('xomni-renders-refresh')]
    #[On('f5tts-renders-refresh')]
    #[On('clone-xtts-renders-refresh')]
    #[On('clone-xomni-renders-refresh')]
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
    public function appCredits(): array
    {
        return (array) ($this->shell['app_credits'] ?? []);
    }

    #[Computed]
    public function apiCredits(): array
    {
        return (array) ($this->shell['api_credits'] ?? []);
    }

    #[Computed]
    public function showApiCredits(): bool
    {
        return (bool) ($this->shell['api_access_enabled'] ?? false) && $this->apiCredits !== [];
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

<div class="px-0 mb-0 app-sidebar-credit-chip" wire:key="header-chip-{{ $refreshKey }}">
    @php
        $appMonthly = data_get($this->appCredits, 'monthly');
        $appPercent = (int) (data_get($this->appCredits, 'percent_remaining') ?? 0);
        $apiMonthly = data_get($this->apiCredits, 'monthly');
        $apiPercent = (int) (data_get($this->apiCredits, 'percent_remaining') ?? 0);
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-1">
        <small class="text-muted">{{ __('App Credits') }}</small>
        <small class="fw-semibold text-end">
            {{ number_format((int) data_get($this->appCredits, 'balance', 0)) }}
            /
            {{ $appMonthly === null ? __('Unlimited') : number_format((int) $appMonthly) }}
        </small>
    </div>
    <div class="progress" style="height:5px;">
        <div class="progress-bar" style="width: {{ $appMonthly === null ? 100 : $appPercent }}%;"></div>
    </div>

    @if($this->showApiCredits)
        <div class="d-flex justify-content-between align-items-center mt-2 mb-1">
            <small class="text-muted">{{ __('API Credits') }}</small>
            <small class="fw-semibold text-end">
                {{ number_format((int) data_get($this->apiCredits, 'balance', 0)) }}
                /
                {{ $apiMonthly === null ? __('Unlimited') : number_format((int) $apiMonthly) }}
            </small>
        </div>
        <div class="progress" style="height:5px;">
            <div class="progress-bar bg-success" style="width: {{ $apiMonthly === null ? 100 : $apiPercent }}%;"></div>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mt-2 mb-1">
        <small class="text-muted">{{ __('Storage') }}</small>
        <small class="fw-semibold text-end">{{ __(':used MB / :total MB', ['used' => number_format($this->usedMb), 'total' => number_format($this->quotaMb)]) }}</small>
    </div>
    <div class="progress" style="height:5px;">
        <div class="progress-bar bg-info" style="width: {{ $this->storagePct }}%;"></div>
    </div>
</div>
