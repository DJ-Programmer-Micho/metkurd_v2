<?php

namespace App\Jobs;

use App\Models\ApiJob;
use App\Models\MlJob;
use App\Services\ASR\AsrJobSyncService;
use App\Services\ASR\QasrJobSyncService;
use App\Services\CustomerApi\CustomerApiJobSyncService;
use App\Services\MetKurd\Jobs\DurableUploadSubmission;
use App\Services\OCR\OcrJobSyncService;
use App\Services\STEM\StemJobSyncService;
use App\Services\Translation\TranJobSyncService;
use App\Services\XTTS\XttsJobSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileMlJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public function __construct(public string $jobId) {}

    public function handle(): void
    {
        $job = MlJob::query()->with('tool')->find($this->jobId);
        if (! $job) {
            return;
        }
        app(DurableUploadSubmission::class)->retryRefund($job);
        if ($job->isActive() && $job->provider_job_id) {
            $code = strtolower((string) ($job->tool?->code ?: $job->job_kind));
            match ($code) {
                'tts', 'xomni', 'xomni-v2', 'ftts', 'clone_tts', 'clone_xomni', 'vector-v2', 'zeta', 'theta' => $job->tool ? app(XttsJobSyncService::class)->sync($job, $job->tool) : null,
                'asr', 'wasr' => $job->tool ? app(AsrJobSyncService::class)->sync($job, $job->tool) : null,
                'qasr', 'leo', 'caption' => $job->tool ? app(QasrJobSyncService::class)->sync($job, $job->tool) : null,
                'tran' => $job->tool ? app(TranJobSyncService::class)->sync($job, $job->tool) : null,
                'ocr' => app(OcrJobSyncService::class)->sync($job),
                'stem' => app(StemJobSyncService::class)->sync($job),
                default => null,
            };
        }
        // API reservations belong to the API wallet and retain their own settlement logic.
        foreach (ApiJob::query()->where('ml_job_id', $job->id)->whereIn('status', ['queued', 'processing', 'accepted'])->get() as $apiJob) {
            app(CustomerApiJobSyncService::class)->syncFromMlJob($apiJob);
        }
    }
}
