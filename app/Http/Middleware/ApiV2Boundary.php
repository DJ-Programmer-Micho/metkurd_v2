<?php

namespace App\Http\Middleware;

use App\Services\CustomerApi\V2\ApiProblem;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ApiV2Boundary
{
    public const MESSAGES = [
        'invalid_request' => 'Check the supplied fields.', 'authentication_failed' => 'A valid API key is required.',
        'permission_denied' => 'This request is not permitted.', 'insufficient_credits' => 'Insufficient API credits.',
        'rate_limit_exceeded' => 'Too many requests. Try again later.', 'invalid_file' => 'The supplied file is invalid.',
        'unsupported_format' => 'The file format is not supported.', 'job_not_found' => 'The requested job was not found.',
        'file_not_found' => 'The requested file was not found or has expired.', 'processing_failed' => 'Processing failed.',
        'storage_limit_exceeded' => 'The storage limit was exceeded.', 'server_error' => 'The request could not be completed.',
        'idempotency_conflict' => 'This idempotency key was used for a different request.',
        'concurrency_limit_exceeded' => 'The concurrent job limit was reached.',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (! config('customer_api.v2_enabled')) {
            return $this->error('invalid_request', 404);
        }
        try {
            if ($request->isJson() && $request->getContent() !== '') {
                try {
                    json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return $this->error('invalid_request', 400);
                }
            }
            // Separate failure-only bucket; authenticated traffic retains plan-based limits.
            $authRate = 'api-v2-auth:'.hash('sha256', (string) $request->ip());
            if (RateLimiter::tooManyAttempts($authRate, (int) config('customer_api.v2_auth_failures_per_minute', 60))) {
                return $this->error('rate_limit_exceeded', 429, [], 60);
            }
            $response = $next($request);
            if ($response->getStatusCode() === 401) {
                RateLimiter::hit($authRate, 60);
            }
            if ($response->getStatusCode() >= 400) {
                $status = $response->getStatusCode();
                if ($response instanceof JsonResponse && isset(self::MESSAGES[$response->getData(true)['error']['code'] ?? ''])) {
                    return $response;
                }
                $code = match ($status) {
                    401 => 'authentication_failed', 403 => 'permission_denied', 404 => 'job_not_found', 429 => 'rate_limit_exceeded', default => 'server_error'
                };

                return $this->error($code, $status, [], $status === 429 ? 60 : null);
            }
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        } catch (ApiProblem $e) {
            return $this->error($e->errorCode, $e->status);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (\Throwable $e) {
            if ($e instanceof HttpExceptionInterface) {
                return $this->error('invalid_request', $e->getStatusCode());
            }
            report($e);

            return $this->error('server_error', 500);
        }
    }

    public function renderException(\Throwable $e): JsonResponse
    {
        if ($e instanceof ApiProblem) {
            return $this->error($e->errorCode, $e->status);
        }
        if ($e instanceof ValidationException) {
            return $this->validationError($e);
        }
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        return $this->error($status >= 500 ? 'server_error' : 'invalid_request', $status);
    }

    private function error(string $code, int $status, array $fields = [], ?int $retry = null): JsonResponse
    {
        return response()->json(['error' => array_filter(['code' => $code, 'message' => self::MESSAGES[$code] ?? self::MESSAGES['server_error'], 'fields' => $fields])], $status,
            array_filter(['Cache-Control' => 'private, no-store', 'Retry-After' => $retry]));
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        $code = 'invalid_request';
        foreach ($e->validator->failed() as $rules) {
            if (isset($rules['Mimes']) || isset($rules['Mimetypes'])) {
                $code = 'unsupported_format';
            }
        }
        if ($code === 'invalid_request' && array_intersect(['file', 'audioFile', 'documentFile', 'reference_id'], array_keys($e->errors()))) {
            $code = 'invalid_file';
        }

        return $this->error($code, 422, array_keys($e->errors()));
    }
}
