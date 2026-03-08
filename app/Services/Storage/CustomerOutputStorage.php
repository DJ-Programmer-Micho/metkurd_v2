<?php

namespace App\Services\Storage;

use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use Illuminate\Http\UploadedFile;
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
                'purpose' => $meta['purpose'] ?? 'render',
                'tool_code' => $meta['tool'] ?? 'tts',
                'disk' => $disk,
                'path' => $path,
                'size_bytes' => $bytes,
                'mime' => $meta['mime'] ?? 'audio/wav',
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
            'mime' => $meta['mime'] ?? 'audio/wav',
        ];
    }

    public function saveUploadedFileToS3(
        int $customerId,
        UploadedFile $file,
        string $path,
        array $meta = []
    ): array {
        if (!$file->isValid()) {
            throw new \RuntimeException('Uploaded file is not valid.');
        }

        $disk = 's3';
        $stream = fopen($file->getRealPath(), 'r');

        if (!$stream) {
            throw new \RuntimeException('Unable to open uploaded file stream.');
        }

        $mime = $file->getMimeType() ?: 'audio/wav';

        Storage::disk($disk)->put($path, $stream, [
            'visibility' => 'private',
            'ContentType' => $mime,
        ]);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $bytes = (int) $file->getSize();

        DB::transaction(function () use ($customerId, $disk, $path, $bytes, $mime, $meta) {
            CustomerFile::create([
                'customer_id' => $customerId,
                'purpose' => $meta['purpose'] ?? 'reference',
                'tool_code' => $meta['tool'] ?? 'clone_tts',
                'disk' => $disk,
                'path' => $path,
                'size_bytes' => $bytes,
                'mime' => $mime,
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
            'mime' => $mime,
        ];
    }

    public function temporaryUrl(string $path, int $minutes = 60, array $options = []): string
    {
        $disk = 's3';

        return Storage::disk($disk)->temporaryUrl(
            $path,
            now()->addMinutes($minutes),
            $options
        );
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