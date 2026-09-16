<?php

namespace App\Domain\Payments\Support;

use Illuminate\Support\Carbon;

final class FibSubscriptionTimestamp
{
    public static function parse(mixed $value): ?Carbon
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $value = trim((string) $value);
        try {
            // Numeric values have one contract: Unix milliseconds, never seconds.
            // Bound evidence to this application's supported MySQL TIMESTAMP era.
            if (preg_match('/^\d{12,13}$/D', $value)) {
                $date = Carbon::createFromTimestampMs($value, 'UTC');
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)?)?$/D', $value, $parts)
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                && (int) ($parts[4] ?? 0) < 24 && (int) ($parts[5] ?? 0) < 60 && (int) ($parts[6] ?? 0) < 60) {
                // Legacy ISO/SQL date strings without an offset are explicitly UTC.
                $date = Carbon::parse($value, 'UTC')->utc();
            } else {
                return null;
            }

            return $date->getTimestamp() >= 946684800 && $date->getTimestamp() <= 2147483647
                ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
