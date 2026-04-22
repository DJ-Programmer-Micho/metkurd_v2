<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

class FibApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        protected array $payload = [],
        protected array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function traceId(): ?string
    {
        $traceId = trim((string) data_get($this->payload, 'traceId', ''));

        return $traceId !== '' ? $traceId : null;
    }

    /**
     * @return array<int, string>
     */
    public function errorCodes(): array
    {
        $errors = data_get($this->payload, 'errors', []);
        $codes = [];

        if (! is_array($errors)) {
            return [];
        }

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = trim((string) data_get($error, 'code', ''));

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    public function hasErrorCode(string $code): bool
    {
        return in_array(strtoupper(trim($code)), array_map('strtoupper', $this->errorCodes()), true);
    }
}
