<?php

namespace App\Services\Mobile;

use App\Models\MlJob;
use App\Models\Tool;
use App\Services\ASR\AsrJobSyncService;
use App\Services\ASR\QasrJobSyncService;
use App\Services\OCR\OcrJobSyncService;
use App\Services\STEM\StemJobSyncService;
use App\Services\Translation\TranJobSyncService;
use App\Services\XTTS\XttsJobSyncService;
use Illuminate\Support\Facades\Log;

class MobileJobStatusSyncService
{
    public function __construct(
        protected XttsJobSyncService $xtts,
        protected AsrJobSyncService $asr,
        protected QasrJobSyncService $qasr,
        protected StemJobSyncService $stem,
        protected OcrJobSyncService $ocr,
        protected TranJobSyncService $tran,
    ) {
    }

    public function refresh(MlJob $job): MlJob
    {
        $job->loadMissing('tool');

        if (! $job->isActive()) {
            return $this->freshJob($job);
        }

        if ((string) ($job->provider ?? '') !== 'runpod') {
            return $this->freshJob($job);
        }

        if ((string) ($job->provider_job_id ?? '') === '') {
            return $this->freshJob($job);
        }

        try {
            $this->sync($job);
        } catch (\Throwable $e) {
            Log::warning('MOBILE_JOB_STATUS_SYNC_FAIL', [
                'job_id' => (string) $job->id,
                'job_kind' => (string) ($job->job_kind ?? ''),
                'tool_code' => (string) ($job->tool?->code ?? ''),
                'message' => $e->getMessage(),
            ]);
        }

        return $this->freshJob($job);
    }

    protected function sync(MlJob $job): void
    {
        $tool = $job->tool;
        $toolCode = strtolower(trim((string) ($tool?->code ?? '')));
        $jobKind = strtolower(trim((string) ($job->job_kind ?? '')));

        match ($jobKind !== '' ? $jobKind : $toolCode) {
            'tts', 'ftts', 'clone_tts' => $this->xtts->sync($job, $this->resolveTool($tool, $toolCode, ['tts', 'ftts', 'clone_tts'])),
            'wasr', 'asr' => $this->asr->sync($job, $this->resolveTool($tool, $toolCode, ['wasr', 'asr'])),
            'qasr' => $this->qasr->sync($job, $this->resolveTool($tool, $toolCode, ['qasr'])),
            'stem' => $this->stem->sync($job),
            'ocr' => $this->ocr->sync($job),
            'tran' => $this->tran->sync($job, $this->resolveTool($tool, $toolCode, ['tran'])),
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $fallbackCodes
     */
    protected function resolveTool(?Tool $tool, string $toolCode, array $fallbackCodes): Tool
    {
        if ($tool instanceof Tool) {
            return $tool;
        }

        foreach ($fallbackCodes as $code) {
            $resolved = Tool::query()->where('code', $code)->first();

            if ($resolved instanceof Tool) {
                return $resolved;
            }
        }

        throw new \RuntimeException('Tool not found for mobile job sync: ' . ($toolCode !== '' ? $toolCode : implode(',', $fallbackCodes)));
    }

    protected function freshJob(MlJob $job): MlJob
    {
        return MlJob::query()
            ->with([
                'tool:id,code,name,meta',
                'toolAction:id,tool_code,full_code,name',
            ])
            ->findOrFail($job->id);
    }
}
