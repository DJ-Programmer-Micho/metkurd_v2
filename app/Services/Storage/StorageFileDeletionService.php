<?php

namespace App\Services\Storage;

use App\Models\ApiResultFile;
use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorageFileDeletionService
{
    public function delete(CustomerFile $file, string $reason = 'customer_deleted'): CustomerFile
    {
        return DB::transaction(function () use ($file, $reason): CustomerFile {
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

                return $fresh;
            }

            $disk = (string) ($fresh->disk ?? 's3');
            $path = (string) ($fresh->path ?? '');

            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
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

            return $fresh;
        }, 3);
    }
}
