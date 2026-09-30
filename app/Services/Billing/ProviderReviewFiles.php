<?php

namespace App\Services\Billing;

/** Private local files only. No storage disks, public URLs, arbitrary paths or overwrites. */
class ProviderReviewFiles
{
    public function write(array $value, string $kind): string
    {
        $dir = $this->directory();
        $name = 'provider-obligation-'.$kind.'-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        $handle = fopen($path, 'x');
        if (! $handle) {
            throw new PaymentHistoryResetRefused('Private export could not be created.');
        }
        chmod($path, 0600);
        try {
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            if (fwrite($handle, $json) !== strlen($json)) {
                throw new PaymentHistoryResetRefused('Private export was incomplete.');
            }
        } finally {
            fclose($handle);
        }

        if ($kind === 'source') {
            chmod($path, 0400);
        }

        return $path;
    }

    public function read(string $path): array
    {
        $real = realpath($path);
        $dir = $this->directory();
        if (! $real || is_link($path) || dirname($real) !== $dir || ! is_file($real)
            || pathinfo($real, PATHINFO_EXTENSION) !== 'json' || filesize($real) > 10 * 1024 * 1024) {
            throw new PaymentHistoryResetRefused('Use a bounded JSON review file directly inside private billing storage.');
        }

        return json_decode(file_get_contents($real), true, 64, JSON_THROW_ON_ERROR);
    }

    private function directory(): string
    {
        $path = storage_path('app/private/billing');
        if (is_link(storage_path('app/private')) || is_link($path)) {
            throw new PaymentHistoryResetRefused('Private review storage must not be a symlink.');
        }
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new PaymentHistoryResetRefused('Private review storage is unavailable.');
        }
        $real = realpath($path);
        if (! $real || str_starts_with($real, realpath(public_path()).DIRECTORY_SEPARATOR)) {
            throw new PaymentHistoryResetRefused('Public review storage is refused.');
        }

        return $real;
    }
}
