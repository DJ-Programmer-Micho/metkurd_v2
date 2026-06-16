<?php

namespace App\Http\Controllers\Api\Customer\V1;

use App\Services\CustomerApi\CustomerApiFileLinkService;
use App\Services\CustomerApi\CustomerApiJobSubmissionService;
use Illuminate\Http\Request;

class StemController extends CustomerApiController
{
    public function __construct(
        protected CustomerApiJobSubmissionService $jobs,
        protected CustomerApiFileLinkService $files,
    ) {}

    public function __invoke(Request $request)
    {
        try {
            $result = $this->jobs->submitStem($request, $this->customer($request), $this->apiKey($request));
        } catch (\Throwable $e) {
            return $this->submissionError($e);
        }

        return $this->success($this->jobPayload($result['api_job'], $this->files), $result['created'] ? 202 : 200);
    }
}
