<?php

namespace App\Services\CustomerApi;

use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\CustomerFile;
use Illuminate\Support\Str;

class CustomerApiFileLinkService
{
    public function attachArtifacts(ApiJob $job): void
    {
        if ($job->status !== 'completed' || ! $job->ml_job_id
            || (data_get($job->meta, 'expires_at') && now()->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse(data_get($job->meta, 'expires_at'))))) {
            return;
        }
        $files = CustomerFile::where('customer_id', $job->customer_id)->where('status', 'active')
            ->where('meta->job_id', $job->ml_job_id)->whereIn('purpose', ['render', 'transcription', 'caption'])->get();
        foreach ($files as $file) {
            if (! $file->expires_at || ! $file->expires_at->isPast()) {
                ApiResultFile::firstOrCreate(['api_job_id' => $job->id, 'storage_file_id' => $file->id, 'result_kind' => 'artifact'],
                    ['id' => 'file_'.Str::lower((string) Str::ulid()), 'customer_id' => $job->customer_id]);
            }
        }
    }

    public function attachPrimaryResult(ApiJob $apiJob): ?ApiResultFile
    {
        $apiJob->loadMissing('mlJob');

        $reference = $this->primaryOutputReference($apiJob);
        $disk = (string) ($reference['disk'] ?? data_get($apiJob->mlJob?->output, 'disk', 's3'));
        $path = (string) ($reference['path'] ?? '');

        if ($path === '') {
            return null;
        }

        $storageFile = CustomerFile::query()
            ->where('customer_id', (int) $apiJob->customer_id)
            ->where('disk', $disk)
            ->where('path', $path)
            ->first();

        if (! $storageFile instanceof CustomerFile) {
            return null;
        }

        $storageFile->forceFill([
            'source_type' => 'api_job',
            'source_id' => (string) $apiJob->id,
        ])->save();

        return ApiResultFile::query()->firstOrCreate(
            [
                'api_job_id' => (string) $apiJob->id,
                'storage_file_id' => (int) $storageFile->id,
                'result_kind' => 'primary',
            ],
            [
                'id' => 'file_'.Str::lower((string) Str::ulid()),
                'customer_id' => (int) $apiJob->customer_id,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resultPayload(ApiResultFile $resultFile): array
    {
        $resultFile->loadMissing('storageFile');
        $storageFile = $resultFile->storageFile;

        return [
            'file_id' => (string) $resultFile->id,
            'mime_type' => (string) ($storageFile?->mime ?? 'application/octet-stream'),
            'size_bytes' => (int) ($storageFile?->size_bytes ?? 0),
            'download_url' => route('api.customer.v1.files.download', ['file' => (string) $resultFile->id]),
            'expires_at' => $storageFile?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{disk: string, path: string}|null
     */
    protected function primaryOutputReference(ApiJob $apiJob): ?array
    {
        $output = (array) ($apiJob->mlJob?->output ?? []);
        $disk = (string) data_get($output, 'disk', 's3');
        $toolCode = strtolower(trim((string) ($apiJob->tool_code ?: $apiJob->engine ?: data_get($apiJob->mlJob, 'job_kind', ''))));

        $candidates = match ($toolCode) {
            'caption' => [
                data_get($output, 'srt_file.path'),
                data_get($output, 'srt.path'),
                data_get($output, 'srt_path'),
                data_get($output, 'path'),
            ],
            'ocr' => [
                data_get($output, 'text.path'),
                data_get($output, 'json.path'),
            ],
            'stem' => [
                data_get($output, 'stems.vocals.path'),
                data_get($output, 'stems.instrumental.path'),
                data_get($output, 'stems.drums.path'),
                data_get($output, 'stems.bass.path'),
                data_get($output, 'stems.other.path'),
                data_get($output, 'result_json.path'),
                data_get($output, 'original.path'),
            ],
            default => [
                data_get($output, 'path'),
                data_get($output, 'text.path'),
                data_get($output, 'json.path'),
            ],
        };

        foreach ($candidates as $candidate) {
            $path = trim((string) $candidate);

            if ($path !== '') {
                return ['disk' => $disk, 'path' => $path];
            }
        }

        return null;
    }
}
