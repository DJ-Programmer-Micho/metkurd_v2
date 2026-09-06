<?php

namespace App\Services\OCR;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

class OcrDocumentProbe
{
    public function pageCount(UploadedFile $file): int
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'pdf' && $file->getMimeType() !== 'application/pdf') {
            return 1;
        }
        $source = $temporary = null;
        try {
            $path = $file->getRealPath();
            if (! $path || ! is_file($path)) {
                // Livewire may keep temporary uploads on a shared remote disk.
                $source = method_exists($file, 'readStream') ? $file->readStream() : null;
                $temporary = tmpfile();
                if (! is_resource($source) || ! is_resource($temporary)) {
                    throw new \RuntimeException('Unable to read the temporary PDF.');
                }
                $copied = stream_copy_to_stream($source, $temporary, 104857601);
                if (! $copied || $copied > 104857600) {
                    throw new \RuntimeException('Invalid PDF upload size.');
                }
                fflush($temporary);
                $path = stream_get_meta_data($temporary)['uri'];
            }
            $result = Process::timeout(30)->env(['LC_ALL' => 'C'])->run([
                (string) config('metkurd_v2.ocr_pdfinfo_binary', 'pdfinfo'), $path,
            ]);
            if ($result->successful() && preg_match('/^Pages:\s+(\d+)\s*$/m', $result->output(), $match)) {
                $pages = (int) $match[1];
                if ($pages > 0 && $pages <= 3888) {
                    return $pages;
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($temporary)) {
                fclose($temporary);
            }
        }
        throw new \RuntimeException(__('The PDF page count could not be verified. Please upload a readable PDF.'));
    }

    /** Validate before expanding ranges, then send exactly the pages being billed. */
    public function selectedPages(int $totalPages, string $range): array
    {
        if ($range === 'all') {
            return range(1, $totalPages);
        }
        if (strlen($range) > 255) {
            throw new \RuntimeException(__('Enter pages like 1-5,8,10-12.'));
        }
        $pages = [];
        foreach (explode(',', $range) as $part) {
            if (! preg_match('/^\s*(\d+)(?:\s*-\s*(\d+))?\s*$/', $part, $match)) {
                throw new \RuntimeException(__('Enter pages like 1-5,8,10-12.'));
            }
            $first = (int) $match[1];
            $last = isset($match[2]) ? (int) $match[2] : $first;
            if ($first < 1 || $last < $first || $last > $totalPages) {
                throw new \RuntimeException(__('Choose at least one valid page.'));
            }
            array_push($pages, ...range($first, $last));
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }
}
