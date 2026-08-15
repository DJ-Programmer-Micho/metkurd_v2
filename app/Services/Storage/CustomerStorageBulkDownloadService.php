<?php

namespace App\Services\Storage;

use App\Models\Customer;
use App\Models\CustomerFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerStorageBulkDownloadService
{
    /** @param array<int, int|string> $fileIds @return array{path: string, filename: string} */
    public function createArchive(Customer $customer, array $fileIds): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Bulk archives are not available on this server.');
        }

        $files = $this->ownedFiles($customer, $fileIds);
        $this->assertWithinLimits($files);

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'metkurd-storage-'.Str::uuid();
        File::ensureDirectoryExists($directory, 0700, true);
        $archivePath = $directory.DIRECTORY_SEPARATOR.'storage.zip';
        $zip = new \ZipArchive;

        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            File::deleteDirectory($directory);
            throw new \RuntimeException('The selected files could not be prepared for download.');
        }

        try {
            foreach ($files as $file) {
                $this->addFile($zip, $file, $directory);
            }

            $zip->close();
        } catch (\Throwable $exception) {
            $zip->close();
            File::deleteDirectory($directory);
            throw $exception;
        }

        return [
            'path' => $archivePath,
            'filename' => 'metkurd-storage-'.now()->format('Y-m-d').'.zip',
        ];
    }

    /** @param array<int, int|string> $fileIds @return \Illuminate\Support\Collection<int, CustomerFile> */
    protected function ownedFiles(Customer $customer, array $fileIds)
    {
        $ids = collect($fileIds)
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new \InvalidArgumentException('Select at least one file.');
        }

        $files = CustomerFile::query()
            ->where('customer_id', (int) $customer->id)
            ->where('status', 'active')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($files->count() !== $ids->count()) {
            throw new \InvalidArgumentException('One or more selected files are unavailable.');
        }

        return $files;
    }

    protected function assertWithinLimits($files): void
    {
        $maxFiles = (int) config('filesystems.customer_outputs.bulk_download_max_files', 25);
        $maxBytes = (int) config('filesystems.customer_outputs.bulk_download_max_bytes', 104857600);
        $bytes = (int) $files->sum(fn (CustomerFile $file) => max(0, (int) $file->size_bytes));

        if ($files->count() > $maxFiles) {
            throw new \InvalidArgumentException('Too many files were selected for one download.');
        }

        if ($bytes > $maxBytes) {
            throw new \InvalidArgumentException('The selected files are too large to download together.');
        }
    }

    protected function addFile(\ZipArchive $zip, CustomerFile $file, string $directory): void
    {
        $disk = (string) ($file->disk ?: 's3');
        $path = trim((string) $file->path);

        try {
            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                throw new StorageObjectUnavailable('This file is no longer available in storage.');
            }

            $stream = Storage::disk($disk)->readStream($path);
            if (! is_resource($stream)) {
                throw new StorageObjectUnavailable('This file is no longer available in storage.');
            }

            $temporaryPath = $directory.DIRECTORY_SEPARATOR.'file-'.(int) $file->id;
            $target = fopen($temporaryPath, 'wb');
            if (! is_resource($target)) {
                fclose($stream);
                throw new \RuntimeException('The selected files could not be prepared for download.');
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            $entry = $this->archiveEntryName($file);
            if (! $zip->addFile($temporaryPath, $entry)) {
                throw new \RuntimeException('The selected files could not be prepared for download.');
            }
        } catch (\Throwable $exception) {
            Log::warning('CUSTOMER_STORAGE_BULK_DOWNLOAD_ITEM_FAILED', [
                'customer_id' => (int) $file->customer_id,
                'customer_file_id' => (int) $file->id,
                'disk' => $disk,
                'operation' => 'bulk_download',
                'error_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    protected function archiveEntryName(CustomerFile $file): string
    {
        $basename = basename(str_replace('\\', '/', (string) $file->path));
        $basename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $basename) ?: 'file';

        return (int) $file->id.'-'.$basename;
    }
}
