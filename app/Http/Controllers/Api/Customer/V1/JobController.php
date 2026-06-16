<?php

namespace App\Http\Controllers\Api\Customer\V1;

use App\Models\ApiJob;
use App\Services\CustomerApi\CustomerApiFileLinkService;
use App\Services\CustomerApi\CustomerApiJobSyncService;
use Illuminate\Http\Request;

class JobController extends CustomerApiController
{
    public function __construct(
        protected CustomerApiJobSyncService $jobs,
        protected CustomerApiFileLinkService $files,
    ) {}

    public function show(Request $request, string $job)
    {
        $apiJob = ApiJob::query()
            ->where('customer_id', (int) $this->customer($request)->id)
            ->where('id', $job)
            ->first();

        if (! $apiJob instanceof ApiJob) {
            return $this->error('Job not found.', 'job_not_found', 404);
        }

        return $this->success($this->jobPayload($this->jobs->refresh($apiJob), $this->files));
    }
}
