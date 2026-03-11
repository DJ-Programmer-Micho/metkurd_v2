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
            $fingerprint = $this->makeFingerprint($customerId, $agent, $ip);

            // release expired clone locks first
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

            if (!$updated) {
                return [
                    'ok' => false,
                    'message' => 'Could not lock the job.',
                ];
            }

            return [
                'ok' => true,
                'session_id' => $sessionId,
                'fingerprint' => $fingerprint,
                'expires_at' => $expiresAt->toDateTimeString(),
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

    protected function makeFingerprint(int $customerId, ?string $agent = null, ?string $ip = null): string
    {
        $agent = trim((string) $agent);
        $ip = trim((string) $ip);

        return hash('sha256', implode('|', [
            'clone_tts',
            $customerId,
            $agent,
            $ip,
            config('app.key'),
        ]));
    }
}