<?php

namespace App\Services\OCR;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

class OcrDocumentProbe
{
    public const MAX_PAGES = 20;

    public function pageCount(UploadedFile $file): ?int
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
            $stream = fopen($path, 'rb');
            try {
                $header = fread($stream, 1024);
                fseek($stream, max(0, filesize($path) - 65536));
                $tail = stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
            if (! preg_match('/%PDF-[12]\.\d/', $header) || ! str_contains($tail, '%%EOF')) {
                throw new \RuntimeException(__('This PDF is invalid or corrupt. Please upload a valid PDF.'));
            }

            $diagnostic = '';
            try {
                $result = Process::timeout(30)->env(['LC_ALL' => 'C'])->run([
                    (string) config('metkurd_v2.ocr_pdfinfo_binary', 'pdfinfo'), $path,
                ]);
                $diagnostic = $result->errorOutput();
                if (preg_match('/incorrect password|password required/i', $diagnostic)) {
                    throw new \DomainException;
                }
                if ($result->successful() && preg_match('/^Pages:\s+(\d+)\s*$/m', $result->output(), $match) && (int) $match[1] > 0) {
                    return (int) $match[1];
                }
            } catch (\DomainException) {
                throw new \RuntimeException(__('This PDF is password-protected. Please upload an unlocked PDF.'));
            } catch (\Throwable) {
                // Missing pdfinfo / timeout is not evidence of an invalid PDF.
            }
            try {
                return $this->structuralPageCount($path);
            } catch (\Throwable $exception) {
                if (str_contains($exception->getMessage(), 'Secured pdf file')) {
                    throw new \RuntimeException(__('This PDF is password-protected. Please upload an unlocked PDF.'));
                }
                if (in_array($exception->getMessage(), ['Missing catalog.', 'Unable to find startxref', 'Unable to find xref', 'Unable to find xref (PDF corrupted?)', 'Unable to find trailer', 'Object list not found. Possible secured file.'], true) || preg_match('/may not be a PDF|couldn.t (?:find trailer|read xref)|invalid page count/i', $diagnostic)) {
                    throw new \RuntimeException(__('This PDF is invalid or corrupt. Please upload a valid PDF.'));
                }

                // An unsupported parser feature is not a fabricated total or a
                // reason to reject a structurally plausible scanned document.
                return null;
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($temporary)) {
                fclose($temporary);
            }
        }

    }

    /** Count page objects only; text extraction is never an acceptance condition. */
    protected function structuralPageCount(string $path): ?int
    {
        $config = new \Smalot\PdfParser\Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(8 * 1024 * 1024);
        $document = (new \Smalot\PdfParser\Parser([], $config))->parseFile($path);
        $count = count($document->getPages());

        return $count > 0 ? $count : null;
    }

    /** Validate before expanding ranges, then send exactly the pages being billed. */
    public function selectedPages(?int $totalPages, string $range): array
    {
        if ($totalPages !== null && $totalPages < 1) {
            throw new \RuntimeException(__('Choose at least one valid page.'));
        }
        if ($range === 'all') {
            return range(1, min(self::MAX_PAGES, $totalPages ?? self::MAX_PAGES));
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
            if ($first < 1 || $last < $first || ($totalPages !== null && $last > $totalPages)) {
                throw new \RuntimeException(__('Choose at least one valid page.'));
            }
            if ($last - $first + 1 > self::MAX_PAGES) {
                throw new \RuntimeException(__('Choose between 1 and 20 pages per OCR job.'));
            }
            $pages = array_values(array_unique(array_merge($pages, range($first, $last))));
            if (count($pages) > self::MAX_PAGES) {
                throw new \RuntimeException(__('Choose between 1 and 20 pages per OCR job.'));
            }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }
}
