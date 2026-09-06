<?php

namespace App\Support\Admin;

use App\Models\CreditOrder;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentMethodCatalog;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesPaymentMethodsPage
{
    use InteractsWithPaymentAdmin;
    use SecureAdminComponent;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    public int $perPage = 12;

    public ?int $editingMethodId = null;

    public string $code = '';

    public string $driver = 'fake';

    public string $name = '';

    public string $description = '';

    public string $icon = '';

    public int $sortOrder = 0;

    public string $supportedCurrenciesText = 'IQD';

    public array $supportedPurchaseTypes = [];

    public bool $supportsRecurring = false;

    public bool $supportsRefunds = false;

    public bool $supportsWebhooks = false;

    public bool $supportsRedirect = false;

    public bool $supportsQr = false;

    public bool $isActive = true;

    public bool $isVisible = true;

    public string $settingsJson = '';

    public string $feeConfigJson = '';

    public string $metaJson = '';

    public ?int $deleteMethodId = null;

    public string $deleteMethodLabel = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function driverOptions(): array
    {
        return app(PaymentProviderManager::class)->driverOptions();
    }

    #[Computed]
    public function purchaseTypeOptions(): array
    {
        return [
            'service_plan' => __('Subscription Plans'),
            'storage_plan' => __('Storage Plans'),
            'credit_product' => __('Add-on Credits'),
        ];
    }

    #[Computed]
    public function topStats(): array
    {
        return [
            'methods' => (int) PaymentMethod::query()->count(),
            'active_methods' => (int) PaymentMethod::query()->where('is_active', true)->count(),
            'visible_methods' => (int) PaymentMethod::query()->where('is_visible', true)->count(),
            'used_methods' => (int) PaymentIntent::query()->distinct('payment_method')->count('payment_method'),
        ];
    }

    protected function methodsBaseQuery(): Builder
    {
        $query = PaymentMethod::query();
        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('driver', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return match ($this->statusFilter) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'visible' => $query->where('is_visible', true),
            'hidden' => $query->where('is_visible', false),
            default => $query,
        };
    }

    #[Computed]
    public function methods()
    {
        return $this->methodsBaseQuery()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    protected function formRules(): array
    {
        return [
            'code' => 'required|string|max:50|alpha_dash|unique:payment_methods,code,'.($this->editingMethodId ?? 'NULL').',id',
            'driver' => 'required|string|in:'.implode(',', array_keys($this->driverOptions)),
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:120',
            'sortOrder' => 'nullable|integer|min:0|max:65535',
            'supportedCurrenciesText' => 'nullable|string|max:255',
            'supportedPurchaseTypes' => 'array',
            'supportedPurchaseTypes.*' => 'string|in:'.implode(',', array_keys($this->purchaseTypeOptions)),
            'settingsJson' => 'nullable|string',
            'feeConfigJson' => 'nullable|string',
            'metaJson' => 'nullable|string',
        ];
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->resetPage();
    }

    public function openCreateMethodModal(): void
    {
        $this->resetMethodForm();
        $this->dispatch('payments-methods:modal-show', id: 'paymentMethodModal');
    }

    public function openEditMethodModal(int $methodId): void
    {
        $method = PaymentMethod::query()->findOrFail($methodId);

        $this->editingMethodId = $method->id;
        $this->code = (string) $method->code;
        $this->driver = (string) $method->driver;
        $this->name = (string) $method->name;
        $this->description = (string) ($method->description ?? '');
        $this->icon = (string) ($method->icon ?? '');
        $this->sortOrder = (int) ($method->sort_order ?? 0);
        $this->supportedCurrenciesText = implode(', ', $method->supported_currencies ?? []);
        $this->supportedPurchaseTypes = collect($method->supported_purchase_types ?? [])
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();
        $this->supportsRecurring = (bool) $method->supports_recurring;
        $this->supportsRefunds = (bool) $method->supports_refunds;
        $this->supportsWebhooks = (bool) $method->supports_webhooks;
        $this->supportsRedirect = (bool) $method->supports_redirect;
        $this->supportsQr = (bool) $method->supports_qr;
        $this->isActive = (bool) $method->is_active;
        $this->isVisible = (bool) $method->is_visible;
        $this->settingsJson = $this->encodeJsonTextarea($method->settings);
        $this->feeConfigJson = $this->encodeJsonTextarea($method->fee_config);
        $this->metaJson = $this->encodeJsonTextarea($method->meta);
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('payments-methods:modal-show', id: 'paymentMethodModal');
    }

    public function saveMethod(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $validated = $this->validate($this->formRules());
        $settings = $this->decodeJsonTextarea($validated['settingsJson'] ?? '', 'settingsJson');
        $feeConfig = $this->decodeJsonTextarea($validated['feeConfigJson'] ?? '', 'feeConfigJson');
        $meta = $this->decodeJsonTextarea($validated['metaJson'] ?? '', 'metaJson');
        $supportedCurrencies = collect(explode(',', (string) ($validated['supportedCurrenciesText'] ?? '')))
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $method = $this->editingMethodId
            ? PaymentMethod::query()->findOrFail($this->editingMethodId)
            : new PaymentMethod;

        $method->fill([
            'code' => strtolower(trim((string) $validated['code'])),
            'driver' => strtolower(trim((string) $validated['driver'])),
            'name' => $validated['name'],
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
            'icon' => trim((string) ($validated['icon'] ?? '')) ?: null,
            'sort_order' => (int) ($validated['sortOrder'] ?? 0),
            'supported_currencies' => $supportedCurrencies,
            'supported_purchase_types' => array_values($validated['supportedPurchaseTypes'] ?? []),
            'supports_recurring' => (bool) $this->supportsRecurring,
            'supports_refunds' => (bool) $this->supportsRefunds,
            'supports_webhooks' => (bool) $this->supportsWebhooks,
            'supports_redirect' => (bool) $this->supportsRedirect,
            'supports_qr' => (bool) $this->supportsQr,
            'is_active' => (bool) $this->isActive,
            'is_visible' => (bool) $this->isVisible,
            'settings' => $settings,
            'fee_config' => $feeConfig,
            'meta' => $meta,
        ]);
        $method->save();

        app(PaymentMethodCatalog::class)->flushCache();

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->editingMethodId ? __('Payment method updated successfully.') : __('Payment method created successfully.')
        );

        $this->resetMethodForm();
        $this->dispatch('payments-methods:modal-hide', id: 'paymentMethodModal');
    }

    public function toggleMethodStatus(int $methodId): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $method = PaymentMethod::query()->findOrFail($methodId);
        $method->update(['is_active' => ! $method->is_active]);
        app(PaymentMethodCatalog::class)->flushCache();

        $this->dispatch('alert', type: 'success', message: $method->is_active
            ? __('Payment method activated successfully.')
            : __('Payment method deactivated successfully.'));
    }

    public function toggleMethodVisibility(int $methodId): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $method = PaymentMethod::query()->findOrFail($methodId);
        $method->update(['is_visible' => ! $method->is_visible]);
        app(PaymentMethodCatalog::class)->flushCache();

        $this->dispatch('alert', type: 'success', message: $method->is_visible
            ? __('Payment method is now visible in checkout.')
            : __('Payment method is now hidden from checkout.'));
    }

    public function confirmDeleteMethod(int $methodId): void
    {
        $method = PaymentMethod::query()->findOrFail($methodId);

        $this->deleteMethodId = $method->id;
        $this->deleteMethodLabel = $method->name;

        $this->dispatch('payments-methods:modal-show', id: 'paymentMethodDeleteModal');
    }

    public function deleteMethod(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $method = PaymentMethod::query()->findOrFail($this->deleteMethodId);

        $hasUsage = PaymentIntent::query()->where('payment_method', $method->code)->exists()
            || CreditOrder::query()->where('payment_method', $method->code)->exists();

        if ($hasUsage) {
            $this->dispatch(
                'alert',
                type: 'error',
                message: __('This payment method already appears in payment history. Deactivate or hide it instead of deleting.')
            );

            return;
        }

        app(\App\Services\Admin\AdminCatalogDeletion::class)->delete($method);
        app(PaymentMethodCatalog::class)->flushCache();
        $this->resetDeleteState();
        $this->dispatch('payments-methods:modal-hide', id: 'paymentMethodDeleteModal');
        $this->dispatch('alert', type: 'success', message: __('Payment method deleted successfully.'));
    }

    public function checkoutReadyBadge(PaymentMethod $method): bool
    {
        return app(PaymentProviderManager::class)->checkoutReady($method);
    }

    public function configurationReadyBadge(PaymentMethod $method): bool
    {
        return app(PaymentProviderManager::class)->configurationReady($method);
    }

    /**
     * @return array<int, string>
     */
    public function configurationIssuesForMethod(PaymentMethod $method): array
    {
        return app(PaymentProviderManager::class)->configurationIssues($method);
    }

    public function driverEnabledBadge(PaymentMethod $method): bool
    {
        return app(PaymentProviderManager::class)->isEnabled($method->driver);
    }

    public function resetMethodForm(): void
    {
        $this->editingMethodId = null;
        $this->code = '';
        $this->driver = 'fake';
        $this->name = '';
        $this->description = '';
        $this->icon = '';
        $this->sortOrder = 0;
        $this->supportedCurrenciesText = 'IQD';
        $this->supportedPurchaseTypes = array_keys($this->purchaseTypeOptions);
        $this->supportsRecurring = false;
        $this->supportsRefunds = false;
        $this->supportsWebhooks = false;
        $this->supportsRedirect = false;
        $this->supportsQr = false;
        $this->isActive = true;
        $this->isVisible = true;
        $this->settingsJson = '';
        $this->feeConfigJson = '';
        $this->metaJson = '';
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function resetDeleteState(): void
    {
        $this->deleteMethodId = null;
        $this->deleteMethodLabel = '';
    }
}
