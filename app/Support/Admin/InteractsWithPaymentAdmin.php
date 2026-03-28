<?php

namespace App\Support\Admin;

use Illuminate\Validation\ValidationException;

trait InteractsWithPaymentAdmin
{
    protected function decodeJsonTextarea(?string $value, string $field): ?array
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => __('Enter valid JSON data.'),
            ]);
        }

        return $decoded;
    }

    protected function encodeJsonTextarea($value): string
    {
        if (!$value) {
            return '';
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function formatMoney($value): string
    {
        return '$' . number_format((float) ($value ?? 0), 2);
    }

    public function formatCredits($value): string
    {
        return number_format((int) round((float) ($value ?? 0)));
    }

    public function formatStorageQuota($quotaMb): string
    {
        $quotaMb = max(0, (int) ($quotaMb ?? 0));

        if ($quotaMb >= 1024) {
            $quotaGb = $quotaMb / 1024;

            return number_format($quotaGb, $quotaGb >= 10 ? 0 : 1) . ' GB';
        }

        return number_format($quotaMb) . ' MB';
    }
}
