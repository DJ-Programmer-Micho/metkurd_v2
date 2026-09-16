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
    public function record(string $operation, int $customerId, int $planId, string $start, string $expiry, ?int $amount, string $reference, string $reason): array
    {
        $admin = AdminAccess::authorize('admin.finance');
        $this->requireSchema();
        Validator::make(compact('start', 'expiry', 'amount', 'reference'), [
            'start' => 'required|date_format:Y-m-d', 'expiry' => 'required|date_format:Y-m-d|after_or_equal:start',
            'amount' => 'nullable|integer|min:0|max:1000000000000', 'reference' => 'required|string|max:190',
        ])->validate();
        $lifecycle = app(ServiceAgreementLifecycle::class);

        return $lifecycle->underCheckoutLock($customerId, fn () => app(AdminOperationRunner::class)->run($operation, 'admin.finance', 'agreement.record', $customerId,
            ['plan_id' => $planId, 'start' => $start, 'expiry' => $expiry, 'agreed_amount_iqd' => $amount, 'reference' => $reference], $reason,
            function (Customer $customer) use ($planId, $start, $expiry, $amount, $reference, $reason, $admin, $operation, $lifecycle) {
                $starts = Carbon::createFromFormat('!Y-m-d', $start, config('app.timezone'));
                $ends = Carbon::createFromFormat('!Y-m-d', $expiry, config('app.timezone'))->addDay();
                if ($ends->lte(now())) {
                    throw ValidationException::withMessages(['agreement' => __('agreement.expired_dates')]);
                }
                $plan = ServicePlan::whereKey($planId)->where('is_active', true)->where('is_free', false)->lockForUpdate()->firstOrFail();
                if (ServicePlanAgreement::where('customer_id', $customer->id)->whereIn('status', ['scheduled', 'active', 'requires_review'])
                    ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->exists()) {
                    throw ValidationException::withMessages(['agreement' => __('agreement.overlap')]);
                }
                $agreement = ServicePlanAgreement::create(['customer_id' => $customer->id, 'service_plan_id' => $plan->id,
                    'admin_id' => $admin->id, 'operation_id' => $operation, 'starts_at' => $starts, 'ends_at' => $ends,
                    'agreed_amount_iqd' => $amount, 'reference' => $reference, 'reason' => $reason,
                    'app_monthly_credits' => $plan->appMonthlyCredits(), 'api_monthly_credits' => $plan->apiMonthlyCredits(), 'status' => 'scheduled']);
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

    private function requireSchema(): void
    {
        if (! Schema::hasTable('service_plan_agreements')) {
            throw ValidationException::withMessages(['agreement' => __('agreement.migration')]);
        }
    }
}
