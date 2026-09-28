<?php

namespace App\Support\Admin;

use App\Models\ApiCreditReservation;
use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\CustomerFile;
use App\Models\CustomerMcpConnection;
use App\Models\MlJob;
use App\Services\Admin\AdminOperations;
use App\Services\CustomerApi\V2\ApiCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Persisted, allowlisted metadata only. Never invoke customer serializers or recovery. */
final class AdminDeveloperWorkspace
{
    public const SECTIONS = ['jobs', 'api', 'keys', 'mcp', 'reservations', 'files'];

    public static function mcp(Builder $q, bool $isMcp = true): void
    {
        $isMcp
            ? $q->whereNotNull('meta->mcp_connection_id')->where('meta->mcp_connection_id', '!=', '')
            : $q->where(fn ($q) => $q->whereNull('meta->mcp_connection_id')->orWhere('meta->mcp_connection_id', ''));
    }

    private function apiProjection(): Builder
    {
        return ApiJob::select(['id', 'customer_id', 'api_key_id', 'ml_job_id', 'tool_action', 'status', 'storage_mode', 'created_at', 'updated_at', 'completed_at'])
            ->selectRaw("JSON_EXTRACT(meta, '$.mcp_connection_id') as origin_connection");
    }

    private function reservationProjection(): Builder
    {
        return ApiCreditReservation::select(['id', 'customer_id', 'api_job_id', 'amount', 'status', 'created_at', 'settled_at', 'released_at'])
            ->selectRaw("JSON_EXTRACT(meta, '$.final_amount') as recorded_final_amount");
    }

    public function query(string $section, array $f): Builder
    {
        AdminAccess::authorize('admin.read');
        abort_unless(in_array($section, [...self::SECTIONS, 'audit'], true), 404);
        $customer = (int) ($f['customer'] ?? 0);
        // Trace identities must agree before any section is read, including empty sections.
        foreach (['job' => MlJob::class, 'apiJob' => ApiJob::class, 'keyId' => CustomerApiKey::class, 'connectionId' => CustomerMcpConnection::class] as $key => $type) {
            if (! empty($f[$key])) {
                $record = $type::select(['id', 'customer_id'])->when($customer, fn ($q) => $q->where('customer_id', $customer))->findOrFail($f[$key]);
                $customer = (int) $record->customer_id;
            }
        }
        $f['customer'] = $customer;
        $base = $f;
        unset($base['channel']);
        if ($section === 'files') {
            unset($base['job']);
        }
        if (in_array($section, ['keys', 'mcp'], true)) {
            unset($base['status']);
        }
        if ($section === 'jobs') {
            $base['status'] = ['processing' => '', 'completed' => 'done'][$f['status'] ?? ''] ?? ($f['status'] ?? '');
            if (($base['status'] ?? '') === 'review') {
                $base['status'] = '';
                $base['group'] = 'attention';
            }
        }
        if ($section === 'mcp') {
            $q = CustomerMcpConnection::select(['id', 'customer_id', 'client_id', 'name', 'scopes', 'status', 'created_at', 'last_used_at', 'revoked_at']);
            if ($customer) {
                $q->where('customer_id', $customer);
            }
            if (! empty($f['search'])) {
                $q->where(fn ($q) => $q->where('name', 'like', '%'.mb_substr($f['search'], 0, 100).'%')->orWhere('id', $f['search']));
            }
            foreach (['from' => '>=', 'until' => '<='] as $key => $op) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$key] ?? '')) {
                    $q->whereDate('created_at', $op, $f[$key]);
                }
            }
            $q->orderByDesc('id');
        } else {
            $q = app(AdminOperations::class)->query($section, $base);
        }
        if ($section === 'jobs' && ($f['status'] ?? '') === 'processing') {
            $q->whereIn('status', ['running', 'saving']);
        }
        if (in_array($section, ['keys', 'mcp'], true) && ! empty($f['status'])) {
            if ($f['status'] === 'revoked') {
                $q->where(fn ($q) => $q->where('status', 'revoked')->orWhereNotNull('revoked_at'));
            } else {
                $q->where('status', mb_substr($f['status'], 0, 60))->whereNull('revoked_at');
            }
        }
        if ($section === 'api') {
            $q->select($this->apiProjection()->getQuery()->columns);
        }
        if ($section === 'reservations') {
            $q->select($this->reservationProjection()->getQuery()->columns);
        }
        if ($section === 'files') {
            $q->select(['id', 'customer_id', 'purpose', 'mime', 'size_bytes', 'created_at', 'expires_at', 'deleted_at', 'status', 'retention_mode', 'source_type', 'source_id'])
                ->selectRaw("JSON_EXTRACT(meta, '$.job_id') as recorded_job_id");
        }
        if ($section === 'jobs') {
            $q->addSelect('updated_at');
        }

        $apis = ApiJob::select('id')->when($customer, fn ($q) => $q->where('customer_id', $customer));
        if (! empty($f['apiJob'])) {
            $apis->whereKey($f['apiJob']);
        }
        if (! empty($f['keyId'])) {
            $apis->where('api_key_id', $f['keyId']);
        }
        if (! empty($f['connectionId'])) {
            $apis->where('meta->mcp_connection_id', $f['connectionId']);
        }
        if (! empty($f['job'])) {
            $apis->where('ml_job_id', $f['job']);
        }
        if (! empty($f['service'])) {
            $apis->where('tool_action', $f['service']);
        }
        $channel = $f['channel'] ?? '';
        if (in_array($channel, ['api', 'mcp'], true)) {
            self::mcp($apis, $channel === 'mcp');
        }
        $trace = ! empty($f['apiJob']) || ! empty($f['keyId']) || ! empty($f['connectionId']);
        if ($section === 'audit' && $trace) {
            $q->where(function ($q) use ($apis, $customer, $f) {
                $q->where(fn ($q) => $q->where('target_type', ApiJob::class)->whereIn('target_id', (clone $apis)->select('id')))
                    ->orWhere(fn ($q) => $q->where('target_type', MlJob::class)->whereIn('target_id', MlJob::where('customer_id', $customer)->select('id')->whereIn('id', (clone $apis)->select('ml_job_id'))))
                    ->orWhere(fn ($q) => $q->where('target_type', ApiCreditReservation::class)->whereIn('target_id', ApiCreditReservation::where('customer_id', $customer)->select('id')->whereIn('api_job_id', $apis)));
                foreach (['keyId' => CustomerApiKey::class, 'connectionId' => CustomerMcpConnection::class] as $key => $type) {
                    if (! empty($f[$key])) {
                        $q->orWhere(fn ($q) => $q->where('target_type', $type)->where('target_id', $f[$key]));
                    }
                }
            });
        }
        if ($section === 'jobs' && ($trace || in_array($channel, ['app', 'api', 'mcp'], true))) {
            $owned = (clone $apis)->select('ml_job_id')->whereNotNull('ml_job_id')->whereColumn('customer_id', 'ml_jobs.customer_id');
            if ($channel === 'app') {
                if ($trace) {
                    $q->whereRaw('1 = 0');
                }
                $q->whereNotIn('id', $owned)->where(fn ($q) => $q->whereNull('input->wallet_type')->orWhere('input->wallet_type', '!=', 'api'));
            } elseif ($channel === 'api' && ! $trace) {
                $q->where(fn ($q) => $q->whereIn('id', $owned)->orWhere(fn ($q) => $q->where('input->wallet_type', 'api')
                    ->whereNotIn('id', ApiJob::select('ml_job_id')->whereNotNull('ml_job_id')->whereColumn('customer_id', 'ml_jobs.customer_id'))));
            } else {
                $q->whereIn('id', $owned);
            }
        } elseif ($section === 'api') {
            $q->whereIn('id', $apis);
            if ($channel === 'app') {
                $q->whereRaw('1 = 0');
            }
        } elseif ($section === 'reservations' && ($trace || ! empty($f['service']) || in_array($channel, ['app', 'api', 'mcp'], true))) {
            $q->whereIn('api_job_id', (clone $apis)->whereColumn('customer_id', 'api_credit_reservations.customer_id'));
            if ($channel === 'app') {
                $q->whereRaw('1 = 0');
            }
        } elseif ($section === 'files' && ($trace || ! empty($f['job']))) {
            $ml = (clone $apis)->select('ml_job_id')->whereNotNull('ml_job_id');
            // App jobs have no ApiJob; retain their direct file associations too.
            $ml = MlJob::select('id')->where('customer_id', $customer)->where(function ($q) use ($ml, $f, $trace) {
                $q->whereIn('id', $ml);
                if (! $trace && ! empty($f['job'])) {
                    $q->orWhere('id', $f['job']);
                }
            });
            $q->where(fn ($q) => $q->where(fn ($q) => $q->where('source_type', 'api_job')->whereIn('source_id', $apis))
                ->orWhere(fn ($q) => $q->where('source_type', 'ml_job')->whereIn('source_id', $ml))
                ->orWhereIn('meta->job_id', $ml)
                ->orWhereIn('id', ApiResultFile::select('storage_file_id')->where('customer_id', $customer)->whereIn('api_job_id', $apis)));
        } elseif ($section === 'keys' && $trace) {
            if (! empty($f['keyId'])) {
                $q->whereKey($f['keyId']);
            }
            if (! empty($f['apiJob']) || ! empty($f['connectionId'])) {
                $q->whereIn('id', (clone $apis)->select('api_key_id'));
            }
        } elseif ($section === 'mcp') {
            if (! empty($f['connectionId'])) {
                $q->whereKey($f['connectionId']);
            } elseif ($trace || ! empty($f['job'])) {
                $q->whereExists((clone $apis)->selectRaw('1')->whereColumn('meta->mcp_connection_id', 'customer_mcp_connections.id'));
            }
        }

        return $q;
    }

    public function summary(array $filters): array
    {
        AdminAccess::authorize('admin.read');
        // Overview counts deliberately use customer/date scope, not current table status/search.
        $scope = array_intersect_key($filters, array_flip(['customer', 'from', 'until']));
        $api = $this->query('api', $scope);
        $mcp = clone $api;
        self::mcp($mcp);

        return [
            'active_jobs' => $this->query('jobs', $scope + ['group' => 'active'])->count(),
            'active_api' => (clone $api)->whereIn('status', ['accepted', 'queued', 'processing'])->count(),
            'mcp_jobs' => $mcp->count(),
            'review_jobs' => $this->query('jobs', $scope + ['group' => 'attention'])->count(),
            'active_keys' => $this->query('keys', $scope)->where('status', 'active')->whereNull('revoked_at')->count(),
            'active_connections' => $this->query('mcp', $scope)->where('status', 'active')->whereNull('revoked_at')->count(),
            'reserved' => $this->query('reservations', $scope)->where('status', 'reserved')->count(),
            'settled' => $this->query('reservations', $scope)->where('status', 'settled')->count(),
            'released' => $this->query('reservations', $scope)->where('status', 'released')->count(),
        ];
    }

    public function rows(Collection $models): Collection
    {
        AdminAccess::authorize('admin.read');
        if ($models->isEmpty()) {
            return collect();
        }
        $customers = $models->pluck('customer_id')->unique();
        $names = Customer::whereIn('id', $customers)->pluck('username', 'id');
        $fileLinks = ApiResultFile::select(['id', 'customer_id', 'storage_file_id', 'api_job_id', 'result_kind', 'deleted_at'])
            ->whereIn('id', ApiResultFile::selectRaw('MIN(id)')->whereIn('customer_id', $customers)
                ->whereIn('storage_file_id', $models->filter(fn ($m) => $m instanceof CustomerFile)->pluck('id'))->groupBy('customer_id', 'storage_file_id'))->get()->keyBy('storage_file_id');
        $mlIds = $models->filter(fn ($m) => $m instanceof MlJob)->pluck('id')
            ->merge($models->pluck('ml_job_id'))->merge($models->filter(fn ($m) => $m instanceof CustomerFile && $m->source_type === 'ml_job')->pluck('source_id'))
            ->merge($models->pluck('recorded_job_id')->map(fn ($v) => $this->scalar($v)))->filter()->unique();
        $apiIds = $models->filter(fn ($m) => $m instanceof ApiJob)->pluck('id')->merge($models->pluck('api_job_id'))
            ->merge($fileLinks->pluck('api_job_id'))
            ->merge($models->filter(fn ($m) => $m instanceof CustomerFile && $m->source_type === 'api_job')->pluck('source_id'))->filter()->unique();
        $apis = $this->apiProjection()->whereIn('customer_id', $customers)->where(fn ($q) => $q->whereIn('id', $apiIds)->orWhereIn('ml_job_id', $mlIds))->get();
        $mlIds = $mlIds->merge($apis->pluck('ml_job_id'))->filter()->unique();
        $jobs = app(AdminOperations::class)->query('jobs')->whereIn('customer_id', $customers)->whereIn('id', $mlIds)->addSelect('updated_at')->get()->keyBy('id');
        $reservations = $this->reservationProjection()->whereIn('customer_id', $customers)->whereIn('api_job_id', $apis->pluck('id'))->get()->keyBy('api_job_id');
        $keys = CustomerApiKey::whereIn('customer_id', $customers)->whereIn('id', $apis->pluck('api_key_id'))->pluck('customer_id', 'id');
        $connections = CustomerMcpConnection::whereIn('customer_id', $customers)->whereIn('id', $apis->pluck('origin_connection')->map(fn ($v) => $this->scalar($v)))->pluck('customer_id', 'id');
        $catalog = collect(app(ApiCatalog::class)->variants())->keyBy('action');
        // Grouped counts, not result bodies or one query per displayed job.
        $resultLinks = ApiResultFile::whereIn('customer_id', $customers)->whereIn('api_job_id', $apis->pluck('id'))->whereNull('deleted_at')
            ->select(['customer_id', 'storage_file_id'])->selectRaw('MIN(api_job_id) as linked_api_job')->groupBy('customer_id', 'storage_file_id');
        $counts = CustomerFile::leftJoinSub($resultLinks, 'result_links', fn ($join) => $join->on('result_links.storage_file_id', '=', 'customer_files.id')->on('result_links.customer_id', '=', 'customer_files.customer_id'))
            ->whereIn('customer_files.customer_id', $customers)->whereIn('purpose', ['render', 'transcription', 'caption'])->where('status', 'active')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereIn('source_id', $mlIds->merge($apis->pluck('id')))->orWhereIn('meta->job_id', $mlIds)->orWhereNotNull('linked_api_job'))
            ->select(['customer_files.customer_id', 'source_type', 'source_id', 'linked_api_job'])->selectRaw("JSON_EXTRACT(meta, '$.job_id') as recorded_job_id, COUNT(*) as aggregate")
            ->groupBy('customer_files.customer_id', 'source_type', 'source_id', 'recorded_job_id', 'linked_api_job')->get();

        return $models->map(function ($m) use ($names, $apis, $jobs, $reservations, $keys, $connections, $catalog, $counts, $fileLinks) {
            $row = ['id' => $m->id, 'customer_id' => $m->customer_id, 'customer' => $names[$m->customer_id] ?? null];
            foreach (['created_at', 'updated_at', 'status'] as $key) {
                $row[$key] = $m->$key;
            }
            $link = $m instanceof CustomerFile ? $fileLinks->get($m->id) : null;
            if ($link && $link->customer_id !== $m->customer_id) {
                $link = null;
            }
            $api = $m instanceof ApiJob ? $m : $apis->first(fn ($a) => $a->customer_id === $m->customer_id &&
                (($m instanceof MlJob && $a->ml_job_id === $m->id) || ($m instanceof ApiCreditReservation && $a->id === $m->api_job_id) ||
                ($m instanceof CustomerFile && (($link && $link->api_job_id === $a->id) || ($m->source_type === 'api_job' && $m->source_id === $a->id) || ($m->source_type === 'ml_job' && $m->source_id === $a->ml_job_id) || ($a->ml_job_id && $this->scalar($m->recorded_job_id) === $a->ml_job_id)))));
            $jobId = $m instanceof MlJob ? $m->id : ($api?->ml_job_id ?? ($m instanceof CustomerFile ? ($m->source_type === 'ml_job' ? $m->source_id : $this->scalar($m->recorded_job_id)) : null));
            $job = $jobs->get($jobId);
            if ($job && $job->customer_id !== $m->customer_id) {
                $job = null;
            }
            $connection = $this->scalar($api?->origin_connection);
            $connection = is_string($connection) ? $connection : null;
            $row += ['channel' => $api ? ($connection ? 'mcp' : 'api') : ($job ? ($job->recorded_api_wallet ? 'api' : 'app') : null),
                'api_job_id' => $api?->id, 'ml_job_id' => $job?->id,
                'key_id' => $api && ($keys[$api->api_key_id] ?? null) === $m->customer_id ? $api->api_key_id : null,
                'connection_id' => ($connections[$connection] ?? null) === $m->customer_id ? $connection : null];
            if ($m instanceof CustomerApiKey || $m instanceof CustomerMcpConnection) {
                foreach (['name', 'scopes', 'last_used_at', 'revoked_at'] as $key) {
                    $row[$key] = $m->$key;
                }
                $row[$m instanceof CustomerApiKey ? 'key_prefix' : 'client_identity'] = $m instanceof CustomerApiKey ? $m->key_prefix : $m->client_id;
                $row[$m instanceof CustomerApiKey ? 'key_id' : 'connection_id'] = $m->id;
                if ($m->revoked_at) {
                    $row['status'] = 'revoked';
                }
            } elseif ($m instanceof CustomerFile) {
                foreach (['purpose', 'mime', 'size_bytes', 'expires_at', 'deleted_at', 'retention_mode'] as $key) {
                    $row[$key] = $m->$key;
                }
                $row['object_present'] = 'not_checked';
                $row['storage_mode'] = $api?->storage_mode;
                $row['api_result_id'] = $link?->id;
                $row['result_kind'] = $link?->result_kind;
                $row['api_result_deleted_at'] = $link?->deleted_at;
            } else {
                $action = $job?->toolAction?->full_code ?? $api?->tool_action;
                $row += ['tool_action' => $action, 'product' => $catalog[$action]['tool']['name'] ?? $action,
                    'local_lifecycle' => $job?->status ?? 'not_recorded', 'finished_at' => $job?->finished_at,
                    'api_status' => $api?->status, 'api_completed_at' => $api?->completed_at, 'storage_mode' => $api?->storage_mode];
                $r = $m instanceof ApiCreditReservation ? $m : $reservations->get($api?->id);
                if ($r && $r->customer_id !== $m->customer_id) {
                    $r = null;
                }
                $row['reservation_id'] = $r?->id;
                $row['reservation_state'] = $r?->status ?? 'not_recorded';
                $row['wallet_type'] = $api || $r || $job?->recorded_api_wallet ? 'api' : 'app';
                if ($row['wallet_type'] === 'app') {
                    $row['app_charged'] = $job?->credits_charged;
                }
                if ($r) {
                    $final = $this->scalar($r->recorded_final_amount);
                    $row += ['reserved_amount' => $r->amount, 'held_amount' => $r->status === 'reserved' ? $r->amount : 0,
                        'settled_amount' => $r->status === 'settled' ? (int) ($final ?? $r->amount) : 0,
                        'released_amount' => $r->status === 'released' ? $r->amount : ($r->status === 'settled' ? max(0, $r->amount - (int) ($final ?? $r->amount)) : 0),
                        'settled_at' => $r->settled_at, 'released_at' => $r->released_at];
                }
                $row['result_count'] = $counts->filter(fn ($f) => $f->customer_id === $m->customer_id && (($job && ($this->scalar($f->recorded_job_id) === $job->id || ($f->source_type === 'ml_job' && $f->source_id === $job->id))) || ($api && (($f->source_type === 'api_job' && $f->source_id === $api->id) || $f->linked_api_job === $api->id))))->sum('aggregate');
                $problems = [];
                if ($job?->failure_stage === 'provider_submission_unknown') {
                    $problems[] = 'unknown_submission';
                }
                if ($job && in_array($job->status, ['running', 'saving']) && ! $job->provider_job_id) {
                    $problems[] = 'missing_remote';
                }
                if ($job && in_array($job->status, ['queued', 'running', 'saving']) && $job->created_at?->lt(now()->subHours(2))) {
                    $problems[] = 'stale';
                }
                if ($job && (($job->status === 'saving') || (in_array($job->status, ['queued', 'running', 'saving']) && $job->recorded_provider_success) || ($job->status === 'done' && ! $job->recorded_inline_result && ! $row['result_count']))) {
                    $problems[] = 'persistence';
                }
                if ($r?->status === 'reserved' && (! $api || in_array($api->status, ['completed', 'failed', 'cancelled']) || $r->created_at?->lt(now()->subHours(2)))) {
                    $problems[] = 'retained_reservation';
                }
                if ($api && ! $job) {
                    $problems[] = 'unlinked';
                }
                if ($job && in_array($job->failure_stage, ['refund_pending'], true)) {
                    $problems[] = 'refund_pending';
                }
                $row['problems'] = $problems;
            }

            return $this->safe($row);
        });
    }

    private function scalar(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function safe(mixed $value, string $field = ''): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->safe($item, (string) $key);
            }

            return $result;
        }
        // OAuth client identity is public metadata, rendered as text, never a request URL.
        if ($field === 'client_identity' && is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            $parts = parse_url($value);

            return ($parts['scheme'] ?? '') === 'https' && ! isset($parts['query']) && ! isset($parts['fragment']) && ! isset($parts['user']) && ! isset($parts['pass']) ? mb_substr($value, 0, 500) : null;
        }
        $clean = AdminData::redact($value);

        return $clean === AdminData::REDACTED ? null : (is_string($clean) ? mb_substr($clean, 0, 500) : $clean);
    }
}
