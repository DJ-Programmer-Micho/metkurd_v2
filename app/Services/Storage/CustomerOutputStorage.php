<?php

namespace App\Services\Storage;

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Services\Billing\CustomerBillingStateService;
use App\Support\CustomerFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerOutputStorage
{
    public function customerFolderFromCustomer(Customer $customer): string
    {
        return CustomerFolder::make(
            (int) $customer->id,
            $customer->profile?->first_name ?? $customer->first_name ?? null,
            $customer->profile?->last_name ?? $customer->last_name ?? null,
            $customer->username ?? null
        );
    }

    public function customerFolder(MlJob $job): string
    {
        $customer = $job->relationLoaded('customer')
            ? $job->customer
            : $job->customer()->with('profile')->first();

        if ($customer instanceof Customer) {
            return $this->customerFolderFromCustomer($customer);
        }

        return CustomerFolder::make((int) $job->customer_id, null, null, null);
    }

    public function renderBaseDir(MlJob $job, string $toolDir): string
    {
        return "renders/{$this->customerFolder($job)}/{$toolDir}/{$job->id}";
    }

    public function saveWavB64ToS3(int $customerId, string $path, string $wavB64, array $meta = []): array
    {
        $bin = base64_decode($wavB64, true);

        if ($bin === false || $bin === '') {
            throw new \RuntimeException('Invalid wav_b64.');
        }

        $disk = 's3';
        $bytes = strlen($bin);
        $mime = $meta['mime'] ?? 'audio/wav';
        $startedAt = microtime(true);
        $countsTowardQuota = (bool) ($meta['counts_toward_quota'] ?? true);

        if ($countsTowardQuota) {
            $this->assertCanConsumeStorage($customerId, $bytes);
        }

        // Log::info('CUSTOMER_OUTPUT_S3_SAVE_START', [
        //     'disk' => $disk,
        //     'path' => $path,
        //     'bytes' => $bytes,
        //     'mime' => $mime,
        //     'bucket' => config('filesystems.disks.s3.bucket'),
        //     'region' => config('filesystems.disks.s3.region'),
        //     'endpoint' => config('filesystems.disks.s3.endpoint'),
        //     'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint'),
        //     'config_cached' => app()->configurationIsCached(),
        // ]);

        try {
            Storage::disk($disk)->put($path, $bin, [
                'visibility' => 'private',
                'ContentType' => $mime,
            ]);
        } catch (\Throwable $e) {
            Log::error('CUSTOMER_OUTPUT_S3_SAVE_FAIL', [
                'disk' => $disk,
                'path' => $path,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Log::info('CUSTOMER_OUTPUT_S3_SAVE_DONE', [
        //     'disk' => $disk,
        //     'path' => $path,
        //     'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        // ]);

        $this->recordCustomerFile($customerId, $disk, $path, $bytes, $mime, $meta);

        return compact('disk', 'path', 'bytes', 'mime');
    }

    public function saveTextToS3(int $customerId, string $path, string $content, array $meta = []): array
    {
        if ($content === '') {
            throw new \RuntimeException('Cannot save empty text content.');
        }

        $disk = 's3';
        $bytes = strlen($content);
        $mime = $meta['mime'] ?? 'text/plain';
        $countsTowardQuota = (bool) ($meta['counts_toward_quota'] ?? true);

        if ($countsTowardQuota) {
            $this->assertCanConsumeStorage($customerId, $bytes);
        }

        Storage::disk($disk)->put($path, $content, [
            'visibility' => 'private',
            'ContentType' => $mime,
        ]);

        $this->recordCustomerFile($customerId, $disk, $path, $bytes, $mime, $meta);

        return compact('disk', 'path', 'bytes', 'mime');
    }

    public function saveUploadedFileToS3(int $customerId, UploadedFile $file, string $path, array $meta = []): array
    {
        if (! $file->isValid()) {
            throw new \RuntimeException('Uploaded file is not valid.');
        }

        $disk = 's3';
        $stream = $this->openUploadedFileReadStream($file);

        if (! is_resource($stream)) {
            throw new \RuntimeException('Unable to open uploaded file stream.');
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $bytes = (int) $file->getSize();
        $countsTowardQuota = (bool) ($meta['counts_toward_quota'] ?? true);

        if ($countsTowardQuota) {
            $this->assertCanConsumeStorage($customerId, $bytes);
        }

        try {
            Storage::disk($disk)->put($path, $stream, [
                'visibility' => 'private',
                'ContentType' => $mime,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->recordCustomerFile($customerId, $disk, $path, $bytes, $mime, $meta);

        return compact('disk', 'path', 'bytes', 'mime');
    }

    public function inputBaseDir(Customer $customer, string $toolCode, string $jobId): string
    {
        $toolSegment = $this->normalizePathSegment($toolCode, 'tool');
        $jobSegment = trim($jobId);

        if ($jobSegment === '') {
            throw new \InvalidArgumentException('Job ID is required for input storage path.');
        }

        return "renders/{$this->customerFolderFromCustomer($customer)}/{$toolSegment}/{$jobSegment}";
    }

    public function inputPath(
        Customer $customer,
        string $toolCode,
        string $jobId,
        string $extension,
        string $baseName = 'input'
    ): string {
        $base = $this->inputBaseDir($customer, $toolCode, $jobId);
        $name = $this->normalizePathSegment($baseName, 'input');
        $ext = $this->normalizeExtension($extension, 'bin');

        return "{$base}/{$name}.{$ext}";
    }

    public function storeJobInputFile(
        Customer $customer,
        UploadedFile $file,
        string $toolCode,
        string $jobId,
        array $meta = [],
        ?string $extension = null,
        string $baseName = 'input'
    ): array {
        $resolvedExtension = $this->normalizeExtension(
            $extension ?? (string) ($file->getClientOriginalExtension() ?: ''),
            'bin'
        );

        $path = $this->inputPath(
            customer: $customer,
            toolCode: $toolCode,
            jobId: $jobId,
            extension: $resolvedExtension,
            baseName: $baseName
        );

        $meta = array_merge([
            'tool' => $toolCode,
            'purpose' => 'input',
            'role' => 'source_file',
            'original_name' => $file->getClientOriginalName(),
        ], $meta);

        return $this->saveUploadedFileToS3(
            customerId: (int) $customer->id,
            file: $file,
            path: $path,
            meta: $meta
        );
    }

    public function temporaryUrl(string $path, int $minutes = 60, array $options = []): string
    {
        return $this->temporaryUrlForDisk('s3', $path, now()->addMinutes($minutes), $options);
    }

    public function temporaryUrlForDisk(string $disk, string $path, ?\DateTimeInterface $expiresAt = null, array $options = []): string
    {
        $expiresAt ??= now()->addHours(8);

        if (method_exists(Storage::disk($disk), 'temporaryUrl')) {
            return Storage::disk($disk)->temporaryUrl($path, $expiresAt, $options);
        }

        return Storage::disk($disk)->url($path);
    }

    public function deleteFromS3AndUncount(int $customerId, string $path, int $bytes = 0): void
    {
        $this->deleteFromDiskAndUncount($customerId, 's3', $path, $bytes);
    }

    public function deleteFromDiskAndUncount(int $customerId, string $disk, string $path, int $bytes = 0): void
    {
        if ($path === '') {
            return;
        }

        DB::transaction(function () use ($customerId, $disk, $path, $bytes) {
            $file = CustomerFile::query()
                ->where('customer_id', $customerId)
                ->where('disk', $disk)
                ->where('path', $path)
                ->lockForUpdate()
                ->first();

            $actualBytes = $bytes > 0
                ? $bytes
                : (int) ($file?->size_bytes ?? 0);

            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }

            if ($file && (string) $file->status !== 'deleted') {
                $file->status = 'deleted';
                $file->deleted_at = now();
                $file->delete_reason = $file->delete_reason ?: 'deleted';
                $file->save();
            }

            if ($actualBytes > 0 && (bool) ($file?->counts_toward_quota ?? true)) {
                $usage = CustomerUsage::query()
                    ->where('customer_id', $customerId)
                    ->lockForUpdate()
                    ->first();

                if ($usage) {
                    $usage->storage_used_bytes = max(0, (int) $usage->storage_used_bytes - $actualBytes);
                    $usage->save();
                }
            }
        }, 3);
    }

    public function registerExistingObject(int $customerId, string $disk, string $path, array $meta = []): ?array
    {
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $existing = CustomerFile::query()
            ->where('customer_id', $customerId)
            ->where('disk', $disk)
            ->where('path', $path)
            ->where('status', '!=', 'deleted')
            ->first();

        $bytes = (int) Storage::disk($disk)->size($path);
        $mime = (string) (Storage::disk($disk)->mimeType($path) ?: ($meta['mime'] ?? 'application/octet-stream'));
        $countsTowardQuota = (bool) ($meta['counts_toward_quota'] ?? true);

        if (! $existing) {
            if ($countsTowardQuota) {
                $this->assertCanConsumeStorage($customerId, $bytes);
            }
            $this->recordCustomerFile($customerId, $disk, $path, $bytes, $mime, $meta);
        }

        return compact('disk', 'path', 'bytes', 'mime');
    }

    protected function recordCustomerFile(
        int $customerId,
        string $disk,
        string $path,
        int $bytes,
        string $mime,
        array $meta = []
    ): void {
        DB::transaction(function () use ($customerId, $disk, $path, $bytes, $mime, $meta) {
            $countsTowardQuota = (bool) ($meta['counts_toward_quota'] ?? true);

            CustomerFile::create([
                'customer_id' => $customerId,
                'purpose' => $meta['purpose'] ?? 'render',
                'tool_code' => $meta['tool'] ?? 'tts',
                'disk' => $disk,
                'path' => $path,
                'size_bytes' => $bytes,
                'mime' => $mime,
                'checksum' => $meta['checksum'] ?? null,
                'status' => 'active',
                'retention_mode' => $meta['retention_mode'] ?? null,
                'expires_at' => $meta['expires_at'] ?? null,
                'delete_reason' => null,
                'source_type' => $meta['source_type'] ?? null,
                'source_id' => isset($meta['source_id']) ? (string) $meta['source_id'] : null,
                'counts_toward_quota' => $countsTowardQuota,
                'meta' => $meta,
            ]);

            if ($countsTowardQuota) {
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
            }
        }, 3);
    }

    public function stemDisk(): string
    {
        return 's3';
    }

    public function stemBaseDir(MlJob $job): string
    {
        return $this->renderBaseDir($job, 'stem');
    }

    public function stemPaths(MlJob $job, string $codec = 'mp3', int $stemsMode = 4): array
    {
        $base = $this->stemBaseDir($job);

        $stems = $stemsMode === 2
            ? [
                'vocals' => "{$base}/stems/vocals.{$codec}",
                'instrumental' => "{$base}/stems/instrumental.{$codec}",
            ]
            : [
                'vocals' => "{$base}/stems/vocals.{$codec}",
                'drums' => "{$base}/stems/drums.{$codec}",
                'bass' => "{$base}/stems/bass.{$codec}",
                'other' => "{$base}/stems/other.{$codec}",
            ];

        return [
            'original' => "{$base}/original.wav",
            'result_json' => "{$base}/result.json",
            'stems' => $stems,
        ];
    }

    public function makeStemUploadTargets(MlJob $job, string $codec = 'mp3', int $stemsMode = 4): array
    {
        $this->assertCanConsumeStorage((int) $job->customer_id);

        $paths = $this->stemPaths($job, $codec, $stemsMode);

        return [
            'original_wav' => $this->temporaryUploadTarget(
                disk: $this->stemDisk(),
                path: $paths['original'],
                contentType: 'audio/wav'
            ),
            'result_json' => $this->temporaryUploadTarget(
                disk: $this->stemDisk(),
                path: $paths['result_json'],
                contentType: 'application/json'
            ),
            'stems' => collect($paths['stems'])
                ->mapWithKeys(function (string $path, string $name) use ($codec) {
                    $mime = match (strtolower($codec)) {
                        'wav' => 'audio/wav',
                        'flac' => 'audio/flac',
                        default => 'audio/mpeg',
                    };

                    return [
                        $name => $this->temporaryUploadTarget(
                            disk: $this->stemDisk(),
                            path: $path,
                            contentType: $mime
                        ),
                    ];
                })
                ->all(),
        ];
    }

    public function temporaryUploadTarget(
        string $disk,
        string $path,
        string $contentType,
        ?\DateTimeInterface $expiresAt = null
    ): array {
        $expiresAt ??= now()->addHours(8);

        if (method_exists(Storage::disk($disk), 'temporaryUploadUrl')) {
            $upload = Storage::disk($disk)->temporaryUploadUrl(
                $path,
                $expiresAt,
                [
                    'ContentType' => $contentType,
                ]
            );

            $uploadUrl = is_array($upload)
                ? (string) ($upload['url'] ?? '')
                : (string) $upload;

            $uploadHeaders = is_array($upload)
                ? $this->normalizeUploadHeaders((array) ($upload['headers'] ?? []))
                : [];

            return [
                'key' => $path,
                'url' => $uploadUrl,
                'headers' => array_merge($uploadHeaders, [
                    'Content-Type' => $contentType,
                ]),
            ];
        }

        return [
            'key' => $path,
            'url' => route('app.storage.presigned-upload', [
                'disk' => $disk,
                'path' => encrypt($path),
                'expires' => $expiresAt->timestamp,
                'content_type' => $contentType,
            ]),
            'headers' => [
                'Content-Type' => $contentType,
            ],
        ];
    }

    protected function normalizeUploadHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            if ($value === null || $value === '') {
                continue;
            }

            $normalized[(string) $name] = (string) $value;
        }

        return $normalized;
    }

    protected function normalizePathSegment(string $value, string $fallback): string
    {
        $normalized = trim(Str::of($value)->lower()->replaceMatches('/[^a-z0-9_-]+/', '-')->toString(), '-');

        return $normalized !== '' ? $normalized : $fallback;
    }

    protected function normalizeExtension(string $extension, string $fallback = 'bin'): string
    {
        $normalized = trim(Str::of($extension)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString());

        return $normalized !== '' ? $normalized : $fallback;
    }

    public function registerStemArtifacts(MlJob $job, array $output, string $toolCode = 'stem'): int
    {
        return $this->registerStemArtifactsWithMeta($job, $output, $toolCode);
    }

    public function registerStemArtifactsWithMeta(MlJob $job, array $output, string $toolCode = 'stem', array $metaOverrides = []): int
    {
        $disk = (string) data_get($output, 'disk', $this->stemDisk());
        $customerId = (int) $job->customer_id;
        $total = 0;

        $artifacts = array_filter([
            'original' => data_get($output, 'original.path'),
            'result_json' => data_get($output, 'result_json.path'),
            'vocals' => data_get($output, 'stems.vocals.path'),
            'instrumental' => data_get($output, 'stems.instrumental.path'),
            'drums' => data_get($output, 'stems.drums.path'),
            'bass' => data_get($output, 'stems.bass.path'),
            'other' => data_get($output, 'stems.other.path'),
        ]);

        foreach ($artifacts as $role => $path) {
            $saved = $this->registerExistingObject($customerId, $disk, (string) $path, array_merge($this->apiOutputMeta($job, $toolCode, 'render', $role), [
                'job_id' => (string) $job->id,
                'mime' => match ($role) {
                    'result_json' => 'application/json',
                    'original' => 'audio/wav',
                    default => 'audio/mpeg',
                },
            ], $metaOverrides));

            $total += (int) ($saved['bytes'] ?? 0);
        }

        return $total;
    }

    public function deleteStemOutputs(MlJob $job): void
    {
        $output = (array) ($job->output ?? []);
        $outputDisk = (string) data_get($output, 'disk', $this->stemDisk());
        $inputDisk = (string) data_get($job->input, 'audio_disk', 's3');

        $paths = array_filter([
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'original.path', ''), 'bytes' => (int) data_get($output, 'original.bytes', 0)],
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'result_json.path', ''), 'bytes' => (int) data_get($output, 'result_json.bytes', 0)],
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'stems.vocals.path', ''), 'bytes' => (int) data_get($output, 'stems.vocals.bytes', 0)],
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'stems.instrumental.path', ''), 'bytes' => (int) data_get($output, 'stems.instrumental.bytes', 0)],
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'stems.drums.path', ''), 'bytes' => (int) data_get($output, 'stems.drums.bytes', 0)],
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'stems.bass.path', ''), 'bytes' => (int) data_get($output, 'stems.bass.bytes', 0)],
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'stems.other.path', ''), 'bytes' => (int) data_get($output, 'stems.other.bytes', 0)],
            ['disk' => $inputDisk,  'path' => (string) data_get($job->input, 'audio_path', ''), 'bytes' => (int) ((int) $job->storage_in_bytes ?: data_get($job->input, 'audio_bytes', 0))],
        ], fn ($item) => ! empty($item['path']));

        foreach ($paths as $file) {
            $this->deleteFromDiskAndUncount(
                (int) $job->customer_id,
                (string) $file['disk'],
                (string) $file['path'],
                (int) $file['bytes']
            );
        }

        MlJob::query()->where('id', $job->id)->update([
            'status' => 'deleted',
            'output' => null,
            'storage_in_bytes' => 0,
            'storage_out_bytes' => 0,
            'error' => null,
            'updated_at' => now(),
        ]);
    }

    public function ocrDisk(): string
    {
        return 's3';
    }

    public function ocrPaths(MlJob $job, ?string $inputPath = null): array
    {
        $inputPath ??= (string) data_get($job->input, 'file_path', '');

        $base = $inputPath !== ''
            ? str_replace('\\', '/', dirname($inputPath))
            : $this->renderBaseDir($job, 'ocr');

        if ($base === '.' || $base === '/') {
            $base = $this->renderBaseDir($job, 'ocr');
        }

        return [
            'text' => "{$base}/text.txt",
            'json' => "{$base}/result.json",
        ];
    }

    public function makeOcrUploadTargets(MlJob $job, ?string $inputPath = null): array
    {
        $this->assertCanConsumeStorage((int) $job->customer_id);

        $paths = $this->ocrPaths($job, $inputPath);

        return [
            'text' => $this->temporaryUploadTarget(
                disk: $this->ocrDisk(),
                path: $paths['text'],
                contentType: 'text/plain; charset=UTF-8'
            ),
            'json' => $this->temporaryUploadTarget(
                disk: $this->ocrDisk(),
                path: $paths['json'],
                contentType: 'application/json'
            ),
        ];
    }

    public function registerOcrArtifacts(MlJob $job, array $output, string $toolCode = 'ocr', array $metaOverrides = []): int
    {
        $disk = (string) data_get($output, 'disk', $this->ocrDisk());
        $customerId = (int) $job->customer_id;
        $total = 0;

        $artifacts = array_filter([
            'text' => data_get($output, 'text.path'),
            'json' => data_get($output, 'json.path'),
        ]);

        foreach ($artifacts as $role => $path) {
            $saved = $this->registerExistingObject($customerId, $disk, (string) $path, array_merge($this->apiOutputMeta($job, $toolCode, 'render', $role), [
                'job_id' => (string) $job->id,
                'mime' => $role === 'json' ? 'application/json' : 'text/plain; charset=UTF-8',
            ], $metaOverrides));

            $total += (int) ($saved['bytes'] ?? 0);
        }

        return $total;
    }

    protected function openUploadedFileReadStream(UploadedFile $file)
    {
        if (method_exists($file, 'readStream')) {
            try {
                $stream = $file->readStream();

                if (is_resource($stream)) {
                    return $stream;
                }
            } catch (\Throwable $e) {
                Log::warning('CUSTOMER_OUTPUT_UPLOAD_STREAM_READ_FAIL', [
                    'file_class' => get_class($file),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $candidates = [
            $file->getRealPath(),
            method_exists($file, 'getPathname') ? $file->getPathname() : null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '' || ! is_file($candidate) || ! is_readable($candidate)) {
                continue;
            }

            $stream = @fopen($candidate, 'rb');
            if (is_resource($stream)) {
                return $stream;
            }
        }

        return null;
    }

    public function deleteOcrOutputs(MlJob $job): void
    {
        $output = (array) ($job->output ?? []);
        $outputDisk = (string) data_get($output, 'disk', $this->ocrDisk());
        $inputDisk = (string) data_get($job->input, 'file_disk', 's3');

        $paths = array_filter([
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'text.path', ''), 'bytes' => (int) data_get($output, 'text.bytes', 0)],
            ['disk' => $outputDisk, 'path' => (string) data_get($output, 'json.path', ''), 'bytes' => (int) data_get($output, 'json.bytes', 0)],
            ['disk' => $inputDisk, 'path' => (string) data_get($job->input, 'file_path', ''), 'bytes' => (int) ((int) $job->storage_in_bytes ?: data_get($job->input, 'file_bytes', 0))],
        ], fn ($item) => ! empty($item['path']));

        foreach ($paths as $file) {
            $this->deleteFromDiskAndUncount(
                (int) $job->customer_id,
                (string) $file['disk'],
                (string) $file['path'],
                (int) $file['bytes']
            );
        }

        MlJob::query()->where('id', $job->id)->update([
            'status' => 'deleted',
            'output' => null,
            'storage_in_bytes' => 0,
            'storage_out_bytes' => 0,
            'error' => null,
            'updated_at' => now(),
        ]);
    }

    protected function assertCanConsumeStorage(int $customerId, int $bytes = 0): void
    {
        $customer = Customer::query()
            ->with('usage')
            ->find($customerId);

        if (! $customer instanceof Customer) {
            return;
        }

        $state = app(CustomerBillingStateService::class)->storageQuotaState($customer);
        $usedBytes = (int) ($state['used_bytes'] ?? 0);
        $limitBytes = max(1, (int) ($state['current_limit_bytes'] ?? (512 * 1024 * 1024)));

        if ((bool) ($state['upload_blocked'] ?? false)) {
            throw new StorageQuotaExceededException(
                __('Your account is over quota. Delete files or upgrade your storage plan to continue.')
            );
        }

        if ($bytes > 0 && ($usedBytes + $bytes) > $limitBytes) {
            throw new StorageQuotaExceededException(
                __('This action would exceed your current storage quota. Delete files or upgrade your storage plan and try again.')
            );
        }
    }

    public function apiOutputMeta(MlJob $job, string $toolCode, string $purpose = 'render', ?string $role = null): array
    {
        $apiJobId = trim((string) data_get($job->input, 'api_job_id', ''));
        $retentionMode = (string) data_get($job->input, 'api_storage_mode') === 'temporary'
            ? 'temporary'
            : 'permanent';

        return array_filter([
            'job_id' => (string) $job->id,
            'tool' => $toolCode,
            'purpose' => $purpose,
            'role' => $role,
            'retention_mode' => $retentionMode,
            'expires_at' => data_get($job->input, 'api_expires_at'),
            'source_type' => $apiJobId !== '' ? 'api_job' : 'ml_job',
            'source_id' => $apiJobId !== '' ? $apiJobId : (string) $job->id,
            'counts_toward_quota' => $retentionMode !== 'temporary',
        ], static fn (mixed $value): bool => $value !== null);
    }
}
