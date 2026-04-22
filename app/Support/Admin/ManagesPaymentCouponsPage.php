<?php

namespace App\Support\Admin;

use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Enums\CouponDiscountType;
use App\Enums\CouponDurationType;
use App\Enums\CouponRedemptionStatus;
use App\Enums\CouponTargetType;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CreditProduct;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Coupons\CouponService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesPaymentCouponsPage
{
    use InteractsWithPaymentAdmin;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'target', keep: true)]
    public string $targetFilter = 'all';

    #[Url(as: 'sort', keep: true)]
    public string $sortColumn = 'created_at';

    #[Url(as: 'dir', keep: true)]
    public string $sortDirection = 'desc';

    public int $perPage = 12;

    public ?int $editingCouponId = null;

    public string $code = '';
    public string $name = '';
    public string $description = '';
    public bool $isActive = true;
    public bool $isPublic = true;
    public bool $isStackable = false;
    public string $discountType = 'percent';
    public $discountValue = '';
    public string $targetType = 'plan_subscription';
    public array $selectedServicePlanCodes = [];
    public array $selectedStoragePlanCodes = [];
    public array $selectedAddonCodes = [];
    public array $selectedBillingCycles = [];
    public bool $firstTimeSubscribersOnly = false;
    public string $durationType = 'forever';
    public $durationCycles = '';
    public $maxTotalUses = '';
    public $maxUsesPerCustomer = '';
    public $minimumAmountIqd = '';
    public string $startsAtLocal = '';
    public string $endsAtLocal = '';
    public string $metadataJson = '';

    public ?int $deleteCouponId = null;
    public string $deleteCouponLabel = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTargetFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTargetType(string $value): void
    {
        $this->applyTargetMatrix($value);
    }

    public function updatedDurationType(string $value): void
    {
        if ($value !== CouponDurationType::FIRST_N_CYCLES->value) {
            $this->durationCycles = '';
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->targetFilter = 'all';
        $this->sortColumn = 'created_at';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['created_at', 'code', 'name', 'used_count', 'max_total_uses'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, ['used_count', 'max_total_uses'], true) ? 'desc' : 'asc';
    }

    protected function couponFormRules(): array
    {
        return [
            'code' => 'required|string|max:80|alpha_dash|unique:coupons,code,' . ($this->editingCouponId ?? 'NULL') . ',id',
            'name' => 'required|string|max:120',
            'description' => 'nullable|string',
            'discountType' => 'required|string|in:percent,fixed',
            'discountValue' => 'required|numeric|min:0.01',
            'targetType' => 'required|string|in:all,plan_subscription,storage_subscription,addon_credits',
            'selectedServicePlanCodes' => 'array',
            'selectedServicePlanCodes.*' => 'string|exists:service_plans,code',
            'selectedStoragePlanCodes' => 'array',
            'selectedStoragePlanCodes.*' => 'string|exists:storage_plans,code',
            'selectedAddonCodes' => 'array',
            'selectedAddonCodes.*' => 'string|exists:credit_products,code',
            'selectedBillingCycles' => 'array',
            'selectedBillingCycles.*' => 'string',
            'durationType' => 'nullable|string|in:once,first_cycle,first_n_cycles,forever',
            'durationCycles' => 'nullable|integer|min:1|max:365',
            'maxTotalUses' => 'nullable|integer|min:1',
            'maxUsesPerCustomer' => 'nullable|integer|min:1',
            'minimumAmountIqd' => 'nullable|integer|min:1',
            'startsAtLocal' => 'nullable|string',
            'endsAtLocal' => 'nullable|string',
            'metadataJson' => 'nullable|string',
        ];
    }

    #[Computed]
    public function topStats(): array
    {
        $redemptionSummary = CouponRedemption::query()
            ->selectRaw('COUNT(*) as total_redemptions')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as consumed_redemptions', [CouponRedemptionStatus::CONSUMED->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) as in_checkout', [
                CouponRedemptionStatus::RESERVED->value,
                CouponRedemptionStatus::APPLIED->value,
            ])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN discount_amount_iqd ELSE 0 END), 0) as discounted_value_iqd', [CouponRedemptionStatus::CONSUMED->value])
            ->first();

        return [
            'coupons' => (int) Coupon::query()->count(),
            'active_coupons' => (int) Coupon::query()->where('is_active', true)->count(),
            'private_coupons' => (int) Coupon::query()->where('is_public', false)->count(),
            'total_redemptions' => (int) ($redemptionSummary->total_redemptions ?? 0),
            'consumed_redemptions' => (int) ($redemptionSummary->consumed_redemptions ?? 0),
            'in_checkout' => (int) ($redemptionSummary->in_checkout ?? 0),
            'discounted_value_iqd' => (float) ($redemptionSummary->discounted_value_iqd ?? 0),
        ];
    }

    protected function couponsBaseQuery(): Builder
    {
        $query = Coupon::query()
            ->withCount([
                'redemptions as consumed_redemptions_count' => fn (Builder $builder) => $builder->where('status', CouponRedemptionStatus::CONSUMED->value),
                'redemptions as live_redemptions_count' => fn (Builder $builder) => $builder->whereIn('status', [
                    CouponRedemptionStatus::RESERVED->value,
                    CouponRedemptionStatus::APPLIED->value,
                ]),
            ]);

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        } elseif ($this->statusFilter === 'scheduled') {
            $query->where(function (Builder $builder) {
                $builder
                    ->whereNotNull('starts_at')
                    ->orWhereNotNull('ends_at');
            });
        }

        if ($this->targetFilter !== 'all') {
            $query->where('target_type', $this->targetFilter);
        }

        $column = match ($this->sortColumn) {
            'code' => 'code',
            'name' => 'name',
            'used_count' => 'used_count',
            'max_total_uses' => 'max_total_uses',
            default => 'created_at',
        };

        return $query
            ->orderBy($column, $this->sortDirection)
            ->orderBy('id', 'desc');
    }

    #[Computed]
    public function coupons()
    {
        return $this->couponsBaseQuery()->paginate($this->perPage);
    }

    #[Computed]
    public function recentRedemptions()
    {
        return CouponRedemption::query()
            ->with(['coupon:id,code,name', 'customer:id,username,email'])
            ->latest('id')
            ->limit(12)
            ->get();
    }

    #[Computed]
    public function servicePlanChoices(): array
    {
        return ServicePlan::query()
            ->where('is_active', true)
            ->where('is_free', false)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ServicePlan $plan) => [
                'code' => (string) $plan->code,
                'name' => (string) $plan->name,
                'credits' => (int) ($plan->monthly_credits ?? 0),
                'monthly_price' => $this->formatCanonicalMoneyWithOptionalDisplay($plan->priceIqdForCycle('monthly')),
                'yearly_price' => $this->formatCanonicalMoneyWithOptionalDisplay($plan->priceIqdForCycle('yearly')),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function storagePlanChoices(): array
    {
        return StoragePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (StoragePlan $plan) => $plan->priceIqdAmount() > 0)
            ->map(fn (StoragePlan $plan) => [
                'code' => (string) $plan->code,
                'name' => (string) $plan->name,
                'quota' => $this->formatStorageQuota($plan->quota_mb),
                'price' => $this->formatCanonicalMoneyWithOptionalDisplay($plan->priceIqdAmount()),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function addonChoices(): array
    {
        return CreditProduct::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CreditProduct $product) => [
                'code' => (string) $product->code,
                'name' => (string) $product->name,
                'credits' => $this->formatCredits($product->credits_amount),
                'price' => $this->formatCanonicalMoneyWithOptionalDisplay($product->priceIqdAmount()),
            ])
            ->values()
            ->all();
    }

    public function openCreateCouponModal(): void
    {
        $this->resetCouponForm();
        $this->dispatch('payments-coupons:modal-show', id: 'paymentCouponModal');
    }

    public function openEditCouponModal(int $couponId): void
    {
        $coupon = Coupon::query()->findOrFail($couponId);

        $this->editingCouponId = $coupon->id;
        $this->code = (string) $coupon->code;
        $this->name = (string) $coupon->name;
        $this->description = (string) ($coupon->description ?? '');
        $this->isActive = (bool) $coupon->is_active;
        $this->isPublic = (bool) $coupon->is_public;
        $this->isStackable = (bool) $coupon->is_stackable;
        $this->discountType = (string) ($coupon->discount_type?->value ?? CouponDiscountType::PERCENT->value);
        $this->discountValue = (string) ($coupon->discount_value ?? '');
        $this->targetType = (string) ($coupon->target_type?->value ?? CouponTargetType::PLAN_SUBSCRIPTION->value);
        $this->selectedServicePlanCodes = $this->selectionValuesFromStoredCodes($coupon->applies_to_codes);
        $this->selectedStoragePlanCodes = $this->selectionValuesFromStoredCodes($coupon->applies_to_codes);
        $this->selectedAddonCodes = $this->selectionValuesFromStoredCodes($coupon->applies_to_codes);
        $this->selectedBillingCycles = collect($coupon->applies_to_billing_cycles ?? [])
            ->map(fn (mixed $value) => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();
        $this->firstTimeSubscribersOnly = (bool) $coupon->first_time_subscribers_only;
        $this->durationType = (string) ($coupon->duration_type?->value ?? CouponDurationType::FOREVER->value);
        $this->durationCycles = $coupon->duration_cycles !== null ? (string) $coupon->duration_cycles : '';
        $this->maxTotalUses = $coupon->max_total_uses !== null ? (string) $coupon->max_total_uses : '';
        $this->maxUsesPerCustomer = $coupon->max_uses_per_customer !== null ? (string) $coupon->max_uses_per_customer : '';
        $this->minimumAmountIqd = $coupon->minimum_amount_iqd !== null ? (string) ((int) round((float) $coupon->minimum_amount_iqd)) : '';
        $this->startsAtLocal = $coupon->starts_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i') ?? '';
        $this->endsAtLocal = $coupon->ends_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i') ?? '';
        $this->metadataJson = $this->encodeJsonTextarea($coupon->metadata);
        $this->applyTargetMatrix($this->targetType, preserveExistingDuration: true);
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('payments-coupons:modal-show', id: 'paymentCouponModal');
    }

    public function saveCoupon(): void
    {
        $validated = $this->validate($this->couponFormRules());
        $metadata = $this->decodeJsonTextarea($validated['metadataJson'] ?? '', 'metadataJson');
        $discountValue = (float) $validated['discountValue'];
        $discountType = $validated['discountType'];
        $targetType = (string) $validated['targetType'];

        if ($targetType === CouponTargetType::ALL->value) {
            throw ValidationException::withMessages([
                'targetType' => __('Choose a specific coupon target. One-time add-ons and recurring subscriptions do not share the same duration capabilities in the current billing flow.'),
            ]);
        }

        if ($discountType === CouponDiscountType::PERCENT->value && ($discountValue <= 0 || $discountValue > 100)) {
            throw ValidationException::withMessages([
                'discountValue' => __('Percentage discounts must be greater than 0 and not exceed 100.'),
            ]);
        }

        $startsAt = $this->parseLocalDateTime($validated['startsAtLocal'] ?? '', 'startsAtLocal');
        $endsAt = $this->parseLocalDateTime($validated['endsAtLocal'] ?? '', 'endsAtLocal');

        if ($startsAt && $endsAt && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'endsAtLocal' => __('The end date must be after the start date.'),
            ]);
        }

        $allowedCodes = $this->selectedItemCodesForTarget($targetType);
        $allowedCycles = $this->selectedBillingCyclesForTarget($targetType);
        $durationType = $this->resolvedDurationTypeForTarget(
            $targetType,
            $validated['durationType'] ?? null,
            $validated['durationCycles'] ?? null,
        );
        $durationCycles = $durationType === CouponDurationType::FIRST_N_CYCLES->value
            ? (int) ($validated['durationCycles'] ?? 0)
            : null;
        $firstTimeSubscribersOnly = $targetType === CouponTargetType::PLAN_SUBSCRIPTION->value
            ? (bool) $this->firstTimeSubscribersOnly
            : false;

        $coupon = $this->editingCouponId
            ? Coupon::query()->findOrFail($this->editingCouponId)
            : new Coupon();

        $coupon->fill([
            'code' => strtoupper(trim($validated['code'])),
            'name' => $validated['name'],
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
            'is_active' => (bool) $this->isActive,
            'is_public' => (bool) $this->isPublic,
            'is_stackable' => (bool) $this->isStackable,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'target_type' => $targetType,
            'applies_to_codes' => $allowedCodes !== [] ? $allowedCodes : null,
            'applies_to_billing_cycles' => $allowedCycles !== [] ? $allowedCycles : null,
            'first_time_subscribers_only' => $firstTimeSubscribersOnly,
            'duration_type' => $durationType,
            'duration_cycles' => $durationCycles,
            'max_total_uses' => $this->nullableInt($validated['maxTotalUses'] ?? null),
            'max_uses_per_customer' => $this->nullableInt($validated['maxUsesPerCustomer'] ?? null),
            'minimum_amount_iqd' => $this->nullableInt($validated['minimumAmountIqd'] ?? null),
            'currency' => 'IQD',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'metadata' => $metadata,
        ]);

        $coupon->save();

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->editingCouponId ? __('Coupon updated successfully.') : __('Coupon created successfully.')
        );

        $this->resetCouponForm();
        $this->dispatch('payments-coupons:modal-hide', id: 'paymentCouponModal');
    }

    public function toggleCouponStatus(int $couponId): void
    {
        $coupon = Coupon::query()->findOrFail($couponId);
        $coupon->update(['is_active' => ! $coupon->is_active]);

        $this->dispatch(
            'alert',
            type: 'success',
            message: $coupon->is_active ? __('Coupon activated successfully.') : __('Coupon deactivated successfully.')
        );
    }

    public function confirmDeleteCoupon(int $couponId): void
    {
        $coupon = Coupon::query()->findOrFail($couponId);
        $this->deleteCouponId = $coupon->id;
        $this->deleteCouponLabel = $coupon->code;

        $this->dispatch('payments-coupons:modal-show', id: 'paymentCouponDeleteModal');
    }

    public function deleteCoupon(): void
    {
        $coupon = Coupon::query()->findOrFail($this->deleteCouponId);

        if ($coupon->redemptions()->exists()) {
            $this->dispatch(
                'alert',
                type: 'warning',
                message: __('Coupons with redemption history cannot be deleted. Deactivate the coupon instead.')
            );
            $this->dispatch('payments-coupons:modal-hide', id: 'paymentCouponDeleteModal');

            return;
        }

        $coupon->delete();

        $this->dispatch(
            'alert',
            type: 'success',
            message: __('Coupon deleted successfully.')
        );

        $this->deleteCouponId = null;
        $this->deleteCouponLabel = '';
        $this->dispatch('payments-coupons:modal-hide', id: 'paymentCouponDeleteModal');
    }

    public function targetFilterOptions(): array
    {
        return [
            'all' => __('All Targets'),
            CouponTargetType::PLAN_SUBSCRIPTION->value => __('Plan Subscription'),
            CouponTargetType::STORAGE_SUBSCRIPTION->value => __('Storage Subscription'),
            CouponTargetType::ADDON_CREDITS->value => __('Add-on Payment'),
        ];
    }

    public function targetOptions(): array
    {
        $options = [
            CouponTargetType::PLAN_SUBSCRIPTION->value => __('Plan Subscription'),
            CouponTargetType::STORAGE_SUBSCRIPTION->value => __('Storage Subscription'),
            CouponTargetType::ADDON_CREDITS->value => __('Add-on Payment'),
        ];

        if ($this->targetType === CouponTargetType::ALL->value) {
            $options = [CouponTargetType::ALL->value => __('All Checkout Types (legacy)')] + $options;
        }

        return $options;
    }

    public function targetLabel(?string $targetType): string
    {
        return match ($targetType) {
            CouponTargetType::PLAN_SUBSCRIPTION->value => __('Plan Subscription'),
            CouponTargetType::STORAGE_SUBSCRIPTION->value => __('Storage Subscription'),
            CouponTargetType::ADDON_CREDITS->value => __('Add-on Payment'),
            default => __('All Checkout Types (legacy)'),
        };
    }

    public function durationOptions(): array
    {
        if ($this->isRecurringTarget()) {
            return [
                CouponDurationType::FOREVER->value => __('Discount every recurring cycle'),
            ];
        }

        return [
            CouponDurationType::ONCE->value => __('Apply once to this one-time checkout'),
        ];
    }

    public function unsupportedRecurringDurationOptions(): array
    {
        return [
            __('First cycle only'),
            __('First N cycles'),
        ];
    }

    public function discountTypeOptions(): array
    {
        return [
            CouponDiscountType::PERCENT->value => __('Percentage'),
            CouponDiscountType::FIXED->value => __('Fixed Amount (IQD)'),
        ];
    }

    public function billingCycleOptions(): array
    {
        return $this->billingCycleOptionsForTarget($this->targetType);
    }

    public function isRecurringTarget(?string $targetType = null): bool
    {
        return in_array($targetType ?? $this->targetType, [
            CouponTargetType::PLAN_SUBSCRIPTION->value,
            CouponTargetType::STORAGE_SUBSCRIPTION->value,
        ], true);
    }

    public function isPlanTarget(?string $targetType = null): bool
    {
        return ($targetType ?? $this->targetType) === CouponTargetType::PLAN_SUBSCRIPTION->value;
    }

    public function isStorageTarget(?string $targetType = null): bool
    {
        return ($targetType ?? $this->targetType) === CouponTargetType::STORAGE_SUBSCRIPTION->value;
    }

    public function isAddonTarget(?string $targetType = null): bool
    {
        return ($targetType ?? $this->targetType) === CouponTargetType::ADDON_CREDITS->value;
    }

    public function showBillingCycleRestrictions(): bool
    {
        return $this->isRecurringTarget();
    }

    public function showFirstTimeSubscriberRule(): bool
    {
        return $this->isPlanTarget();
    }

    public function recurringDurationHelpText(): string
    {
        return __('The current FIB subscription checkout creates one fixed recurring amount. That means a recurring coupon can safely discount every cycle, but it cannot automatically switch from discounted early cycles to full-price later renewals.');
    }

    public function recurringUnsupportedHelpText(): string
    {
        return __('Not supported with the current FIB recurring flow: first-cycle-only recurring discounts and first-N-cycle recurring discounts.');
    }

    public function selectedRecurringDurationCompatibilityMessage(): ?string
    {
        if (! $this->isRecurringTarget()) {
            return null;
        }

        return app(CouponService::class)->recurringDurationCompatibilityMessage(
            $this->durationType,
            'fib',
            $this->durationCycles !== '' ? (int) $this->durationCycles : null,
        );
    }

    public function describeCouponWindow(Coupon $coupon): string
    {
        $parts = [];

        if ($coupon->starts_at) {
            $parts[] = __('Starts :date', ['date' => $coupon->starts_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]);
        }

        if ($coupon->ends_at) {
            $parts[] = __('Ends :date', ['date' => $coupon->ends_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]);
        }

        return $parts === [] ? __('Always available while active') : implode(' | ', $parts);
    }

    public function describeCouponDiscount(Coupon $coupon): string
    {
        return match ($coupon->discount_type) {
            CouponDiscountType::PERCENT => __(':value% off', ['value' => rtrim(rtrim(number_format((float) $coupon->discount_value, 2, '.', ''), '0'), '.')]),
            default => __(':amount off', ['amount' => $this->formatMoney((float) $coupon->discount_value, 'IQD')]),
        };
    }

    public function describeCouponDuration(Coupon $coupon): string
    {
        $label = match ($coupon->duration_type) {
            CouponDurationType::FIRST_CYCLE => __('First cycle only'),
            CouponDurationType::FIRST_N_CYCLES => __('First :count cycles', ['count' => number_format((int) ($coupon->duration_cycles ?? 1))]),
            CouponDurationType::FOREVER => __('Every eligible cycle'),
            default => __('One checkout only'),
        };

        if ($this->couponHasUnsupportedRecurringDuration($coupon)) {
            return __('Unsupported for the current FIB recurring flow: :duration', ['duration' => $label]);
        }

        return $label;
    }

    public function describeCouponRestrictions(Coupon $coupon): string
    {
        $parts = [];

        if (! empty($coupon->applies_to_codes)) {
            $parts[] = __('Items: :items', ['items' => implode(', ', (array) $coupon->applies_to_codes)]);
        }

        if (! empty($coupon->applies_to_billing_cycles)) {
            $parts[] = __('Cycles: :cycles', ['cycles' => implode(', ', array_map('ucfirst', (array) $coupon->applies_to_billing_cycles))]);
        }

        if ($coupon->first_time_subscribers_only) {
            $parts[] = __('First paid plan subscribers only');
        }

        if ($coupon->minimum_amount_iqd !== null) {
            $parts[] = __('Minimum :amount', ['amount' => $this->formatMoney((float) $coupon->minimum_amount_iqd, 'IQD')]);
        }

        return $parts === [] ? __('No extra restrictions') : implode(' | ', $parts);
    }

    public function couponHasUnsupportedRecurringDuration(Coupon $coupon): bool
    {
        return $this->isRecurringTarget($coupon->target_type?->value)
            && ($coupon->duration_type ?? CouponDurationType::ONCE) !== CouponDurationType::FOREVER;
    }

    public function resetCouponForm(): void
    {
        $this->editingCouponId = null;
        $this->code = '';
        $this->name = '';
        $this->description = '';
        $this->isActive = true;
        $this->isPublic = true;
        $this->isStackable = false;
        $this->discountType = CouponDiscountType::PERCENT->value;
        $this->discountValue = '';
        $this->targetType = CouponTargetType::PLAN_SUBSCRIPTION->value;
        $this->selectedServicePlanCodes = [];
        $this->selectedStoragePlanCodes = [];
        $this->selectedAddonCodes = [];
        $this->selectedBillingCycles = [];
        $this->firstTimeSubscribersOnly = false;
        $this->durationType = CouponDurationType::FOREVER->value;
        $this->durationCycles = '';
        $this->maxTotalUses = '';
        $this->maxUsesPerCustomer = '';
        $this->minimumAmountIqd = '';
        $this->startsAtLocal = '';
        $this->endsAtLocal = '';
        $this->metadataJson = '';
        $this->applyTargetMatrix($this->targetType);
        $this->resetErrorBag();
        $this->resetValidation();
    }

    protected function applyTargetMatrix(?string $targetType = null, bool $preserveExistingDuration = false): void
    {
        $targetType = (string) ($targetType ?? $this->targetType);
        $this->targetType = $targetType;

        if ($this->isPlanTarget($targetType)) {
            $this->selectedStoragePlanCodes = [];
            $this->selectedAddonCodes = [];
            $this->selectedBillingCycles = array_values(array_intersect(
                $this->selectedBillingCycles,
                array_keys($this->billingCycleOptionsForTarget($targetType))
            ));
            $this->normalizeRecurringDurationState($preserveExistingDuration);

            return;
        }

        if ($this->isStorageTarget($targetType)) {
            $this->selectedServicePlanCodes = [];
            $this->selectedAddonCodes = [];
            $this->selectedBillingCycles = array_values(array_intersect(
                $this->selectedBillingCycles,
                array_keys($this->billingCycleOptionsForTarget($targetType))
            ));
            $this->firstTimeSubscribersOnly = false;
            $this->normalizeRecurringDurationState($preserveExistingDuration);

            return;
        }

        if ($this->isAddonTarget($targetType)) {
            $this->selectedServicePlanCodes = [];
            $this->selectedStoragePlanCodes = [];
            $this->selectedBillingCycles = [];
            $this->firstTimeSubscribersOnly = false;
            $this->durationType = CouponDurationType::ONCE->value;
            $this->durationCycles = '';

            return;
        }

        $this->selectedServicePlanCodes = [];
        $this->selectedStoragePlanCodes = [];
        $this->selectedAddonCodes = [];
        $this->selectedBillingCycles = [];
        $this->firstTimeSubscribersOnly = false;
        $this->durationType = CouponDurationType::ONCE->value;
        $this->durationCycles = '';
    }

    protected function normalizeRecurringDurationState(bool $preserveExistingDuration = false): void
    {
        if (! $preserveExistingDuration) {
            $this->durationType = CouponDurationType::FOREVER->value;
            $this->durationCycles = '';

            return;
        }

        $durationType = CouponDurationType::tryFrom((string) $this->durationType);

        if (! $durationType) {
            $this->durationType = CouponDurationType::FOREVER->value;
            $this->durationCycles = '';

            return;
        }

        if ($durationType !== CouponDurationType::FIRST_N_CYCLES) {
            $this->durationCycles = '';
        }
    }

    protected function selectionValuesFromStoredCodes(?array $codes): array
    {
        return collect($codes ?? [])
            ->map(fn (mixed $value) => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();
    }

    protected function selectedItemCodesForTarget(string $targetType): array
    {
        $selected = match ($targetType) {
            CouponTargetType::PLAN_SUBSCRIPTION->value => $this->selectedServicePlanCodes,
            CouponTargetType::STORAGE_SUBSCRIPTION->value => $this->selectedStoragePlanCodes,
            CouponTargetType::ADDON_CREDITS->value => $this->selectedAddonCodes,
            default => [],
        };

        return $this->normalizeCodeSelection($selected);
    }

    protected function selectedBillingCyclesForTarget(string $targetType): array
    {
        if (! $this->isRecurringTarget($targetType)) {
            return [];
        }

        $allowed = array_keys($this->billingCycleOptionsForTarget($targetType));

        return collect($this->selectedBillingCycles)
            ->map(fn (mixed $value) => strtolower(trim((string) $value)))
            ->filter()
            ->when(
                collect($this->selectedBillingCycles)
                    ->map(fn (mixed $value) => strtolower(trim((string) $value)))
                    ->reject(fn (string $value) => in_array($value, $allowed, true))
                    ->isNotEmpty(),
                function ($cycles) use ($allowed) {
                    $invalid = collect($this->selectedBillingCycles)
                        ->map(fn (mixed $value) => strtolower(trim((string) $value)))
                        ->reject(fn (string $value) => in_array($value, $allowed, true))
                        ->implode(', ');

                    throw ValidationException::withMessages([
                        'selectedBillingCycles' => __('Unsupported billing cycles for the selected target: :cycles', ['cycles' => $invalid]),
                    ]);
                }
            )
            ->values()
            ->all();
    }

    protected function resolvedDurationTypeForTarget(string $targetType, mixed $durationType, mixed $durationCycles): string
    {
        if ($this->isAddonTarget($targetType)) {
            return CouponDurationType::ONCE->value;
        }

        $durationType = CouponDurationType::tryFrom((string) $durationType) ?? CouponDurationType::ONCE;
        $durationCycles = $durationCycles !== '' && $durationCycles !== null ? (int) $durationCycles : null;

        if ($this->isRecurringTarget($targetType)) {
            $message = app(CouponService::class)->recurringDurationCompatibilityMessage($durationType, 'fib', $durationCycles);

            if ($message !== null) {
                throw ValidationException::withMessages([
                    'durationType' => $message,
                ]);
            }

            return CouponDurationType::FOREVER->value;
        }

        return $durationType->value;
    }

    protected function normalizeCodeSelection(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value) => strtoupper(trim((string) $value)))
            ->filter()
            ->values()
            ->all();
    }

    protected function billingCycleOptionsForTarget(string $targetType): array
    {
        if ($this->isPlanTarget($targetType)) {
            $options = [
                'monthly' => __('Monthly'),
                'yearly' => __('Yearly'),
            ];

            if ($this->hourlyTestingEnabled()) {
                $options['hourly'] = __('Hourly Test');
            }

            return $options;
        }

        if ($this->isStorageTarget($targetType)) {
            $options = [
                'monthly' => __('Monthly'),
            ];

            if ($this->hourlyTestingEnabled()) {
                $options['hourly'] = __('Hourly Test');
            }

            return $options;
        }

        return [];
    }

    protected function hourlyTestingEnabled(): bool
    {
        return app(FibSubscriptionService::class)->hourlyTestingEnabled();
    }

    protected function parseLocalDateTime(?string $value, string $field): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d\TH:i', $value, config('app.timezone'));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => __('Enter a valid date and time.'),
            ]);
        }
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return max(0, (int) $value);
    }
}
