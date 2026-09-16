<?php

namespace App\Services\Admin;

use App\Domain\Payments\Models\Payment;
use App\Models\AdminAuditEvent;
use App\Models\AdminOperation;
use App\Models\ApiCreditReservation;
use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\CreditLedger;
use App\Models\CreditOrder;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\CustomerFile;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Local evidence only. Never call serializers, billing repair, storage or provider services here. */
class AdminOperations
{
    public const SECTIONS = ['jobs', 'review', 'api', 'payments', 'ledger', 'reservations', 'files', 'subscriptions', 'storage_subscriptions', 'orders', 'keys', 'audit', 'entitlements'];

    public const QUEUES = ['provider_submission_unknown', 'refund_pending', 'delete_failed', 'stuck', 'unfinalized', 'reservation_review', 'payment_review'];

    public const PAGE_SIZE = 25;

    public const JOB_GROUPS = ['attention', 'active', 'completed', 'failed', 'uncertain', 'persistence', 'reservation'];

    public function jobSummary(array $filters): array
    {
        $counts = [];
        unset($filters['group'], $filters['status']);
        foreach (self::JOB_GROUPS as $group) {
            $counts[$group] = $this->query('jobs', $filters + ['group' => $group])->count();
        }

        return $counts;
    }

    private function jobGroup(Builder $query, string $group): void
    {
        if ($group === 'attention') {
            $query->where(function ($q) {
                foreach (['failed', 'uncertain', 'persistence', 'reservation'] as $part) {
                    $q->orWhere(fn ($nested) => $this->jobGroup($nested, $part));
                }
                $q->orWhere('status', 'delete_failed')->orWhere(fn ($q) => $q->whereIn('status', ['queued', 'running', 'saving'])->where('created_at', '<', now()->subHours(2)));
            });
        } elseif ($group === 'active') {
            $query->whereIn('status', ['queued', 'running', 'saving']);
        } elseif ($group === 'completed') {
            $query->where('status', 'done');
        } elseif ($group === 'failed') {
            $query->where(fn ($q) => $q->where('status', 'failed')->orWhere('failure_stage', 'refund_pending'));
        } elseif ($group === 'uncertain') {
            $query->where('failure_stage', 'provider_submission_unknown');
        } elseif ($group === 'reservation') {
            $review = $this->query('review', ['queue' => 'reservation_review'])->select('api_job_id')->reorder();
            $query->whereIn('id', ApiJob::select('ml_job_id')->whereIn('id', $review)->whereColumn('customer_id', 'ml_jobs.customer_id'));
        } elseif ($group === 'persistence') {
            $query->where(function ($q) {
                $q->where(fn ($q) => $q->whereIn('status', ['queued', 'running', 'saving'])->where('output->provider_success', true))
                    ->orWhere(function ($q) {
                        $q->where('status', 'done')->whereRaw("(JSON_EXTRACT(output, '$.text') IS NULL OR JSON_EXTRACT(output, '$.text') IN ('', 'null', '\"\"'))")
                            ->whereNotExists(function ($files) {
                                $files->selectRaw('1')->from('customer_files')->whereColumn('customer_id', 'ml_jobs.customer_id')
                                    ->where('status', 'active')->whereNull('deleted_at')->whereIn('purpose', ['render', 'transcription', 'caption'])
                                    ->where(function ($f) {
                                        $f->where(fn ($f) => $f->where('source_type', 'ml_job')->whereColumn('source_id', 'ml_jobs.id'))
                                            ->orWhereColumn('meta->job_id', 'ml_jobs.id')
                                            ->orWhere(fn ($f) => $f->where('source_type', 'api_job')->whereIn('source_id', ApiJob::select('id')->whereColumn('ml_job_id', 'ml_jobs.id')->whereColumn('customer_id', 'ml_jobs.customer_id')));
                                    });
                            });
                    });
            });
        }
    }

    public function customerLookup(string $search, ?int $selected = null)
    {
        AdminAccess::authorize('admin.read');
        $query = Customer::query()->select(['id', 'uid', 'username', 'email']);
        $search = mb_substr(trim($search), 0, 100);
        $rows = mb_strlen($search) >= 2
            ? $query->where(fn ($q) => $q->where('username', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('uid', 'like', '%'.$search.'%'))
                ->orderBy('id')->limit(20)->get() : collect();
        if ($selected && ! $rows->contains('id', $selected)) {
            if ($record = Customer::query()->select(['id', 'uid', 'username', 'email'])->find($selected)) {
                $rows->prepend($record);
            }
        }

        return $rows;
    }

    public function customer(int $id): array
    {
        AdminAccess::authorize('admin.read');
        $c = Customer::query()->with(['activeServiceSubscription.servicePlan', 'activeServiceSubscription.previousServicePlan', 'activeStorageSubscription.storagePlan'])->findOrFail($id);
        $state = $c->servicePlanState();
        $plan = $state['current_plan'];
        $config = app(CustomerApiAccessService::class)->configForCustomer($c);
        $used = $c->storageUsedBytes();
        $quota = $c->storageQuotaMb() * 1024 * 1024;
        $wallets = CreditWallet::where('customer_id', $id)->get()->keyBy('wallet_type');
        $blocks = ['identity' => $this->fields($c, ['id', 'uid', 'username', 'email', 'status', 'email_verify', 'phone_verify', 'created_at']),
            'plan' => ['plan' => $plan?->name, 'plan_id' => $plan?->id] + ($c->activeServiceSubscription ? $this->subscription($c->activeServiceSubscription) : ['status' => 'not_recorded']),
            'storage' => ['used_bytes' => $used, 'quota_bytes' => $quota, 'over_quota' => $used > $quota,
                'subscription_id' => $c->activeStorageSubscription?->id, 'plan' => $c->currentStoragePlan()?->name],
            'api_access' => ['api_enabled' => $config['api_enabled'], 'scopes' => app(ApiCatalog::class)->scopes($c),
                'requests_per_minute' => $config['requests_per_minute'], 'concurrent_jobs' => $config['concurrent_jobs'],
                'active_jobs' => ApiJob::where('customer_id', $id)->whereIn('status', ['accepted', 'queued', 'processing'])->count()]];
        $blocks['identity']['status'] = (int) $c->status === 1 ? 'active' : 'inactive';
        if ($agreement = $state['agreement'] ?? $state['pending_agreement']) {
            $blocks['plan']['agreement'] = $this->fields($agreement, ['id', 'reference', 'status', 'starts_at', 'ends_at', 'subscription_id']);
        }
        if ($state['externally_managed']) {
            $blocks['plan']['payment_id'] = __('agreement.external');
        }
        foreach (['app', 'api'] as $type) {
            $w = $wallets->get($type);
            $blocks[$type.'_credits'] = ['wallet_type' => $type, 'wallet_id' => $w?->id,
                'balance_credits' => $w?->balance_credits, 'subscription_balance_credits' => $w?->subscription_balance_credits,
                'addon_balance_credits' => $w?->addon_balance_credits,
                'lifetime_earned' => $w?->lifetime_earned, 'lifetime_spent' => $w?->lifetime_spent, 'lifetime_refunded' => $w?->lifetime_refunded,
                'allowance' => $state['allowances'][$type],
                'held_amount' => $type === 'api' ? ApiCreditReservation::where('customer_id', $id)->where('status', 'reserved')->sum('amount') : null,
                'latest_activity' => CreditLedger::where('customer_id', $id)->where('wallet_type', $type)->max('created_at')];
        }
        foreach (app(ApiCatalog::class)->variants() as $variant) {
            $blocks['effective_access'][$variant['action'].' / App'] = $c->isAllowed($variant['action'], 'app');
            $blocks['effective_access'][$variant['action'].' / API'] = in_array($variant['scope'], $blocks['api_access']['scopes'], true) && $c->isAllowed($variant['action'], 'api');
        }

        return $this->safe($blocks);
    }

    public function query(string $section, array $f = []): Builder
    {
        AdminAccess::authorize('admin.read');
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $queue = $f['queue'] ?? '';
        if ($section === 'review') {
            $section = match ($queue) {
                'reservation_review' => 'reservations', 'payment_review' => 'payments', default => 'jobs'
            };
        }
        $q = match ($section) {
            'jobs' => MlJob::with(['toolAction:id,full_code', 'customer:id,username'])
                ->select(['id', 'customer_id', 'tool_action_id', 'status', 'created_at', 'started_at', 'finished_at', 'failure_stage', 'model_key', 'provider', 'provider_job_id', 'endpoint_key', 'credits_charged', 'charge_reference', 'refund_reference', 'refunded_at'])
                ->selectRaw("CASE WHEN JSON_EXTRACT(output, '$.provider_success') = true THEN 1 ELSE 0 END AS recorded_provider_success")
                ->selectRaw("CASE WHEN JSON_EXTRACT(output, '$.text') IS NOT NULL AND JSON_EXTRACT(output, '$.text') NOT IN ('', 'null', '\"\"') THEN 1 ELSE 0 END AS recorded_inline_result"),
            'api' => ApiJob::query(), 'payments' => Payment::query(), 'ledger' => CreditLedger::query(),
            'reservations' => ApiCreditReservation::query(), 'files' => CustomerFile::query(),
            'subscriptions' => CustomerServiceSubscription::with(['servicePlan:id,name', 'previousServicePlan:id,name']),
            'storage_subscriptions' => CustomerStorageSubscription::with('storagePlan:id,name'),
            'orders' => CreditOrder::query(), 'keys' => CustomerApiKey::query()->select(['id', 'customer_id', 'name', 'key_prefix', 'scopes', 'status', 'created_at', 'last_used_at', 'revoked_at']),
            'audit' => AdminAuditEvent::query(), 'entitlements' => PlanEntitlement::with('toolAction:id,full_code'),
        };
        if (in_array($section, ['payments', 'orders'], true)) {
            if (($f['financialEra'] ?? 'current') === 'legacy' && $queue === '') {
                $boundary = app(\App\Services\Billing\BillingReportingBoundary::class)->current();
                if ($boundary) {
                    $table = $section === 'payments' ? 'payments' : 'credit_orders';
                    $q->where(fn ($old) => $old->where($table.'.created_at', '<', $boundary['starts_at'])
                        ->orWhereNull($table.'.created_at')->orWhere($table.'.id', '<=', $boundary[$section === 'payments' ? 'payment_id' : 'credit_order_id']));
                } else {
                    $q->whereRaw('1 = 0');
                }
            } else {
                $q->currentBillingPeriod();
            }
        }
        if ($customer = (int) ($f['customer'] ?? 0)) {
            if ($section === 'audit') {
                $this->auditCustomer($q, $customer);
            } elseif ($section === 'entitlements') {
                $q->where('service_plan_id', Customer::findOrFail($customer)->currentServicePlanId() ?? 0);
            } else {
                $q->where($q->getModel()->getTable().'.customer_id', $customer);
            }
        } elseif ($section === 'entitlements') {
            $q->whereRaw('1 = 0');
        }
        $searchFields = match ($section) {
            'jobs' => ['id', 'provider_job_id'], 'api' => ['id', 'ml_job_id', 'tool_code'],
            'payments' => ['id', 'uuid', 'local_reference', 'fib_payment_id', 'fib_subscription_id'],
            'ledger' => ['reference_code', 'source_id', 'related_id', 'ml_job_id', 'api_job_id'],
            'keys' => ['name', 'key_prefix'], 'audit' => ['action', 'target_id', 'operation_id'],
            'files' => ['id', 'source_id', 'purpose'], 'reservations' => ['id', 'api_job_id'], default => ['id'],
        };
        if ($search = mb_substr(trim($f['search'] ?? ''), 0, 100)) {
            $q->where(function ($q) use ($searchFields, $search) {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'like', '%'.$search.'%');
                }
            });
        }
        if (! empty($f['status']) && in_array($section, ['jobs', 'api', 'payments', 'reservations', 'files', 'keys', 'subscriptions', 'storage_subscriptions', 'orders'], true)) {
            $q->where('status', mb_substr($f['status'], 0, 60));
        }
        foreach (['from' => '>=', 'until' => '<='] as $key => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$key] ?? '')) {
                $q->whereDate('created_at', $op, $f[$key]);
            }
        }
        if ($section === 'jobs') {
            if (in_array($f['group'] ?? '', self::JOB_GROUPS, true)) {
                $this->jobGroup($q, $f['group']);
            }
            if (in_array($f['channel'] ?? '', ['app', 'api'], true)) {
                $apiIds = ApiJob::select('ml_job_id')->whereNotNull('ml_job_id');
                ($f['channel'] === 'api') ? $q->whereIn('id', $apiIds) : $q->whereNotIn('id', $apiIds);
            }
            if (! empty($f['service'])) {
                $q->whereHas('toolAction', fn ($a) => $a->where('full_code', $f['service']));
            }
            if (! empty($f['failure'])) {
                $q->where('failure_stage', mb_substr($f['failure'], 0, 80));
            }
            if (in_array($queue, ['provider_submission_unknown', 'refund_pending'], true)) {
                $q->where('failure_stage', $queue);
            }
            if ($queue === 'delete_failed') {
                $q->where('status', 'delete_failed');
            }
            if ($queue === 'stuck') {
                $q->active()->where('created_at', '<', now()->subHours(2));
            }
            // Only explicit persisted provider evidence; absence is not completion evidence.
            if ($queue === 'unfinalized') {
                $q->whereIn('status', ['queued', 'running', 'saving'])->where('output->provider_success', true);
            }
        }
        if ($section === 'api' && ! empty($f['service'])) {
            $q->where('tool_action', $f['service']);
        }
        if ($section === 'payments') {
            if (! empty($f['method'])) {
                $q->where('provider', $f['method']);
            }
            if ($queue === 'payment_review') {
                $q->where(fn ($review) => $review->where(fn ($open) => $open->where('internal_status', 'requires_review')
                    ->where(fn ($resolution) => $resolution->whereNull('meta->review_resolution->closed_at')->orWhere('meta->review_resolution->closed_at', '')))
                    ->orWhere('meta->provider_cancellation->provider_cancel_pending', true));
            }
        }
        if ($section === 'reservations' && $queue === 'reservation_review') {
            $q->where('status', 'reserved')->where(fn ($q) => $q->where('created_at', '<', now()->subHours(2))
                ->orWhereHas('apiJob', fn ($a) => $a->whereIn('status', ['completed', 'failed', 'cancelled']))->orWhereDoesntHave('apiJob'));
        }
        if ($section === 'ledger') {
            if (in_array($f['channel'] ?? '', ['app', 'api'], true)) {
                $q->where('wallet_type', $f['channel']);
            }
            if (($f['direction'] ?? '') === 'debit') {
                $q->where('credits_delta', '<', 0);
            }
            if (($f['direction'] ?? '') === 'credit') {
                $q->where('credits_delta', '>', 0);
            }
        }
        if ($jobId = ($f['job'] ?? '')) {
            $this->jobScope($q, $section, $jobId);
        }
        if ($paymentId = ($f['payment'] ?? '')) {
            $this->paymentScope($q, $section, $paymentId);
        }

        return $q->orderByDesc('id');
    }

    private function jobScope(Builder $q, string $section, string $id): void
    {
        $job = MlJob::select(['id', 'customer_id', 'charge_reference', 'refund_reference'])->findOrFail($id);
        $apis = ApiJob::where('ml_job_id', $id)->where('customer_id', $job->customer_id)->select('id');
        if (! in_array($section, ['jobs', 'api', 'ledger', 'reservations', 'files', 'audit'], true)) {
            $q->whereRaw('1 = 0');

            return;
        }
        if ($section !== 'audit') {
            $q->where('customer_id', $job->customer_id);
        }
        match ($section) {
            'jobs' => $q->where('id', $id), 'api' => $q->where('ml_job_id', $id),
            'reservations' => $q->whereIn('api_job_id', $apis),
            'files' => $q->where(fn ($q) => $q->where(fn ($q) => $q->where('source_type', 'ml_job')->where('source_id', $id))
                ->orWhere(fn ($q) => $q->where('source_type', 'api_job')->whereIn('source_id', $apis))->orWhere('meta->job_id', $id)),
            'ledger' => $q->where(fn ($q) => $q->where('ml_job_id', $id)->orWhereIn('api_job_id', $apis)
                ->orWhere(fn ($q) => $q->where('source_type', 'ml_job')->where('source_id', $id))
                ->when($job->charge_reference, fn ($q) => $q->orWhere('reference_code', $job->charge_reference))
                ->when($job->refund_reference, fn ($q) => $q->orWhere('reference_code', $job->refund_reference))),
            'audit' => $q->where('target_type', MlJob::class)->where('target_id', $id),
        };
    }

    private function paymentScope(Builder $q, string $section, string $id): void
    {
        $p = Payment::select(['id', 'customer_id'])->findOrFail($id);
        if (! in_array($section, ['payments', 'orders', 'subscriptions', 'storage_subscriptions', 'ledger', 'audit'], true)) {
            $q->whereRaw('1 = 0');

            return;
        }
        if ($section !== 'audit') {
            $q->where('customer_id', $p->customer_id);
        }
        if ($section === 'payments') {
            $q->where('id', $id);
        } elseif ($section === 'audit') {
            $q->where('target_type', Payment::class)->where('target_id', $id);
        } elseif ($section === 'ledger') {
            $q->where(function ($q) use ($id) {
                foreach ([Payment::class => Payment::whereKey($id)->select('id'), CreditOrder::class => CreditOrder::where('payment_id', $id)->select('id'), CustomerServiceSubscription::class => CustomerServiceSubscription::where('payment_id', $id)->select('id'), CustomerStorageSubscription::class => CustomerStorageSubscription::where('payment_id', $id)->select('id')] as $type => $ids) {
                    $q->orWhere(fn ($q) => $q->where('related_type', $type)->whereIn('related_id', $ids));
                }
                $q->orWhere('meta->payment_id', $id);
            });
        } else {
            $q->where('payment_id', $id);
        }
    }

    private function auditCustomer(Builder $q, int $id): void
    {
        $q->where(function ($q) use ($id) {
            $q->whereIn('operation_id', AdminOperation::where('customer_id', $id)->select('id'))
                ->orWhere(fn ($q) => $q->where('target_type', Customer::class)->where('target_id', $id));
            foreach ([Payment::class, MlJob::class, CreditOrder::class, CustomerServiceSubscription::class, CustomerStorageSubscription::class] as $type) {
                $q->orWhere(fn ($q) => $q->where('target_type', $type)->whereIn('target_id', $type::where('customer_id', $id)->select('id')));
            }
        });
    }

    public function row(Model $m): array
    {
        AdminAccess::authorize('admin.read');
        $row = match (true) {
            $m instanceof MlJob => $this->job($m),
            $m instanceof ApiJob => $this->fields($m, ['id', 'customer_id', 'ml_job_id', 'tool_code', 'tool_action', 'status', 'created_at', 'completed_at', 'storage_mode'])
                + ['idempotency_present' => (bool) $m->idempotency_hash, 'expires_at' => data_get($m->meta, 'expires_at')],
            $m instanceof ApiCreditReservation => $this->reservation($m),
            $m instanceof Payment => $this->payment($m),
            $m instanceof CustomerFile => $this->file($m),
            $m instanceof CreditLedger => $this->ledger($m),
            $m instanceof CustomerApiKey => $this->fields($m, ['id', 'customer_id', 'name', 'key_prefix', 'status', 'scopes', 'created_at', 'last_used_at', 'revoked_at']),
            $m instanceof CustomerServiceSubscription, $m instanceof CustomerStorageSubscription => $this->subscription($m),
            $m instanceof CreditOrder => $this->fields($m, ['id', 'customer_id', 'payment_id', 'order_type', 'source_type', 'status', 'credits_amount', 'service_plan_id', 'credit_product_id', 'paid_at', 'created_at']) + ['revenue_excluded' => $m->isRevenueExcluded()],
            $m instanceof AdminAuditEvent => $this->audit($m),
            $m instanceof PlanEntitlement => $this->fields($m, ['id', 'service_plan_id', 'entitlement_channel', 'allowed']) + ['tool_action' => $m->toolAction?->full_code],
            default => [],
        };

        return $this->safe($row);
    }

    private function job(MlJob $j): array
    {
        $api = ApiJob::where('customer_id', $j->customer_id)->where('ml_job_id', $j->id)->first(['id', 'status']);
        $action = $j->toolAction?->full_code;
        $variant = collect(app(ApiCatalog::class)->variants())->firstWhere('action', $action);
        $files = $this->query('files', ['job' => $j->id]);
        $resultCount = (clone $files)->whereIn('purpose', ['render', 'transcription', 'caption'])->where('status', 'active')->whereNull('deleted_at')->count();
        $row = $this->fields($j, ['id', 'customer_id', 'status', 'created_at', 'started_at', 'finished_at', 'failure_stage'])
            + ['customer' => $j->customer?->username, 'family' => $variant['service'] ?? 'legacy', 'model' => $variant['tool']['name'] ?? $j->model_key, 'model_key' => $variant['slug'] ?? $j->model_key,
                'tool_action' => $action, 'channel' => $api ? 'api' : 'app', 'api_job_id' => $api?->id,
                'provider' => $j->provider, 'provider_job_id' => $j->provider_job_id, 'endpoint_key' => $j->endpoint_key,
                'provider_status' => ($j->recorded_provider_success ?? (data_get($j->output, 'provider_success') === true)) ? 'provider_success_recorded' : 'not_recorded',
                'persisted_result' => $j->status === 'done' && ($resultCount > 0 || ($j->recorded_inline_result ?? filled(data_get($j->output, 'text')))) ? 'persisted' : 'not_confirmed',
                'file_count' => $files->count(), 'available_result_files' => $resultCount,
                'charged' => $api ? null : $j->credits_charged, 'charge_reference' => $api ? null : $j->charge_reference,
                'refund_reference' => $api ? null : $j->refund_reference, 'refunded_at' => $api ? null : $j->refunded_at,
                'refund_amount' => $api ? null : CreditLedger::where('customer_id', $j->customer_id)->where('wallet_type', 'app')->where('credits_delta', '>', 0)
                    ->where(function ($q) use ($j) {
                        $q->where('ml_job_id', $j->id)->where('type', 'like', '%refund%');
                        if ($j->refund_reference) {
                            $q->orWhere('reference_code', $j->refund_reference);
                        }
                    })->sum('amount')];
        if ($api) {
            // ApiCreditReservation has a unique API job identity. This reads without settling/releasing.
            $reservation = ApiCreditReservation::where('customer_id', $j->customer_id)->where('api_job_id', $api->id)->first();
            $row['api_reservation'] = $reservation ? $this->reservation($reservation) : ['status' => 'not_recorded'];
            foreach (['charged', 'charge_reference', 'refund_reference', 'refunded_at', 'refund_amount'] as $field) {
                unset($row[$field]);
            }
        }
        $row['local_lifecycle'] = $row['status'];
        $row['age'] = $j->created_at?->diffForHumans();
        $row['attention'] = in_array($j->failure_stage, ['refund_pending', 'provider_submission_unknown'], true)
            || in_array($j->status, ['failed', 'delete_failed'], true)
            || ($j->status === 'done' && $row['persisted_result'] === 'not_confirmed')
            || (in_array($j->status, ['queued', 'running', 'saving'], true) && ($row['provider_status'] === 'provider_success_recorded' || $j->created_at?->lt(now()->subHours(2))))
            || (isset($reservation) && $reservation->status === 'reserved' && ($reservation->created_at?->lt(now()->subHours(2)) || in_array($api?->status, ['completed', 'failed', 'cancelled'], true)));
        unset($row['status']);

        return $row;
    }

    private function reservation(ApiCreditReservation $r): array
    {
        $final = $r->status === 'settled' ? (int) data_get($r->meta, 'final_amount', $r->amount) : 0;

        return $this->fields($r, ['id', 'customer_id', 'api_job_id', 'status', 'created_at', 'settled_at', 'released_at'])
            + ['wallet_type' => 'api', 'wallet_id' => CreditWallet::where('customer_id', $r->customer_id)->where('wallet_type', 'api')->value('id'), 'reserved_at' => $r->created_at,
                'reserved_amount' => $r->amount, 'held_amount' => $r->status === 'reserved' ? $r->amount : 0,
                'settled_amount' => $final, 'released_amount' => $r->status === 'released' ? $r->amount : ($r->status === 'settled' ? max(0, $r->amount - $final) : 0),
                'reference_code' => data_get($r->meta, 'reference_code')];
    }

    private function payment(Payment $p): array
    {
        $row = $this->fields($p, ['id', 'uuid', 'customer_id', 'provider', 'status', 'internal_status', 'amount', 'currency', 'purchase_type', 'purchasable_type', 'purchasable_id', 'local_reference', 'created_at', 'paid_at', 'fulfilled_at', 'review_required_at']);
        $row['product'] = data_get($p->purchase_snapshot, 'name');
        $row['fulfillment'] = $p->fulfilled_at ? 'fulfilled' : 'not_recorded';
        if ($this->deepEvidence()) {
            $row['provider_reference'] = $p->providerReference();
            $row['provider_status'] = $p->provider_subscription_status ?: $p->provider_status;
            $row['provider_evidence'] = AdminData::diagnostics($p->status_response);
            $row['provider_cancellation'] = array_intersect_key((array) data_get($p->meta, 'provider_cancellation', []), array_flip([
                'state', 'reason_code', 'requested_at', 'provider_cancel_requested_at', 'provider_cancel_confirmed_at',
                'provider_cancel_pending', 'effective_access_until', 'replacement_subscription_id', 'replacement_payment_id', 'retry_after',
            ]));
        }

        return $row;
    }

    private function subscription(Model $s): array
    {
        return $this->fields($s, ['id', 'customer_id', 'payment_id', 'status', 'source', 'starts_at', 'ends_at', 'cycle_started_on', 'cycle_ends_on', 'next_renewal_on', 'auto_renew', 'renewal_strategy'])
            + ['plan' => $s instanceof CustomerServiceSubscription ? $s->servicePlan?->name : $s->storagePlan?->name,
                'access_status' => $s->status === 'active' && $s->ends_at?->isPast() ? 'expired' : $s->status,
                'provider_status' => data_get($s->meta, 'provider_status'),
                'renewal_status' => data_get($s->meta, 'provider_cancellation.state', $s->auto_renew ? 'renewing' : 'off'),
                'provider_cancel_pending' => (bool) data_get($s->meta, 'provider_cancellation.provider_cancel_pending'),
                'effective_access_until' => data_get($s->meta, 'provider_cancellation.effective_access_until', data_get($s->meta, 'period_ends_at')),
                'superseded_at' => data_get($s->meta, 'superseded_at'),
                'replacement_subscription_id' => data_get($s->meta, 'provider_cancellation.replacement_subscription_id'),
                'expired_at' => data_get($s->meta, 'expired_at'),
                'renewal_payment_missing' => (bool) data_get($s->meta, 'renewal_payment_missing'),
                'previous_plan' => $s instanceof CustomerServiceSubscription ? $s->previousServicePlan?->name : null];
    }

    private function file(CustomerFile $f): array
    {
        $apiResult = ApiResultFile::where('customer_id', $f->customer_id)->where('storage_file_id', $f->id)->first(['id', 'result_kind', 'deleted_at']);

        return $this->fields($f, ['id', 'customer_id', 'purpose', 'mime', 'size_bytes', 'status', 'retention_mode', 'expires_at', 'counts_toward_quota', 'source_type', 'source_id'])
            + ['filename' => data_get($f->meta, 'original_name') ?: basename((string) $f->path),
                'object_present' => 'not_checked', 'api_result_id' => $apiResult?->id, 'result_kind' => $apiResult?->result_kind, 'deleted_at' => $f->deleted_at,
                'api_result_deleted_at' => $apiResult?->deleted_at,
                'available_by_metadata' => $f->status === 'active' && ! $f->deleted_at && (! $f->expires_at || $f->expires_at->isFuture()) && ! $apiResult?->deleted_at];
    }

    private function ledger(CreditLedger $l): array
    {
        $paymentId = null;
        if ($l->related_type === Payment::class) {
            $paymentId = Payment::where('customer_id', $l->customer_id)->whereKey($l->related_id)->value('id');
        } elseif (in_array($l->related_type, [CreditOrder::class, CustomerServiceSubscription::class, CustomerStorageSubscription::class], true)) {
            $paymentId = $l->related_type::where('customer_id', $l->customer_id)->whereKey($l->related_id)->value('payment_id');
        }

        return $this->fields($l, ['id', 'customer_id', 'created_at', 'wallet_type', 'type', 'direction', 'amount', 'credits_delta', 'balance_after', 'bucket', 'reference_code', 'source_type', 'source_id', 'related_type', 'related_id', 'ml_job_id', 'api_job_id']) + ['payment_id' => $paymentId];
    }

    private function audit(AdminAuditEvent $e): array
    {
        $operation = $e->operation_id ? AdminOperation::find($e->operation_id) : null;
        $keys = ['status', 'is_active', 'customer_id', 'payment_id', 'service_plan_id', 'storage_plan_id', 'credits_amount', 'balance_credits', 'subscription_balance_credits', 'addon_balance_credits'];

        return $this->fields($e, ['id', 'created_at', 'admin_id', 'action', 'target_type', 'target_id', 'reason', 'operation_id'])
            + ['outcome' => str_ends_with($e->action, '.failed') ? 'failed' : ($e->target_type === AdminOperation::class ? 'completed' : 'not_recorded'),
                'operation_status' => $operation?->status, 'before_state' => array_intersect_key($e->before_state ?? [], array_flip($keys)),
                'after_state' => array_intersect_key($e->after_state ?? [], array_flip($keys))];
    }

    private function deepEvidence(): bool
    {
        $admin = AdminAccess::authorize('admin.read');

        return Gate::forUser($admin)->allows('admin.finance') || Gate::forUser($admin)->allows('admin.reconcile');
    }

    private function fields(Model $m, array $keys): array
    {
        $row = [];
        foreach ($keys as $key) {
            $row[$key] = $m->getAttribute($key);
        }

        return $row;
    }

    private function safe(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $clean = AdminData::redact($this->safe($item), (string) $key);
                $result[$key] = $clean === AdminData::REDACTED ? null : $clean;
            }

            return $result;
        }
        $value = AdminData::redact($value);

        return $value === AdminData::REDACTED ? null : (is_string($value) ? mb_substr($value, 0, 500) : $value);
    }
}
