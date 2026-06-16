<?php

namespace App\Http\Controllers\Api\Customer\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiJob;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Services\CustomerApi\CustomerApiFileLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class CustomerApiController extends Controller
{
    protected function customer(Request $request): Customer
    {
        $customer = $request->user();

        abort_unless($customer instanceof Customer, 401, 'Unauthorized.');

        return $customer;
    }

    protected function apiKey(Request $request): CustomerApiKey
    {
        $apiKey = $request->attributes->get('customerApiKey');

        abort_unless($apiKey instanceof CustomerApiKey, 401, 'Unauthorized.');

        return $apiKey;
    }

    protected function success(array $payload = [], int $status = 200): JsonResponse
    {
        return response()->json(array_merge(['success' => true], $payload), $status);
    }

    protected function error(string $message, string $code, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], $extra), $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function jobPayload(ApiJob $job, CustomerApiFileLinkService $files): array
    {
        $job->loadMissing(['resultFiles.storageFile']);
        $result = $job->resultFiles->first(function ($resultFile): bool {
            return $resultFile->deleted_at === null
                && $resultFile->storageFile !== null
                && (string) $resultFile->storageFile->status !== 'deleted'
                && $resultFile->storageFile->deleted_at === null;
        });

        return array_filter([
            'job_id' => (string) $job->id,
            'status' => (string) $job->status,
            'estimated_credits' => (int) $job->estimated_credits,
            'credits_charged' => $job->status === 'completed' ? (int) $job->final_credits : null,
            'storage' => [
                'mode' => (string) $job->storage_mode,
                'expires_in_days' => $job->storage_mode === 'temporary'
                    ? (int) config('customer_api.temporary_file_ttl_days', 7)
                    : null,
            ],
            'result' => $result ? $files->resultPayload($result) : null,
            'message' => $job->status === 'failed' ? (string) ($job->error_message ?: 'Job failed.') : null,
            'error_code' => $job->status === 'failed' ? (string) ($job->error_code ?: 'job_failed') : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function submissionError(\Throwable $e): JsonResponse
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Idempotency-Key') => $this->error($message, 'idempotency_conflict', 409),
            str_contains($message, 'Not enough credits') => $this->error('Insufficient credits.', 'insufficient_credits', 402),
            str_contains($message, 'concurrent API job limit') => $this->error($message, 'concurrency_limit_exceeded', 409),
            str_contains($message, 'not authorized for the requested scope') => $this->error($message, 'scope_forbidden', 403),
            str_contains($message, 'does not allow this API scope') => $this->error($message, 'scope_forbidden', 403),
            str_contains($message, 'does not allow this tool') => $this->error($message, 'scope_forbidden', 403),
            str_contains($message, 'Pricing is not configured') => $this->error($message, 'pricing_unavailable', 422),
            str_contains($message, 'already being transcribed') => $this->error($message, 'concurrency_limit_exceeded', 409),
            str_contains($message, 'already being processed') => $this->error($message, 'concurrency_limit_exceeded', 409),
            str_contains($message, 'already being separated') => $this->error($message, 'concurrency_limit_exceeded', 409),
            str_contains($message, 'already running on another browser or machine') => $this->error($message, 'concurrency_limit_exceeded', 409),
            str_contains($message, 'Could not calculate billing') => $this->error($message, 'pricing_unavailable', 422),
            default => $this->error($message !== '' ? $message : 'Request failed.', 'request_failed', 422),
        };
    }
}
