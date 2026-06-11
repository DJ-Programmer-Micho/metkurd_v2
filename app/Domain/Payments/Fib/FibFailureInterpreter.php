<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Exceptions\FibApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;

class FibFailureInterpreter
{
    /**
     * @return array<string, mixed>
     */
    public function describe(
        \Throwable $exception,
        string $providerReferenceType,
        string $providerReference,
        string $source = 'system',
        ?string $endpoint = null,
        ?string $profile = null,
    ): array {
        $httpStatus = $this->httpStatus($exception);
        $fibErrorCode = $this->fibErrorCode($exception);
        $fibErrorTitle = $this->fibErrorTitle($exception);
        $fibTraceId = $this->fibTraceId($exception);
        $transient = $this->isTransient($exception, $httpStatus, $fibErrorCode);
        $permanent = ! $transient && in_array($httpStatus, [400, 401, 403, 404, 422], true);
        $safeMessage = $this->safeMessage($exception, $providerReferenceType, $httpStatus, $fibErrorCode);

        return [
            'http_status' => $httpStatus,
            'fib_error_code' => $fibErrorCode,
            'fib_error_title' => $fibErrorTitle,
            'fib_trace_id' => $fibTraceId,
            'provider_reference_type' => $providerReferenceType,
            'provider_reference' => $providerReference,
            'endpoint' => $endpoint,
            'profile' => $profile,
            'source' => $source,
            'safe_message' => $safeMessage,
            'display_error' => $this->displayError($httpStatus, $fibErrorCode, $fibErrorTitle, $exception),
            'transient' => $transient,
            'permanent' => $permanent,
            'review_after_failures' => $this->reviewThreshold($httpStatus, $fibErrorCode),
            'event_interval_hours' => $permanent ? 24 : 6,
        ];
    }

    protected function httpStatus(\Throwable $exception): ?int
    {
        if ($exception instanceof FibApiException) {
            $status = (int) $exception->getCode();

            return $status > 0 ? $status : null;
        }

        if ($exception instanceof RequestException) {
            return $exception->response?->status();
        }

        return null;
    }

    protected function fibErrorCode(\Throwable $exception): ?string
    {
        if ($exception instanceof FibApiException) {
            $code = trim((string) data_get($exception->errorCodes(), '0', ''));

            if ($code !== '') {
                return $code;
            }

            return $this->stringOrNull(data_get($exception->payload(), 'error'));
        }

        return null;
    }

    protected function fibErrorTitle(\Throwable $exception): ?string
    {
        if ($exception instanceof FibApiException) {
            foreach ([
                data_get($exception->payload(), 'errors.0.title'),
                data_get($exception->payload(), 'message'),
                data_get($exception->payload(), 'error_description'),
                data_get($exception->payload(), 'detail'),
                data_get($exception->payload(), 'error'),
            ] as $candidate) {
                $value = $this->stringOrNull($candidate);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return $this->stringOrNull($exception->getMessage());
    }

    protected function fibTraceId(\Throwable $exception): ?string
    {
        if ($exception instanceof FibApiException) {
            return $exception->traceId();
        }

        return null;
    }

    protected function isTransient(\Throwable $exception, ?int $httpStatus, ?string $fibErrorCode): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($httpStatus !== null && ($httpStatus >= 500 || $httpStatus === 429)) {
            return true;
        }

        $message = Str::lower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
            return true;
        }

        return in_array(strtoupper((string) $fibErrorCode), ['TIMEOUT', 'TOO_MANY_REQUESTS'], true);
    }

    protected function safeMessage(
        \Throwable $exception,
        string $providerReferenceType,
        ?int $httpStatus,
        ?string $fibErrorCode,
    ): string {
        $providerLabel = $providerReferenceType === 'subscription' ? 'subscription ID' : 'payment ID';
        $upperCode = strtoupper((string) $fibErrorCode);
        $message = Str::lower($exception->getMessage());

        if ($httpStatus === 404 || $upperCode === 'NOT_FOUND_ERROR') {
            return sprintf(
                'FIB returned NOT_FOUND for this %s. Do not fulfill automatically. Verify whether the paid transaction belongs to a different FIB payment/transaction/subscription reference.',
                $providerLabel
            );
        }

        if ($httpStatus === 400 || $upperCode === 'INVALID_REQUEST') {
            return 'The provider reference was rejected by FIB. This may not be the API paymentId/subscriptionId expected by the FIB status endpoint. If you copied a Transaction ID from FIB Business, look for the API paymentId created by MetKurd or check whether FIB supports transaction-ID lookup.';
        }

        if (in_array($httpStatus, [401, 403], true) || str_contains($message, 'jwt issuer is not configured')) {
            return 'FIB authentication/environment mismatch detected. Check local .env FIB base URL, client ID, client secret, grant type, and whether you are using sandbox credentials against production IDs or production credentials against sandbox IDs.';
        }

        if ($httpStatus === 422) {
            return 'FIB rejected this lookup as semantically invalid. Do not fulfill automatically until a matching paid provider transaction is verified.';
        }

        if ($httpStatus === 429) {
            return 'FIB rate-limited this lookup. Retry later instead of changing customer entitlement.';
        }

        if ($httpStatus !== null && $httpStatus >= 500) {
            return 'FIB returned a server error for this lookup. Retry later instead of changing customer entitlement.';
        }

        if ($exception instanceof ConnectionException || str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
            return 'The FIB lookup timed out or the network request failed. Retry later instead of changing customer entitlement.';
        }

        return 'FIB lookup failed for this provider reference. Do not fulfill automatically until a matching paid provider transaction is verified.';
    }

    protected function displayError(?int $httpStatus, ?string $fibErrorCode, ?string $fibErrorTitle, \Throwable $exception): string
    {
        $parts = [];

        if ($httpStatus !== null) {
            $parts[] = 'HTTP '.$httpStatus;
        }

        if ($fibErrorCode !== null) {
            $parts[] = $fibErrorCode;
        } elseif ($fibErrorTitle !== null) {
            $parts[] = $fibErrorTitle;
        } else {
            $parts[] = trim($exception->getMessage());
        }

        return trim(implode(' ', array_filter($parts)));
    }

    protected function reviewThreshold(?int $httpStatus, ?string $fibErrorCode): int
    {
        if (in_array($httpStatus, [401, 403], true)) {
            return 2;
        }

        if (in_array($httpStatus, [400, 404, 422], true) || in_array(strtoupper((string) $fibErrorCode), ['INVALID_REQUEST', 'NOT_FOUND_ERROR'], true)) {
            return 3;
        }

        return 0;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
