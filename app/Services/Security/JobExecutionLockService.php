<?php

namespace App\Services\Security;

use App\Models\MlJob;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobExecutionLockService
{
    public function acquireCloneLock(
        int $customerId,
        string $jobId,
        Session $session,
        ?string $agent = null,
        ?string $ip = null
    ): array {
        return DB::transaction(function () use ($customerId, $jobId, $session, $agent, $ip) {
            $now = now();
            $expiresAt = $now->copy()->addMinutes(30);

            $sessionId = (string) $session->getId();
            $fingerprint = $this->makeFingerprint($customerId, $agent, $ip, 'clone_tts');

            MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'clone_tts')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '<', $now)
                ->update([
                    'execution_scope' => null,
                    'locked_by_session_id' => null,
                    'locked_by_fingerprint' => null,
                    'lock_expires_at' => null,
                    'updated_at' => $now,
                ]);

            $conflict = MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'clone_tts')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', $now)
                ->where(function ($q) use ($sessionId, $fingerprint) {
                    $q->where('locked_by_session_id', '!=', $sessionId)
                        ->orWhere('locked_by_fingerprint', '!=', $fingerprint);
                })
                ->first();

            if ($conflict) {
                return [
                    'ok' => false,
                    'message' => 'Clone XTTS is already running on another browser or machine.',
                    'conflict_job_id' => (string) $conflict->id,
                ];
            }

            $updated = MlJob::query()
                ->where('id', $jobId)
                ->where('customer_id', $customerId)
                ->update([
                    'job_kind' => 'clone_tts',
                    'execution_scope' => 'device',
                    'locked_by_session_id' => $sessionId,
                    'locked_by_fingerprint' => $fingerprint,
                    'lock_expires_at' => $expiresAt,
                    'updated_at' => $now,
                ]);

            return $updated
                ? [
                    'ok' => true,
                    'session_id' => $sessionId,
                    'fingerprint' => $fingerprint,
                    'expires_at' => $expiresAt->toDateTimeString(),
                ]
                : [
                    'ok' => false,
                    'message' => 'Could not lock the job.',
                ];
        });
    }

    public function acquireAsrLock(
        int $customerId,
        string $jobId,
        string $inputHash,
        Session $session,
        ?string $agent = null,
        ?string $ip = null
    ): array {
        return DB::transaction(function () use ($customerId, $jobId, $inputHash, $session, $agent, $ip) {
            $now = now();
            $expiresAt = $now->copy()->addMinutes(60);

            $sessionId = (string) $session->getId();
            $fingerprint = $this->makeFingerprint($customerId, $agent, $ip, 'asr');

            MlJob::query()
                ->where('customer_id', $customerId)
                ->whereIn('job_kind', ['asr', 'wasr'])
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '<', $now)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => 'ASR lock expired. Job marked as stale.'],
                    'finished_at' => $now,
                    'execution_scope' => null,
                    'locked_by_session_id' => null,
                    'locked_by_fingerprint' => null,
                    'lock_expires_at' => null,
                    'updated_at' => $now,
                ]);

            MlJob::query()
                ->where('customer_id', $customerId)
                ->whereIn('job_kind', ['asr', 'wasr'])
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNull('lock_expires_at')
                ->where('id', '!=', $jobId)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => 'ASR stale job without lock. Auto-cleaned.'],
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);

            $sameFileConflict = MlJob::query()
                ->where('customer_id', $customerId)
                ->whereIn('job_kind', ['asr', 'wasr'])
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->where('id', '!=', $jobId)
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', $now)
                ->where('input_hash', $inputHash)
                ->first();

            if ($sameFileConflict) {
                return [
                    'ok' => false,
                    'message' => 'This same audio file is already being transcribed.',
                    'conflict_job_id' => (string) $sameFileConflict->id,
                ];
            }

            $activeCount = MlJob::query()
                ->where('customer_id', $customerId)
                ->whereIn('job_kind', ['asr', 'wasr'])
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', $now)
                ->count();

            $maxConcurrent = $this->resolveMaxConcurrentAsr($customerId);

            if ($activeCount >= $maxConcurrent) {
                return [
                    'ok' => false,
                    'message' => "You already have {$activeCount} ASR job(s) in progress. Please wait for one to finish.",
                ];
            }

            $updated = MlJob::query()
                ->where('id', $jobId)
                ->where('customer_id', $customerId)
                ->update([
                    'job_kind' => 'wasr',
                    'execution_scope' => 'customer',
                    'locked_by_session_id' => $sessionId,
                    'locked_by_fingerprint' => $fingerprint,
                    'lock_expires_at' => $expiresAt,
                    'input_hash' => $inputHash,
                    'updated_at' => $now,
                ]);

            return $updated
                ? [
                    'ok' => true,
                    'session_id' => $sessionId,
                    'fingerprint' => $fingerprint,
                    'expires_at' => $expiresAt->toDateTimeString(),
                ]
                : [
                    'ok' => false,
                    'message' => 'Could not lock the ASR job.',
                ];
        });
    }

    public function acquireStemLock(
        int $customerId,
        string $jobId,
        string $inputHash,
        Session $session,
        ?string $agent = null,
        ?string $ip = null
    ): array {
        return DB::transaction(function () use ($customerId, $jobId, $inputHash, $session, $agent, $ip) {
            $now = now();
            $expiresAt = $now->copy()->addMinutes(60);

            $sessionId = (string) $session->getId();
            $fingerprint = $this->makeFingerprint($customerId, $agent, $ip, 'stem');

            /*
            |--------------------------------------------------------------------------
            | 1) Clean expired locked STEM jobs
            |--------------------------------------------------------------------------
            */
            MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'stem')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '<', $now)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => 'STEM lock expired. Job marked as stale.'],
                    'finished_at' => $now,
                    'execution_scope' => null,
                    'locked_by_session_id' => null,
                    'locked_by_fingerprint' => null,
                    'lock_expires_at' => null,
                    'updated_at' => $now,
                ]);

            /*
            |--------------------------------------------------------------------------
            | 2) Clean ghost STEM rows without lock
            |--------------------------------------------------------------------------
            */
            MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'stem')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNull('lock_expires_at')
                ->where('id', '!=', $jobId)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => 'STEM stale job without lock. Auto-cleaned.'],
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);

            /*
            |--------------------------------------------------------------------------
            | 3) Same-file conflict against live STEM jobs
            |--------------------------------------------------------------------------
            */
            $sameFileConflict = MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'stem')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->where('id', '!=', $jobId)
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', $now)
                ->where('input_hash', $inputHash)
                ->first();

            if ($sameFileConflict) {
                return [
                    'ok' => false,
                    'message' => 'This same audio file is already being separated.',
                    'conflict_job_id' => (string) $sameFileConflict->id,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 4) Count only live locked STEM jobs
            |--------------------------------------------------------------------------
            */
            $activeCount = MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'stem')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', $now)
                ->count();

            $maxConcurrent = $this->resolveMaxConcurrentStem($customerId);

            if ($activeCount >= $maxConcurrent) {
                return [
                    'ok' => false,
                    'message' => "You already have {$activeCount} STEM job(s) in progress. Please wait for one to finish.",
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 5) Lock current STEM job
            |--------------------------------------------------------------------------
            */
            $updated = MlJob::query()
                ->where('id', $jobId)
                ->where('customer_id', $customerId)
                ->update([
                    'job_kind' => 'stem',
                    'execution_scope' => 'customer',
                    'locked_by_session_id' => $sessionId,
                    'locked_by_fingerprint' => $fingerprint,
                    'lock_expires_at' => $expiresAt,
                    'input_hash' => $inputHash,
                    'updated_at' => $now,
                ]);

            return $updated
                ? [
                    'ok' => true,
                    'session_id' => $sessionId,
                    'fingerprint' => $fingerprint,
                    'expires_at' => $expiresAt->toDateTimeString(),
                ]
                : [
                    'ok' => false,
                    'message' => 'Could not lock the STEM job.',
                ];
        });
    }

    public function acquireOcrLock(
        int $customerId,
        string $jobId,
        string $inputHash,
        Session $session,
        ?string $agent = null,
        ?string $ip = null
    ): array {
        return DB::transaction(function () use ($customerId, $jobId, $inputHash, $session, $agent, $ip) {
            $now = now();
            $expiresAt = $now->copy()->addMinutes(60);

            $sessionId = (string) $session->getId();
            $fingerprint = $this->makeFingerprint($customerId, $agent, $ip, 'ocr');

            MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'ocr')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '<', $now)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => 'OCR lock expired. Job marked as stale.'],
                    'finished_at' => $now,
                    'execution_scope' => null,
                    'locked_by_session_id' => null,
                    'locked_by_fingerprint' => null,
                    'lock_expires_at' => null,
                    'updated_at' => $now,
                ]);

            MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'ocr')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNull('lock_expires_at')
                ->where('id', '!=', $jobId)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => 'OCR stale job without lock. Auto-cleaned.'],
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);

            $sameFileConflict = MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'ocr')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->where('id', '!=', $jobId)
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', $now)
                ->where('input_hash', $inputHash)
                ->first();

            if ($sameFileConflict) {
                return [
                    'ok' => false,
                    'message' => 'This same PDF is already being processed.',
                    'conflict_job_id' => (string) $sameFileConflict->id,
                ];
            }

            $activeCount = MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', 'ocr')
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', $now)
                ->count();

            $maxConcurrent = $this->resolveMaxConcurrentOcr($customerId);

            if ($activeCount >= $maxConcurrent) {
                return [
                    'ok' => false,
                    'message' => "You already have {$activeCount} OCR job(s) in progress. Please wait for one to finish.",
                ];
            }

            $updated = MlJob::query()
                ->where('id', $jobId)
                ->where('customer_id', $customerId)
                ->update([
                    'job_kind' => 'ocr',
                    'execution_scope' => 'customer',
                    'locked_by_session_id' => $sessionId,
                    'locked_by_fingerprint' => $fingerprint,
                    'lock_expires_at' => $expiresAt,
                    'input_hash' => $inputHash,
                    'updated_at' => $now,
                ]);

            return $updated
                ? [
                    'ok' => true,
                    'session_id' => $sessionId,
                    'fingerprint' => $fingerprint,
                    'expires_at' => $expiresAt->toDateTimeString(),
                ]
                : [
                    'ok' => false,
                    'message' => 'Could not lock the OCR job.',
                ];
        });
    }

    public function acquireLock(
        string $scope,
        int $customerId,
        string $jobId,
        Session $session,
        ?string $userAgent = null,
        ?string $ip = null,
        int $ttlSeconds = 3600
    ): array {
        return DB::transaction(function () use ($scope, $customerId, $jobId, $session, $userAgent, $ip, $ttlSeconds) {
            $now = now();
            $expiresAt = $now->copy()->addSeconds(max(60, $ttlSeconds));
            $sessionId = (string) $session->getId();
            $fingerprint = $this->makeFingerprint($customerId, $userAgent, $ip, $scope);
            $scopeLabel = Str::headline(str_replace('_', ' ', $scope));

            MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', $scope)
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '<', $now)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => "{$scopeLabel} lock expired. Job marked as stale."],
                    'finished_at' => $now,
                    'execution_scope' => null,
                    'locked_by_session_id' => null,
                    'locked_by_fingerprint' => null,
                    'lock_expires_at' => null,
                    'updated_at' => $now,
                ]);

            MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', $scope)
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->whereNull('lock_expires_at')
                ->where('id', '!=', $jobId)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => "{$scopeLabel} stale job without lock. Auto-cleaned."],
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);

            $conflict = MlJob::query()
                ->where('customer_id', $customerId)
                ->where('job_kind', $scope)
                ->whereIn('status', ['queued', 'running', 'saving'])
                ->where('id', '!=', $jobId)
                ->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', $now)
                ->first();

            if ($conflict) {
                return [
                    'ok' => false,
                    'message' => "{$scopeLabel} is already in progress.",
                    'conflict_job_id' => (string) $conflict->id,
                ];
            }

            $updated = MlJob::query()
                ->where('id', $jobId)
                ->where('customer_id', $customerId)
                ->update([
                    'job_kind' => $scope,
                    'execution_scope' => 'customer',
                    'locked_by_session_id' => $sessionId,
                    'locked_by_fingerprint' => $fingerprint,
                    'lock_expires_at' => $expiresAt,
                    'updated_at' => $now,
                ]);

            return $updated
                ? [
                    'ok' => true,
                    'session_id' => $sessionId,
                    'fingerprint' => $fingerprint,
                    'expires_at' => $expiresAt->toDateTimeString(),
                ]
                : [
                    'ok' => false,
                    'message' => 'Could not lock the job.',
                ];
        });
    }

    public function refreshLock(string $jobId, int $minutes = 30): void
    {
        MlJob::query()
            ->where('id', $jobId)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->update([
                'lock_expires_at' => now()->addMinutes($minutes),
                'updated_at' => now(),
            ]);
    }

    public function releaseLock(string $jobId): void
    {
        MlJob::query()
            ->where('id', $jobId)
            ->update([
                'execution_scope' => null,
                'locked_by_session_id' => null,
                'locked_by_fingerprint' => null,
                'lock_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    protected function resolveMaxConcurrentAsr(int $customerId): int
    {
        try {
            $customer = \App\Models\Customer::find($customerId);
            $planCode = strtolower((string) ($customer?->serviceCode() ?? 'free'));

            return match ($planCode) {
                'student' => 2,
                'pro' => 3,
                'premium' => 5,
                default => 1,
            };
        } catch (\Throwable) {
            return 1;
        }
    }

    protected function resolveMaxConcurrentStem(int $customerId): int
    {
        try {
            $customer = \App\Models\Customer::find($customerId);
            $planCode = strtolower((string) ($customer?->serviceCode() ?? 'free'));

            return match ($planCode) {
                'student' => 1,
                'pro' => 2,
                'premium' => 3,
                default => 1,
            };
        } catch (\Throwable) {
            return 1;
        }
    }

    protected function resolveMaxConcurrentOcr(int $customerId): int
    {
        try {
            $customer = \App\Models\Customer::find($customerId);
            $planCode = strtolower((string) ($customer?->serviceCode() ?? 'free'));

            return match ($planCode) {
                'student' => 1,
                'pro' => 2,
                'premium' => 3,
                default => 1,
            };
        } catch (\Throwable) {
            return 1;
        }
    }

    protected function makeFingerprint(
        int $customerId,
        ?string $agent = null,
        ?string $ip = null,
        string $scope = 'clone_tts'
    ): string {
        $agent = trim((string) $agent);
        $ip = trim((string) $ip);

        return hash('sha256', implode('|', [
            $scope,
            $customerId,
            $agent,
            $ip,
            config('app.key'),
        ]));
    }
}
