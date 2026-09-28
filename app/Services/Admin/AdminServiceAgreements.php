<?php

namespace App\Services\Admin;

use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\ServicePlanAgreement;
use App\Services\Billing\ServiceAgreementLifecycle;
use App\Support\Admin\AdminAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminServiceAgreements
{
    public function record(string $operation, int $customerId, int $planId, string $start, string $expiry, ?int $amount, string $reference, string $reason, ?int $appCredits = null, ?int $apiCredits = null, ?int $concurrency = null): array
    {
        $admin = AdminAccess::authorize('admin.finance');
        $this->requireSchema();
        $concurrency ??= (int) config('service_agreements.default_concurrency');
        Validator::make(compact('start', 'expiry', 'amount', 'reference', 'appCredits', 'apiCredits', 'concurrency'), [
            'appCredits' => 'nullable|integer|min:0|max:'.config('service_agreements.max_monthly_credits'),
            'apiCredits' => 'nullable|integer|min:0|max:'.config('service_agreements.max_monthly_credits'),
            'concurrency' => 'required|integer|min:1|max:'.config('service_agreements.max_concurrency'),
            'start' => 'required|date_format:Y-m-d', 'expiry' => 'required|date_format:Y-m-d|after_or_equal:start',
            'amount' => 'nullable|integer|min:0|max:1000000000000', 'reference' => 'required|string|max:190',
        ])->validate();
        $lifecycle = app(ServiceAgreementLifecycle::class);

        return $lifecycle->underCheckoutLock($customerId, fn () => app(AdminOperationRunner::class)->run($operation, 'admin.finance', 'agreement.record', $customerId,
            ['plan_id' => $planId, 'start' => $start, 'expiry' => $expiry, 'agreed_amount_iqd' => $amount, 'reference' => $reference,
                'app_monthly_credits' => $appCredits, 'api_monthly_credits' => $apiCredits, 'concurrent_jobs_limit' => $concurrency], $reason,
            function (Customer $customer) use ($planId, $start, $expiry, $amount, $reference, $reason, $admin, $operation, $lifecycle, $appCredits, $apiCredits, $concurrency) {
                $starts = Carbon::createFromFormat('!Y-m-d', $start, config('app.timezone'));
                $ends = Carbon::createFromFormat('!Y-m-d', $expiry, config('app.timezone'))->addDay();
                if ($ends->lte(now())) {
                    throw ValidationException::withMessages(['agreement' => __('agreement.expired_dates')]);
                }
                $plan = ServicePlan::whereKey($planId)->where('is_active', true)->where('is_free', false)->lockForUpdate()->first();
                if (! $plan) {
                    throw ValidationException::withMessages(['agreementPlanId' => __('admin_cleanup.agreement_plan')]);
                }
                if (ServicePlanAgreement::where('customer_id', $customer->id)->whereIn('status', ['scheduled', 'active', 'requires_review'])
                    ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->exists()) {
                    throw ValidationException::withMessages(['agreement' => __('agreement.overlap')]);
                }
                $agreement = ServicePlanAgreement::create(['customer_id' => $customer->id, 'service_plan_id' => $plan->id,
                    'admin_id' => $admin->id, 'operation_id' => $operation, 'starts_at' => $starts, 'ends_at' => $ends,
                    'agreed_amount_iqd' => $amount, 'reference' => $reference, 'reason' => $reason,
                    'app_monthly_credits' => $appCredits ?? $plan->appMonthlyCredits(), 'api_monthly_credits' => $apiCredits ?? $plan->apiMonthlyCredits(),
                    'concurrent_jobs_limit' => $concurrency, 'status' => 'scheduled']);
                $status = $lifecycle->processLocked($agreement, $customer);

                return ['agreement_id' => $agreement->id, 'status' => $status, 'collection_management' => 'external'];
            }));
    }

    public function retry(string $operation, int $customerId, int $agreementId, string $reason): array
    {
        AdminAccess::authorize('admin.finance');
        $this->requireSchema();
        $lifecycle = app(ServiceAgreementLifecycle::class);

        return $lifecycle->underCheckoutLock($customerId, fn () => app(AdminOperationRunner::class)->run($operation, 'admin.finance', 'agreement.retry', $customerId,
            ['agreement_id' => $agreementId], $reason, function (Customer $customer) use ($agreementId, $lifecycle) {
                $agreement = ServicePlanAgreement::whereKey($agreementId)->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();

                return ['agreement_id' => $agreement->id, 'status' => $lifecycle->processLocked($agreement, $customer)];
            }));
    }

    public function adjust(string $operation, int $customerId, int $agreementId, int $appCredits, int $apiCredits, int $concurrency, string $reason): array
    {
        AdminAccess::authorize('admin.finance');
        $this->requireSchema();
        Validator::make(compact('appCredits', 'apiCredits', 'concurrency'), [
            'appCredits' => 'required|integer|min:0|max:'.config('service_agreements.max_monthly_credits'),
            'apiCredits' => 'required|integer|min:0|max:'.config('service_agreements.max_monthly_credits'),
            'concurrency' => 'required|integer|min:1|max:'.config('service_agreements.max_concurrency'),
        ])->validate();
        $lifecycle = app(ServiceAgreementLifecycle::class);

        return $lifecycle->underCheckoutLock($customerId, fn () => app(AdminOperationRunner::class)->run($operation, 'admin.finance', 'agreement.adjust', $customerId,
            compact('agreementId', 'appCredits', 'apiCredits', 'concurrency'), $reason, function (Customer $customer) use ($agreementId, $appCredits, $apiCredits, $concurrency) {
                $agreement = ServicePlanAgreement::whereKey($agreementId)->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
                if (! in_array($agreement->status, ['scheduled', 'active', 'requires_review'], true) || $agreement->ends_at->lte(now())) {
                    throw ValidationException::withMessages(['agreement' => __('admin_cleanup.agreement_ended')]);
                }
                $before = $agreement->only(['app_monthly_credits', 'api_monthly_credits', 'concurrent_jobs_limit']);
                $agreement->update(['app_monthly_credits' => $appCredits, 'api_monthly_credits' => $apiCredits, 'concurrent_jobs_limit' => $concurrency]);
                $after = $agreement->only(array_keys($before));
                app(AdminAudit::class)->record('agreement.allowances', ServicePlanAgreement::class, $agreement->id, $before, $after, $after);

                // Existing claims remain immutable; new allowance begins at the next unallocated cycle.
                return ['agreement_id' => $agreement->id, 'credits_apply' => 'next_unallocated_cycle', 'concurrency' => $concurrency];
            }));
    }

    private function requireSchema(): void
    {
        if (! Schema::hasColumn('service_plan_agreements', 'concurrent_jobs_limit')) {
            throw ValidationException::withMessages(['agreement' => __('agreement.migration')]);
        }
    }
}
