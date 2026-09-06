<?php

namespace App\Services\Admin;

use App\Models\AdminOperation;
use App\Models\Customer;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminData;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminOperationRunner
{
    public function run(string $id, string $capability, string $action, ?int $customerId, array $requested, string $reason, Closure $execute): array
    {
        $admin = AdminAccess::authorize($capability);
        Validator::make(['operation' => $id, 'reason' => $reason], [
            'operation' => 'required|uuid', 'reason' => 'required|string|min:10|max:1000',
        ])->validate();
        $requested = $this->canonical($requested);
        $hash = hash('sha256', json_encode([$admin->id, $customerId, $action, $requested, $reason], JSON_THROW_ON_ERROR));
        // Claim before the financial transaction: rollback retains the original intent for retry.
        $operation = AdminOperation::firstOrCreate(['id' => $id], [
            'admin_id' => $admin->id, 'customer_id' => $customerId, 'action' => $action,
            'payload_hash' => $hash, 'requested' => AdminData::redact($requested), 'reason' => AdminData::redact($reason),
        ]);
        if (! hash_equals($operation->payload_hash, $hash)) {
            throw ValidationException::withMessages(['operation' => __('admin_p0.operation_conflict')]);
        }

        $audit = app(AdminAudit::class);
        $previous = [$audit->operationId, $audit->reason];
        $audit->operationId = $id;
        $audit->reason = $reason;
        try {
            return DB::transaction(function () use ($operation, $customerId, $execute, $audit, $requested) {
                $locked = AdminOperation::query()->lockForUpdate()->findOrFail($operation->id);
                if ($locked->status === 'completed') {
                    return $locked->result;
                }
                $customer = $customerId ? Customer::query()->lockForUpdate()->findOrFail($customerId) : null;
                $result = $execute($customer);
                $locked->update(['status' => 'completed', 'result' => AdminData::redact($result), 'completed_at' => now()]);
                $audit->record($locked->action, AdminOperation::class, $locked->id, [], $requested, $result);

                return $result;
            });
        } catch (\Throwable $exception) {
            // Financial writes have rolled back. Keep the intent pending and store only a safe failure category.
            $audit->record($action.'.failed', AdminOperation::class, $id, [], $requested,
                ['status' => 'pending', 'failure_stage' => $exception instanceof ValidationException ? 'validation' : 'execution']);
            throw $exception;
        } finally {
            [$audit->operationId, $audit->reason] = $previous;
        }
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
