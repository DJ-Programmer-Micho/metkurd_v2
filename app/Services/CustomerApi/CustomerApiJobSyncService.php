<?php

namespace App\Services\CustomerApi;

use App\Models\ApiCreditReservation;
use App\Models\ApiJob;
use App\Models\ApiUsageLog;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\ASR\AsrJobSyncService;
use App\Services\ASR\QasrJobSyncService;
use App\Services\OCR\OcrJobSyncService;
use App\Services\STEM\StemJobSyncService;
use App\Services\Translation\TranJobSyncService;
use App\Services\XTTS\XttsJobSyncService;
use Illuminate\Support\Facades\DB;

class CustomerApiJobSyncService
{
    public function __construct(
        protected XttsJobSyncService $xtts,
        protected AsrJobSyncService $asr,
        protected QasrJobSyncService $qasr,
        protected OcrJobSyncService $ocr,
        protected TranJobSyncService $translate,
        protected StemJobSyncService $stem,
        protected CustomerApiFileLinkService $files,
        protected CustomerApiCreditReservationService $reservations,
    ) {}

    public function refresh(ApiJob $apiJob): ApiJob
    {
        $apiJob->loadMissing(['mlJob.tool', 'resultFiles.storageFile']);

        $mlJob = $apiJob->mlJob;

        if ($mlJob instanceof MlJob && $mlJob->isActive()) {
            $tool = $this->resolveTool($mlJob);
            $this->syncActiveMlJob($mlJob, $tool);
        }

        return $this->syncFromMlJob($apiJob->fresh(['mlJob.tool', 'resultFiles.storageFile']) ?: $apiJob);
    }

    public function syncFromMlJob(ApiJob $apiJob): ApiJob
    {
        $apiJob->loadMissing(['mlJob.tool', 'resultFiles.storageFile']);

        return DB::transaction(function () use ($apiJob): ApiJob {
            /** @var ApiJob $fresh */
            $fresh = ApiJob::query()
                ->with(['mlJob.tool', 'resultFiles.storageFile'])
                ->lockForUpdate()
                ->findOrFail($apiJob->id);

            $mlJob = $fresh->mlJob;
            $status = $this->mapStatus($mlJob);

            $fresh->status = $status;
            $fresh->error_message = (string) data_get($mlJob?->error, 'message', $fresh->error_message);
            $fresh->error_code = $status === 'failed' ? ($fresh->error_code ?: 'job_failed') : null;

            if ($status === 'completed') {
                $result = $fresh->resultFiles->first(fn ($resultFile): bool => $resultFile->deleted_at === null)
                    ?: $this->files->attachPrimaryResult($fresh);
                $this->settleReservation($fresh, (int) $fresh->estimated_credits);

                if ($result !== null) {
                    $fresh->setRelation('resultFiles', collect([$result]));
                }
            }

            if ($status === 'failed') {
                $this->releaseReservation($fresh);
            }

            if (in_array($status, ['completed', 'failed', 'cancelled'], true) && $fresh->completed_at === null) {
                $fresh->completed_at = now();
            }

            $fresh->final_credits = $status === 'completed'
                ? max(0, (int) ($fresh->final_credits ?: $fresh->estimated_credits))
                : 0;

            $fresh->save();

            ApiUsageLog::query()
                ->where('api_job_id', (string) $fresh->id)
                ->whereIn('status', ['received', 'accepted', 'queued', 'processing'])
                ->update([
                    'status' => $status,
                    'credits_charged' => $status === 'completed' ? (int) $fresh->final_credits : 0,
                    'updated_at' => now(),
                ]);

            return $fresh->fresh(['mlJob.tool', 'resultFiles.storageFile']) ?: $fresh;
        }, 3);
    }

    protected function settleReservation(ApiJob $apiJob, int $finalCredits): void
    {
        $reservation = ApiCreditReservation::query()
            ->where('api_job_id', (string) $apiJob->id)
            ->first();

        if (! $reservation instanceof ApiCreditReservation) {
            return;
        }

        $this->reservations->settle($reservation, $finalCredits);
    }

    protected function releaseReservation(ApiJob $apiJob): void
    {
        $reservation = ApiCreditReservation::query()
            ->where('api_job_id', (string) $apiJob->id)
            ->first();

        if (! $reservation instanceof ApiCreditReservation) {
            return;
        }

        $this->reservations->release($reservation);
    }

    protected function mapStatus(?MlJob $mlJob): string
    {
        return match ((string) ($mlJob?->status ?? 'queued')) {
            'queued' => 'queued',
            'running', 'saving' => 'processing',
            'done' => 'completed',
            'deleted' => 'cancelled',
            'failed', 'delete_failed' => 'failed',
            default => 'processing',
        };
    }

    protected function resolveTool(MlJob $mlJob): ?Tool
    {
        if ($mlJob->relationLoaded('tool') && $mlJob->tool instanceof Tool) {
            return $mlJob->tool;
        }

        $toolId = (int) ($mlJob->tool_id ?? 0);

        return $toolId > 0 ? Tool::query()->find($toolId) : null;
    }

    protected function syncActiveMlJob(MlJob $mlJob, ?Tool $tool): void
    {
        $toolCode = strtolower(trim((string) ($tool?->code ?? $mlJob->job_kind ?? '')));

        match ($toolCode) {
            'tts', 'xomni', 'ftts', 'clone_tts', 'clone_xomni' => $tool instanceof Tool ? $this->xtts->sync($mlJob, $tool) : null,
            'asr', 'wasr' => $tool instanceof Tool ? $this->asr->sync($mlJob, $tool) : null,
            'qasr', 'caption' => $tool instanceof Tool ? $this->qasr->sync($mlJob, $tool) : null,
            'ocr' => $this->ocr->sync($mlJob),
            'tran' => $tool instanceof Tool ? $this->translate->sync($mlJob, $tool) : null,
            'stem' => $this->stem->sync($mlJob),
            default => null,
        };
    }
}
