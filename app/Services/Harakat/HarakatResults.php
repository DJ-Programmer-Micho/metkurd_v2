<?php

namespace App\Services\Harakat;

use App\Models\CustomerFile;
use App\Models\MlJob;

class HarakatResults
{
    public function file(?MlJob $job): ?CustomerFile
    {
        if (! $job || $job->status !== 'done' || $job->job_kind !== 'harakat' || data_get($job->input, 'api_job_id')) {
            return null;
        }

        return CustomerFile::query()->where('customer_id', $job->customer_id)->where('tool_code', 'harakat')
            ->where('source_type', 'ml_job')->where('source_id', $job->id)->where('status', 'active')
            ->where('disk', data_get($job->output, 'disk'))->where('path', data_get($job->output, 'path'))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
    }
}
