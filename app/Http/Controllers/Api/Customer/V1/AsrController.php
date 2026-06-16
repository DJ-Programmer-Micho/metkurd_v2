<?php

namespace App\Http\Controllers\Api\Customer\V1;

use App\Services\CustomerApi\CustomerApiFileLinkService;
use App\Services\CustomerApi\CustomerApiJobSubmissionService;
use Illuminate\Http\Request;

class AsrController extends CustomerApiController
{
    public function __construct(
        protected CustomerApiJobSubmissionService $jobs,
        protected CustomerApiFileLinkService $files,
    ) {}

    public function wasr(Request $request)
    {
        try {
            $result = $this->jobs->submitWasr($request, $this->customer($request), $this->apiKey($request));
        } catch (\Throwable $e) {
            return $this->submissionError($e);
        }

        return $this->success($this->jobPayload($result['api_job'], $this->files), $result['created'] ? 202 : 200);
    }

    public function qasr(Request $request)
    {
        try {
            $result = $this->jobs->submitQasr($request, $this->customer($request), $this->apiKey($request));
        } catch (\Throwable $e) {
            return $this->submissionError($e);
        }

        return $this->success($this->jobPayload($result['api_job'], $this->files), $result['created'] ? 202 : 200);
    }
}
