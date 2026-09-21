<?php

namespace App\Http\Controllers\App\Services;

class ThetaRenderController extends XttsRenderController
{
    protected string $toolCode = 'theta';

    protected string $streamFailureLog = 'THETA_STREAM_FAIL';

    protected function jobOrFail(string $jobId): \App\Models\MlJob
    {
        abort_unless(\App\Models\Tool::query()->where('code', $this->toolCode)->exists(), 404);
        $job = parent::jobOrFail($jobId);
        abort_unless((string) $job->tool->code === $this->toolCode, 404);
        $file = \App\Models\CustomerFile::query()->where('customer_id', $job->customer_id)
            ->where('disk', data_get($job->output, 'disk'))->where('path', data_get($job->output, 'path'))->firstOrFail();
        abort_unless($file->status === 'active' && (! $file->expires_at || $file->expires_at->isFuture()), 404);

        return $job;
    }
}
