<?php

namespace App\Http\Controllers\Api\Customer\V1;

use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\CustomerApiFileLinkService;
use App\Services\CustomerApi\CustomerApiJobSubmissionService;
use App\Services\CustomerApi\CustomerApiScopeService;
use App\Services\CustomerApi\CustomerApiVoiceCatalog;
use Illuminate\Http\Request;

class TtsController extends CustomerApiController
{
    public function __construct(
        protected CustomerApiVoiceCatalog $voices,
        protected CustomerApiJobSubmissionService $jobs,
        protected CustomerApiFileLinkService $files,
        protected CustomerApiAccessService $access,
        protected CustomerApiScopeService $scopes,
    ) {}

    public function apollo10Voices(Request $request)
    {
        return $this->voicesResponse($request, 'tts:apollo-1-0v', 'xtts');
    }

    public function apollo15Voices(Request $request)
    {
        return $this->voicesResponse($request, 'tts:apollo-1-5v', 'xomni');
    }

    public function delta10Voices(Request $request)
    {
        return $this->voicesResponse($request, 'tts:delta-1-0v', 'ftts');
    }

    public function apollo10(Request $request)
    {
        return $this->submitProduct($request, 'apollo-1-0v');
    }

    public function apollo15(Request $request)
    {
        return $this->submitProduct($request, 'apollo-1-5v');
    }

    public function delta10(Request $request)
    {
        return $this->submitProduct($request, 'delta-1-0v');
    }

    public function vector10(Request $request)
    {
        return $this->submitProduct($request, 'vector-1-0');
    }

    public function vector15(Request $request)
    {
        return $this->submitProduct($request, 'vector-1-5');
    }

    public function xttsVoices(Request $request)
    {
        return $this->apollo10Voices($request);
    }

    public function xomniVoices(Request $request)
    {
        return $this->apollo15Voices($request);
    }

    public function f5ttsVoices(Request $request)
    {
        return $this->delta10Voices($request);
    }

    public function xtts(Request $request)
    {
        return $this->apollo10($request);
    }

    public function xomni(Request $request)
    {
        return $this->apollo15($request);
    }

    public function f5tts(Request $request)
    {
        return $this->delta10($request);
    }

    public function cloneXtts(Request $request)
    {
        return $this->vector10($request);
    }

    public function cloneXomni(Request $request)
    {
        return $this->vector15($request);
    }

    protected function voicesResponse(Request $request, string $scope, string $engine)
    {
        $customer = $this->customer($request);
        $apiKey = $this->apiKey($request);

        if (! $this->scopes->hasScope($apiKey, $scope)) {
            return $this->error('This API key is not authorized for the requested scope.', 'scope_forbidden', 403);
        }

        if (! $this->access->allowsScope($customer, $scope)) {
            return $this->error('Your current plan does not allow this API scope.', 'scope_forbidden', 403);
        }

        $voices = $this->voices->voicesForCustomer($customer, $engine)
            ->map(fn ($voice): array => [
                'speaker_id' => (string) $voice->code,
                'name' => (string) $voice->name,
            ])
            ->values()
            ->all();

        return $this->success(['voices' => $voices]);
    }

    protected function submitProduct(Request $request, string $product)
    {
        try {
            $result = $this->jobs->submitTtsProduct($request, $this->customer($request), $this->apiKey($request), $product);
        } catch (\Throwable $e) {
            return $this->submissionError($e);
        }

        return $this->success(
            $this->jobPayload($result['api_job'], $this->files),
            $result['created'] ? 202 : 200
        );
    }
}
