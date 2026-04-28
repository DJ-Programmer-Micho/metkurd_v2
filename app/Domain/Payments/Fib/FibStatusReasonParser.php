<?php

namespace App\Domain\Payments\Fib;

class FibStatusReasonParser
{
    public function reasonFromRaw(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'decliningReason'),
            data_get($payload, 'declineReason'),
            data_get($payload, 'failureReason'),
            data_get($payload, 'reason'),
            data_get($payload, 'message'),
            data_get($payload, 'detail'),
            data_get($payload, 'error'),
            data_get($payload, 'error_description'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);

            if ($value !== '') {
                return $value;
            }
        }

        $errors = data_get($payload, 'errors', []);

        if (! is_array($errors)) {
            return null;
        }

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $parts = array_filter([
                $this->stringOrNull(data_get($error, 'code')),
                $this->stringOrNull(data_get($error, 'title')),
                $this->stringOrNull(data_get($error, 'detail'))
                    ?? $this->stringOrNull(data_get($error, 'details'))
                    ?? $this->stringOrNull(data_get($error, 'message')),
            ]);

            if ($parts !== []) {
                return implode(' - ', $parts);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function errorCodesFromRaw(array $payload): array
    {
        $errors = data_get($payload, 'errors', []);

        if (! is_array($errors)) {
            return [];
        }

        $codes = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = $this->stringOrNull(data_get($error, 'code'));

            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

