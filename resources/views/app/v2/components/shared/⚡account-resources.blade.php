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
    public function refreshResources(): void
    {
        $this->loadData(forceRefresh: true);
        $this->refreshKey++;
    }

    #[Computed]
    public function rows(): array
    {
        $locale = app()->getLocale();
        $appCredits = (array) ($this->shell['app_credits'] ?? []);
        $apiCredits = (array) ($this->shell['api_credits'] ?? []);
        $rows = [
            $this->creditRow(
                __('App Credits'),
                $appCredits,
                'app',
                route('app.billing', ['locale' => $locale]),
            ),
        ];

        if ((bool) ($this->shell['api_access_enabled'] ?? false) && $apiCredits !== []) {
            $rows[] = $this->creditRow(
                __('API Credits'),
                $apiCredits,
                'api',
                route('app.v2.api', ['locale' => $locale]),
            );
        }

        $used = (int) ($this->shell['storage_used_mb'] ?? 0);
        $quota = (int) ($this->shell['storage_quota_mb'] ?? 512);
        $rows[] = [
            'label' => __('Storage'),
            'value' => __(':used MB / :total MB', ['used' => number_format($used), 'total' => number_format($quota)]),
            'percent' => $this->visualPercent((int) ($this->shell['storage_pct'] ?? 0)),
            'tone' => 'storage',
            'href' => route('app.v2.storage', ['locale' => $locale]),
        ];

        return $rows;
    }

    private function loadData(bool $forceRefresh = false): void
    {
        $this->shell = app(AppShellData::class)->forCurrentCustomer($forceRefresh);
        $customer = auth('app')->user();
        $this->shell['api_access_enabled'] = $customer !== null && app(\App\Services\CustomerApi\V2\ApiCatalog::class)->scopes($customer) !== [];
        if ($this->shell['api_access_enabled']) {
            $this->shell['api_credits'] = app(\App\Services\Billing\CustomerUsageSummaryService::class)->forApiCustomer($customer)['credits'];
        }
    }

    /** @param array<string,mixed> $credits */
    private function creditRow(string $label, array $credits, string $tone, string $href): array
    {
        $monthly = data_get($credits, 'monthly');

        return [
            'label' => $label,
            'value' => number_format((int) data_get($credits, 'balance', 0)).' / '.($monthly === null ? __('Unlimited') : number_format((int) $monthly)),
            'percent' => $monthly === null ? 100 : $this->visualPercent((int) data_get($credits, 'percent_remaining', 0)),
            'tone' => $tone,
            'href' => $href,
        ];
    }

    private function visualPercent(int $percent): int
    {
        return min(100, max(0, $percent));
    }

    public function render()
    {
        return view('app::v2.components.shared.account-resources');
    }
};
?>
