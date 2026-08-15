<?php

namespace App\Http\Controllers\App;

use App\Models\CustomerFile;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\Storage\CustomerStorageBulkDownloadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class V2StorageFileController
{
    public function download(Request $request, string $locale, CustomerFile $file, CustomerOutputStorage $storage): RedirectResponse
    {
        $customer = $request->user('app');
        abort_unless($customer && (int) $file->customer_id === (int) $customer->id && (string) $file->status === 'active', 404);

        $url = $storage->temporaryUrlForCustomerFile($file, 'attachment');
        if ($url === null) {
            return redirect()
                ->route('app.v2.storage', ['locale' => app()->getLocale()])
                ->with('storage_error', __('This file is no longer available in storage.'));
        }

        return redirect()->away($url);
    }

    public function bulkDownload(Request $request, string $locale, CustomerStorageBulkDownloadService $archives)
    {
        $customer = $request->user('app');
        abort_unless($customer, 403);

        try {
            $archive = $archives->createArchive($customer, (array) $request->query('files', []));

            return response()->streamDownload(function () use ($archive): void {
                try {
                    $stream = fopen($archive['path'], 'rb');
                    if (is_resource($stream)) {
                        fpassthru($stream);
                        fclose($stream);
                    }
                } finally {
                    File::deleteDirectory(dirname($archive['path']));
                }
            }, $archive['filename'], ['Content-Type' => 'application/zip']);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('app.v2.storage', ['locale' => app()->getLocale()])
                ->with('storage_error', __($exception->getMessage()));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('app.v2.storage', ['locale' => app()->getLocale()])
                ->with('storage_error', __('We could not prepare this download. Please try again.'));
        }
    }
}
