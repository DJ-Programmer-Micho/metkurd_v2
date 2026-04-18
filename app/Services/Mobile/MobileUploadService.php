<?php

namespace App\Services\Mobile;

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Services\Storage\CustomerOutputStorage;
use App\Support\CustomerFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class MobileUploadService
{
    public function __construct(
        protected CustomerOutputStorage $storage,
        protected MobileAppCatalog $catalog,
    ) {
    }

    public function store(Customer $customer, string $appSlug, UploadedFile $file): CustomerFile
    {
        $customer->loadMissing('profile');

        $folder = CustomerFolder::make(
            (int) $customer->id,
            $customer->profile?->first_name ?? $customer->first_name ?? null,
            $customer->profile?->last_name ?? $customer->last_name ?? null,
            $customer->username ?? null
        );

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin'));
        $baseName = Str::slug(pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME), '-');
        $baseName = $baseName !== '' ? Str::limit($baseName, 80, '') : 'upload';
        $uuid = (string) Str::uuid();
        $path = "renders/{$folder}/mobile/{$appSlug}/uploads/{$uuid}/{$baseName}.{$extension}";

        $this->storage->saveUploadedFileToS3((int) $customer->id, $file, $path, [
            'tool' => $this->catalog->primaryToolCode($appSlug),
            'purpose' => (string) data_get($this->catalog->uploadConfig($appSlug), 'purpose', 'input_upload'),
            'mobile_app' => $appSlug,
            'original_name' => $file->getClientOriginalName(),
        ]);

        return CustomerFile::query()
            ->where('customer_id', (int) $customer->id)
            ->where('path', $path)
            ->latest('id')
            ->firstOrFail();
    }
}
