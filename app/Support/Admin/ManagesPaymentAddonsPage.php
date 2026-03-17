<?php

namespace App\Support\Admin;

use App\Models\CreditOrder;
use App\Models\CreditProduct;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesPaymentAddonsPage
{
    use InteractsWithPaymentAdmin;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'sort', keep: true)]
    public string $sortColumn = 'sort_order';

    #[Url(as: 'dir', keep: true)]
    public string $sortDirection = 'asc';

    public int $perPage = 10;

    public ?int $editingProductId = null;

    public string $code = '';
    public string $name = '';
    public $creditsAmount = '';
    public $priceUsd = '';
    public bool $isActive = true;
    public $sortOrder = 0;
    public string $metaJson = '';

    public ?int $deleteProductId = null;
    public string $deleteProductLabel = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->sortColumn = 'sort_order';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['sort_order', 'name', 'credits_amount', 'price_usd', 'paid_orders', 'revenue'];

        if (!in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, ['credits_amount', 'price_usd', 'paid_orders', 'revenue'], true)
            ? 'desc'
            : 'asc';
    }

    protected function productFormRules(): array
    {
        return [
            'code' => 'required|string|max:50|alpha_dash|unique:credit_products,code,' . ($this->editingProductId ?? 'NULL') . ',id',
            'name' => 'required|string|max:120',
            'creditsAmount' => 'required|integer|min:0',
            'priceUsd' => 'required|numeric|min:0',
            'sortOrder' => 'nullable|integer|min:0|max:65535',
            'metaJson' => 'nullable|string',
        ];
    }

    #[Computed]
    public function topStats(): array
    {
        $summary = CreditOrder::query()
            ->where('status', 'paid')
            ->whereNotNull('credit_product_id')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue')
            ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits')
            ->first();

        return [
            'products' => (int) CreditProduct::query()->count(),
            'active_products' => (int) CreditProduct::query()->where('is_active', true)->count(),
            'orders' => (int) ($summary->orders ?? 0),
            'revenue' => (float) ($summary->revenue ?? 0),
            'credits' => (int) ($summary->credits ?? 0),
        ];
    }

    protected function productsBaseQuery(): Builder
    {
        $orderStats = CreditOrder::query()
            ->where('status', 'paid')
            ->whereNotNull('credit_product_id')
            ->groupBy('credit_product_id')
            ->selectRaw('credit_product_id')
            ->selectRaw('COUNT(*) as paid_orders')
            ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue')
            ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits_sold')
            ->selectRaw('MAX(created_at) as last_order_at');

        $query = CreditProduct::query()
            ->leftJoinSub($orderStats, 'product_orders', fn ($join) => $join->on('product_orders.credit_product_id', '=', 'credit_products.id'))
            ->select('credit_products.*')
            ->selectRaw('COALESCE(product_orders.paid_orders, 0) as paid_orders')
            ->selectRaw('COALESCE(product_orders.revenue, 0) as revenue')
            ->selectRaw('COALESCE(product_orders.credits_sold, 0) as credits_sold')
            ->selectRaw('product_orders.last_order_at as last_order_at');

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('credit_products.name', 'like', "%{$search}%")
                    ->orWhere('credit_products.code', 'like', "%{$search}%");
            });
        }

        if ($this->statusFilter === 'active') {
            $query->where('credit_products.is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('credit_products.is_active', false);
        }

        $column = match ($this->sortColumn) {
            'name' => 'credit_products.name',
            'credits_amount' => 'credit_products.credits_amount',
            'price_usd' => 'credit_products.price_usd',
            'paid_orders' => 'paid_orders',
            'revenue' => 'revenue',
            default => 'credit_products.sort_order',
        };

        return $query
            ->orderBy($column, $this->sortDirection)
            ->orderBy('credit_products.name');
    }

    #[Computed]
    public function products()
    {
        return $this->productsBaseQuery()->paginate($this->perPage);
    }

    public function openCreateProductModal(): void
    {
        $this->resetProductForm();
        $this->dispatch('payments-addons:modal-show', id: 'paymentAddonModal');
    }

    public function openEditProductModal(int $productId): void
    {
        $product = CreditProduct::query()->findOrFail($productId);

        $this->editingProductId = $product->id;
        $this->code = (string) $product->code;
        $this->name = (string) $product->name;
        $this->creditsAmount = (int) ($product->credits_amount ?? 0);
        $this->priceUsd = (string) ((float) ($product->price_usd ?? 0));
        $this->isActive = (bool) $product->is_active;
        $this->sortOrder = (int) ($product->sort_order ?? 0);
        $this->metaJson = $this->encodeJsonTextarea($product->meta);
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('payments-addons:modal-show', id: 'paymentAddonModal');
    }

    public function saveProduct(): void
    {
        $validated = $this->validate($this->productFormRules());
        $meta = $this->decodeJsonTextarea($validated['metaJson'] ?? '', 'metaJson');

        $product = $this->editingProductId
            ? CreditProduct::query()->findOrFail($this->editingProductId)
            : new CreditProduct();
        $product->fill([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'credits_amount' => (int) $validated['creditsAmount'],
            'price_usd' => (float) $validated['priceUsd'],
            'is_active' => (bool) $this->isActive,
            'sort_order' => (int) ($validated['sortOrder'] ?? 0),
            'meta' => $meta,
        ]);
        $product->save();

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->editingProductId ? 'Credit product updated successfully.' : 'Credit product created successfully.'
        );

        $this->resetProductForm();
        $this->dispatch('payments-addons:modal-hide', id: 'paymentAddonModal');
    }

    public function toggleProductStatus(int $productId): void
    {
        $product = CreditProduct::query()->findOrFail($productId);
        $product->update(['is_active' => !$product->is_active]);

        $this->dispatch(
            'alert',
            type: 'success',
            message: $product->is_active ? 'Credit product activated successfully.' : 'Credit product deactivated successfully.'
        );
    }

    public function confirmDeleteProduct(int $productId): void
    {
        $product = CreditProduct::query()->findOrFail($productId);

        $this->deleteProductId = $product->id;
        $this->deleteProductLabel = $product->name;

        $this->dispatch('payments-addons:modal-show', id: 'paymentAddonDeleteModal');
    }

    public function deleteProduct(): void
    {
        $product = CreditProduct::query()->findOrFail($this->deleteProductId);

        if ($product->orders()->exists()) {
            $this->dispatch(
                'alert',
                type: 'error',
                message: 'This credit product has paid orders. Deactivate it instead of deleting.'
            );

            return;
        }

        $product->delete();
        $this->resetDeleteState();
        $this->dispatch('payments-addons:modal-hide', id: 'paymentAddonDeleteModal');
        $this->dispatch('alert', type: 'success', message: 'Credit product deleted successfully.');
    }

    public function resetProductForm(): void
    {
        $this->editingProductId = null;
        $this->code = '';
        $this->name = '';
        $this->creditsAmount = '';
        $this->priceUsd = '';
        $this->isActive = true;
        $this->sortOrder = 0;
        $this->metaJson = '';
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function resetDeleteState(): void
    {
        $this->deleteProductId = null;
        $this->deleteProductLabel = '';
    }
}
