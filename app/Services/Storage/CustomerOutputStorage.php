<?php

namespace App\Services\Storage;

use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use App\Models\MlJob;
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
        $bytes = strlen($bin);
        $mime = $meta['mime'] ?? 'audio/wav';

        Storage::disk($disk)->put($path, $bin, [
            'visibility' => 'private',
            'ContentType' => $mime,
        ]);

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

        Storage::disk($disk)->put($path, $content, [
            'visibility' => 'private',
            'ContentType' => $mime,
        ]);

        $this->recordCustomerFile($customerId, $disk, $path, $bytes, $mime, $meta);

        return compact('disk', 'path', 'bytes', 'mime');
    }

    public function saveUploadedFileToS3(int $customerId, UploadedFile $file, string $path, array $meta = []): array
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('Uploaded file is not valid.');
        }

        $disk = 's3';
        $stream = fopen($file->getRealPath(), 'r');

        if (!$stream) {
            throw new \RuntimeException('Unable to open uploaded file stream.');
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';

        Storage::disk($disk)->put($path, $stream, [
            'visibility' => 'private',
            'ContentType' => $mime,
        ]);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $bytes = (int) $file->getSize();

        $this->recordCustomerFile($customerId, $disk, $path, $bytes, $mime, $meta);

        return compact('disk', 'path', 'bytes', 'mime');
    }

    public function temporaryUrl(string $path, int $minutes = 60, array $options = []): string
    {
        return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes($minutes), $options);
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
                $file->save();
            }

            if ($actualBytes > 0) {
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
        if ($path === '' || !Storage::disk($disk)->exists($path)) {
            return null;
        }

        $bytes = (int) Storage::disk($disk)->size($path);
        $mime = (string) (Storage::disk($disk)->mimeType($path) ?: ($meta['mime'] ?? 'application/octet-stream'));

        $existing = CustomerFile::query()
            ->where('customer_id', $customerId)
            ->where('disk', $disk)
            ->where('path', $path)
            ->where('status', '!=', 'deleted')
            ->first();

        if (!$existing) {
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
            CustomerFile::create([
                'customer_id' => $customerId,
                'purpose'     => $meta['purpose'] ?? 'render',
                'tool_code'   => $meta['tool'] ?? 'tts',
                'disk'        => $disk,
                'path'        => $path,
                'size_bytes'  => $bytes,
                'mime'        => $mime,
                'checksum'    => $meta['checksum'] ?? null,
                'status'      => 'active',
                'meta'        => $meta,
            ]);

            $usage = CustomerUsage::query()->lockForUpdate()->firstOrCreate(
                ['customer_id' => $customerId],
                [
                    'storage_used_bytes' => 0,
                    'jobs_total'         => 0,
                    'jobs_succeeded'     => 0,
                    'jobs_failed'        => 0,
                ]
            );

            $usage->storage_used_bytes = (int) $usage->storage_used_bytes + $bytes;
            $usage->save();
        }, 3);
    }

    public function stemDisk(): string
    {
        return 's3';
    }

    public function stemBaseDir(MlJob $job): string
    {
        return "renders/stem/{$job->id}";
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
            'original'    => "{$base}/original.wav",
            'result_json' => "{$base}/result.json",
            'stems'       => $stems,
        ];
    }

    public function makeStemUploadTargets(MlJob $job, string $codec = 'mp3', int $stemsMode = 4): array
    {
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

    public function registerStemArtifacts(MlJob $job, array $output, string $toolCode = 'stem'): int
    {
        $disk = (string) data_get($output, 'disk', $this->stemDisk());
        $customerId = (int) $job->customer_id;
        $total = 0;

        $artifacts = array_filter([
            'original'    => data_get($output, 'original.path'),
            'result_json' => data_get($output, 'result_json.path'),
            'vocals'      => data_get($output, 'stems.vocals.path'),
            'instrumental'=> data_get($output, 'stems.instrumental.path'),
            'drums'       => data_get($output, 'stems.drums.path'),
            'bass'        => data_get($output, 'stems.bass.path'),
            'other'       => data_get($output, 'stems.other.path'),
        ]);

        foreach ($artifacts as $role => $path) {
            $saved = $this->registerExistingObject($customerId, $disk, (string) $path, [
                'job_id'  => (string) $job->id,
                'tool'    => $toolCode,
                'purpose' => 'render',
                'role'    => $role,
                'mime'    => match ($role) {
                    'result_json' => 'application/json',
                    'original' => 'audio/wav',
                    default => 'audio/mpeg',
                },
            ]);

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
        ], fn ($item) => !empty($item['path']));

        foreach ($paths as $file) {
            $this->deleteFromDiskAndUncount(
                (int) $job->customer_id,
                (string) $file['disk'],
                (string) $file['path'],
                (int) $file['bytes']
            );
        }

        MlJob::query()->where('id', $job->id)->update([
            'status'            => 'deleted',
            'output'            => null,
            'storage_in_bytes'  => 0,
            'storage_out_bytes' => 0,
            'error'             => null,
            'updated_at'        => now(),
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
            : "renders/ocr/{$job->id}";

        if ($base === '.' || $base === '/') {
            $base = "renders/ocr/{$job->id}";
        }

        return [
            'text' => "{$base}/text.txt",
            'json' => "{$base}/result.json",
        ];
    }

    public function makeOcrUploadTargets(MlJob $job, ?string $inputPath = null): array
    {
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

    public function registerOcrArtifacts(MlJob $job, array $output, string $toolCode = 'ocr'): int
    {
        $disk = (string) data_get($output, 'disk', $this->ocrDisk());
        $customerId = (int) $job->customer_id;
        $total = 0;

        $artifacts = array_filter([
            'text' => data_get($output, 'text.path'),
            'json' => data_get($output, 'json.path'),
        ]);

        foreach ($artifacts as $role => $path) {
            $saved = $this->registerExistingObject($customerId, $disk, (string) $path, [
                'job_id' => (string) $job->id,
                'tool' => $toolCode,
                'purpose' => 'render',
                'role' => $role,
                'mime' => $role === 'json' ? 'application/json' : 'text/plain; charset=UTF-8',
            ]);

            $total += (int) ($saved['bytes'] ?? 0);
        }

        return $total;
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
        ], fn ($item) => !empty($item['path']));

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
}
