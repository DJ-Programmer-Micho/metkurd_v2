<?php

namespace App\Services\Storage;

use App\Models\ApiResultFile;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageFileDeletionService
{
    public function destructiveOperationsAllowed(): bool
    {
        return (bool) config('filesystems.customer_outputs.allow_destructive_operations', false);
    }

    public function assertDestructiveOperationsAllowed(): void
    {
        if ($this->destructiveOperationsAllowed()) {
            return;
        }

        throw new StorageDestructiveOperationBlocked(
            'Customer output deletion is disabled for this storage environment.'
        );
    }

    public function delete(CustomerFile $file, string $reason = 'customer_deleted'): CustomerFile
    {
        return $this->deleteWithOutcome($file, $reason)['file'];
    }

    /** @return array{file: CustomerFile, remote_missing: bool} */
    public function deleteWithOutcome(CustomerFile $file, string $reason = 'customer_deleted'): array
    {
        $this->assertDestructiveOperationsAllowed();

        $result = DB::transaction(function () use ($file, $reason): array {
            /** @var CustomerFile $fresh */
            $fresh = CustomerFile::query()->lockForUpdate()->findOrFail($file->id);
            $deletedAt = now();

            if ((string) $fresh->status === 'deleted') {
                if ($fresh->delete_reason === null && $reason !== '') {
                    $fresh->delete_reason = $reason;
                }

                if ($fresh->deleted_at === null) {
                    $fresh->deleted_at = $deletedAt;
                }

                $fresh->save();

                ApiResultFile::query()
                    ->where('storage_file_id', (int) $fresh->id)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => $deletedAt,
                        'updated_at' => $deletedAt,
                    ]);

                app(DeletedResultReconciliation::class)->reconcile($fresh);

                return ['file' => $fresh, 'remote_missing' => false];
            }

            $disk = (string) ($fresh->disk ?? 's3');
            $path = (string) ($fresh->path ?? '');

            try {
                $exists = $path !== '' && Storage::disk($disk)->exists($path);

                if ($exists && Storage::disk($disk)->delete($path) === false) {
                    throw new \RuntimeException('The remote object could not be deleted.');
                }
            } catch (\Throwable $exception) {
                Log::warning('CUSTOMER_STORAGE_DELETE_REMOTE_FAILED', [
                    'customer_id' => (int) $fresh->customer_id,
                    'customer_file_id' => (int) $fresh->id,
                    'disk' => $disk,
                    'operation' => 'delete',
                    'error_class' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $fresh->status = 'deleted';
            $fresh->deleted_at = $deletedAt;
            $fresh->delete_reason = $reason;
            $fresh->save();

            ApiResultFile::query()
                ->where('storage_file_id', (int) $fresh->id)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $deletedAt,
                    'updated_at' => $deletedAt,
                ]);

            if ((bool) ($fresh->counts_toward_quota ?? true) && (int) ($fresh->size_bytes ?? 0) > 0) {
                $usage = CustomerUsage::query()
                    ->where('customer_id', (int) $fresh->customer_id)
                    ->lockForUpdate()
                    ->first();

                if ($usage) {
                    $usage->storage_used_bytes = max(0, (int) $usage->storage_used_bytes - (int) $fresh->size_bytes);
                    $usage->save();
                }
            }

            app(DeletedResultReconciliation::class)->reconcile($fresh);

            return ['file' => $fresh, 'remote_missing' => ! $exists];
        }, 3);

        return $result;
    }

    /**
     * @param  array<int, int|string>  $fileIds
     * @return array{deleted: int, missing: int, failed: int, skipped: int}
     */
    public function deleteOwned(Customer $customer, array $fileIds, string $reason = 'customer_deleted'): array
    {
        $this->assertDestructiveOperationsAllowed();

        $ids = collect($fileIds)
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $files = CustomerFile::query()
            ->where('customer_id', (int) $customer->id)
            ->where('status', 'active')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $outcome = ['deleted' => 0, 'missing' => 0, 'failed' => 0, 'skipped' => max(0, $ids->count() - $files->count())];

        foreach ($files as $file) {
            try {
                $result = $this->deleteWithOutcome($file, $reason);
                $outcome['deleted']++;
                $outcome['missing'] += (int) $result['remote_missing'];
            } catch (\Throwable $exception) {
                $outcome['failed']++;
                Log::warning('CUSTOMER_STORAGE_BULK_DELETE_ITEM_FAILED', [
                    'customer_id' => (int) $customer->id,
                    'customer_file_id' => (int) $file->id,
                    'operation' => 'bulk_delete',
                    'error_class' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $outcome;
    }
}
