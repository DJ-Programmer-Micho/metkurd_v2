<?php

namespace App\Services\Storage;

use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CustomerOutputStorage
{
    public function saveWavB64ToS3(int $customerId, string $path, string $wavB64, array $meta = []): array
    {
        $bin = base64_decode($wavB64, true);

        if ($bin === false || $bin === '') {
            throw new \RuntimeException('Invalid wav_b64.');
        }

        $disk = 's3';

        Storage::disk($disk)->put($path, $bin, [
            'visibility' => 'private',
            'ContentType' => 'audio/wav',
        ]);

        $bytes = strlen($bin);

        DB::transaction(function () use ($customerId, $disk, $path, $bytes, $meta) {
            CustomerFile::create([
                'customer_id' => $customerId,
                'purpose' => 'render',
                'tool_code' => $meta['tool'] ?? 'tts',
                'disk' => $disk,
                'path' => $path,
                'size_bytes' => $bytes,
                'mime' => 'audio/wav',
                'checksum' => null,
                'status' => 'active',
                'meta' => $meta,
            ]);

            $usage = CustomerUsage::query()->lockForUpdate()->firstOrCreate(
                ['customer_id' => $customerId],
                [
                    'storage_used_bytes' => 0,
                    'jobs_total' => 0,
                    'jobs_succeeded' => 0,
                    'jobs_failed' => 0,
                ]
            );

            $usage->storage_used_bytes = (int) $usage->storage_used_bytes + $bytes;
            $usage->save();
        }, 3);

        return [
            'disk' => $disk,
            'path' => $path,
            'bytes' => $bytes,
        ];
    }

    public function deleteFromS3AndUncount(int $customerId, string $path, int $bytes): void
    {
        $disk = 's3';

        Storage::disk($disk)->delete($path);

        DB::transaction(function () use ($customerId, $disk, $path, $bytes) {
            CustomerFile::query()
                ->where('customer_id', $customerId)
                ->where('disk', $disk)
                ->where('path', $path)
                ->update([
                    'status' => 'deleted',
                    'deleted_at' => now(),
                ]);

            $usage = CustomerUsage::query()
                ->lockForUpdate()
                ->where('customer_id', $customerId)
                ->first();

            if ($usage && $bytes > 0) {
                $usage->storage_used_bytes = max(0, (int) $usage->storage_used_bytes - $bytes);
                $usage->save();
            }
        }, 3);
    }
}