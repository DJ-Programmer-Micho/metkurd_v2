<?php

namespace App\Support\Admin;

final class AdminData
{
    public const REDACTED = '[redacted]';

    public static function redact(mixed $value, string $key = ''): mixed
    {
        if (in_array($key, ['callback_payload', 'status_response', 'request_payload', 'response_payload'], true)) {
            $decoded = is_array($value) ? $value : json_decode((string) $value, true);

            return self::diagnostics(is_array($decoded) ? $decoded : []);
        }
        if (preg_match('/secret|password|passwd|token|authorization|credential|cookie|private.?key|api.?key|signed|signature|client.?key/i', $key)) {
            return self::REDACTED;
        }
        if (is_array($value)) {
            foreach ($value as $k => $item) {
                $value[$k] = self::redact($item, (string) $k);
            }

            return $value;
        }
        if (is_string($value)) {
            // JSON nested inside string fields must not bypass recursive filtering.
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return json_encode(self::redact($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (preg_match('~(?:https?://|Bearer\s|Basic\s|-----BEGIN .*PRIVATE KEY|\b(?:secret|token|password|authorization|credential|api[_-]?key)\s*[:=])~i', $value)) {
                return self::REDACTED;
            }
        }

        return is_scalar($value) || $value === null ? $value : self::REDACTED;
    }

    /** Provider diagnostics expose only known operational fields, never arbitrary payloads. */
    public static function diagnostics(?array $payload): array
    {
        $allowed = ['id', 'paymentId', 'subscriptionId', 'status', 'paymentStatus', 'code', 'errorCode',
            'createdAt', 'paidAt', 'lastPaymentAt', 'activeUntil', 'validUntil', 'failure_stage'];

        return self::redact(array_intersect_key($payload ?? [], array_flip($allowed)));
    }

    /** Masked settings round trips preserve the server copy, including omitted protected keys. */
    public static function mergeEditable(array $original, array $edited): array
    {
        foreach ($original as $key => $value) {
            if (self::redact($value, (string) $key) === self::REDACTED) {
                $edited[$key] = $value;
            } elseif (is_array($value)) {
                $edited[$key] = self::mergeEditable($value, is_array($edited[$key] ?? null) ? $edited[$key] : []);
            }
        }

        return $edited;
    }
}
