<?php

namespace App\Support\Admin;

use App\Models\Customer;
use App\Services\Admin\AdminOperations;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\CustomerApi\V2\ApiProblem;
use App\Services\Mcp\CustomerMcpAccessService;
use App\Services\Plans\PlanConcurrencyService;

/** Bounded, local presentation projection. Never submits, settles or repairs anything. */
final class AdminCustomerWorkspace
{
    public function read(int $id): array
    {
        AdminAccess::authorize('admin.read');
        $customer = Customer::findOrFail($id);
        $state = $customer->servicePlanState();
        $reader = app(AdminOperations::class);
        $catalog = app(ApiCatalog::class);
        $mcp = app(CustomerMcpAccessService::class);
        $mcpReason = null;
        try {
            $mcp->assertEligible($customer);
        } catch (ApiProblem $problem) {
            $mcpReason = $problem->errorCode;
        }
        if (! config('mcp.enabled')) {
            $mcpReason = 'feature_disabled';
        }

        $subscription = $state['subscription'];
        $end = $state['period_ends_at'];
        // Agreement end is exclusive; show the inclusive date used by its editor.
        $expiry = $state['agreement'] ? $state['agreement']->ends_at->copy()->subDay()->format('Y-m-d') : $end?->format('Y-m-d');
        // Replace Operations' existing select; first($columns) would retain payments.*.
        $payment = $reader->query('payments', ['customer' => $id])->reorder()->latest('created_at')->withoutEagerLoads()->select([
            'id', 'provider', 'status', 'purchase_type', 'amount', 'currency', 'created_at', 'paid_at', 'fulfilled_at', 'review_required_at',
        ])->first();
        $variants = [];
        foreach ($catalog->variants() as $variant) {
            $variants[$variant['action']] = $variant['tool']['name'] ?? $variant['slug'];
        }

        return [
            'plan' => [
                'name' => $state['current_plan']->name,
                'source_label' => self::sourceLabel($state['source']),
                'access_type' => $state['access_type'],
                'expiry' => $expiry,
                'subscription_id' => $subscription?->id,
                'next_renewal' => $subscription?->auto_renew ? $subscription->next_renewal_on?->format('Y-m-d') : null,
                'has_agreement' => $state['agreement'] !== null,
                'has_pending_agreement' => $state['pending_agreement'] !== null,
            ],
            'app_concurrency' => app(PlanConcurrencyService::class)->allowedConcurrentJobsForCustomer($customer),
            'account_active' => (int) $customer->status === 1,
            'api_allowed' => $catalog->hasAccess($customer),
            'api_gate' => (bool) config('customer_api.v2_enabled'),
            'mcp_available' => $mcpReason === null,
            'mcp_reason' => $mcpReason,
            'action_names' => $variants,
            'jobs' => $reader->query('jobs', ['customer' => $id])->reorder()->latest('created_at')->limit(5)->get()->map(fn ($job) => $reader->row($job))->all(),
            'job_counts' => [
                'active' => $reader->query('jobs', ['customer' => $id, 'group' => 'active'])->count(),
                'queued' => $reader->query('jobs', ['customer' => $id, 'status' => 'queued'])->count(),
                'completed' => $reader->query('jobs', ['customer' => $id, 'group' => 'completed'])->count(),
                'attention' => $reader->query('jobs', ['customer' => $id, 'group' => 'attention'])->count(),
            ],
            'latest_payment' => $payment ? AdminData::redact($payment->toArray()) : null,
            'files_count' => $reader->query('files', ['customer' => $id])->count(),
            'audit' => $reader->query('audit', ['customer' => $id])->reorder()->latest('created_at')->limit(3)->get()->map(fn ($event) => $reader->row($event))->all(),
        ];
    }

    public static function sourceLabel(?string $source): string
    {
        return __('admin_customer.'.match ($source) {
            'fib' => 'source_fib',
            'admin_cash_agreement' => 'source_agreement',
            'admin_manual_grant' => 'source_grant',
            'admin_manual', 'internal_non_revenue' => 'source_internal',
            'system', null => 'source_system',
            default => 'source_other',
        });
    }
}
