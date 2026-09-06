<?php

namespace App\Services\OCR;

use App\Models\MlJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates customer-requested V2 OCR exports when a worker response contains
 * final text but does not include every selected binary artifact.
 */
class OcrV2ArtifactService
{
    /** @return array<string, array{path:string, bytes:int, mime:string}> */
    public function persistSelectedArtifacts(MlJob $job, string $disk, string $text, array $existing = []): array
    {
        if ($text === '') {
            return $existing;
        }

        $exports = (array) data_get($job->input, 'exports', []);
        $paths = $this->pathsFor($job);
        $contents = [];

        if (($exports['export_docx'] ?? false) && ! isset($existing['docx'])) {
            $contents['docx'] = $this->docx($text);
        }
        if (($exports['export_markdown'] ?? false) && ! isset($existing['markdown'])) {
            $contents['markdown'] = $text;
        }
        if (($exports['export_html'] ?? false) && ! isset($existing['html'])) {
            $contents['html'] = $this->html($text, (string) data_get($job->input, 'file_name', 'OCR result'));
        }

        foreach ($contents as $format => $content) {
            if (Storage::disk($disk)->put($paths[$format]['path'], $content, ['ContentType' => $paths[$format]['mime']]) !== true) {
                throw new \App\Services\Storage\StorageObjectUnavailable('The customer result could not be persisted.');
            }
            $existing[$format] = $this->stored($disk, $paths[$format]);
        }

        if (($exports['export_zip'] ?? false) && ! isset($existing['zip'])) {
            $archiveEntries = ['ocr-result.txt' => $text];
            foreach (['docx', 'markdown', 'html'] as $format) {
                if (isset($contents[$format])) {
                    $archiveEntries[basename($paths[$format]['path'])] = $contents[$format];
                } elseif (isset($existing[$format]) && Storage::disk($disk)->exists((string) $existing[$format]['path'])) {
                    $archiveEntries[basename((string) $existing[$format]['path'])] = Storage::disk($disk)->get((string) $existing[$format]['path']);
                }
            }
            $zip = $this->zip($archiveEntries);
            if (Storage::disk($disk)->put($paths['zip']['path'], $zip, ['ContentType' => $paths['zip']['mime']]) !== true) {
                throw new \App\Services\Storage\StorageObjectUnavailable('The customer result could not be persisted.');
            }
            $existing['zip'] = $this->stored($disk, $paths['zip']);
        }

        return $existing;
    }

    /**
     * Persist binary exports returned inline by the upgraded OCR worker.
     *
     * @param  array{docx_base64?: mixed, html?: mixed}  $workerOutput
     * @return array<string, array{path:string, bytes:int, mime:string}>
     */
    public function persistWorkerArtifacts(MlJob $job, string $disk, array $workerOutput): array
    {
        $exports = (array) data_get($job->input, 'exports', []);
        $paths = $this->pathsFor($job);
        $stored = [];

        if (($exports['export_docx'] ?? false) && is_string($workerOutput['docx_base64'] ?? null)) {
            $docx = base64_decode((string) $workerOutput['docx_base64'], true);
            if ($docx !== false && $docx !== '') {
                if (Storage::disk($disk)->put($paths['docx']['path'], $docx, ['ContentType' => $paths['docx']['mime']]) !== true) {
                    throw new \App\Services\Storage\StorageObjectUnavailable('The customer result could not be persisted.');
                }
                $stored['docx'] = $this->stored($disk, $paths['docx']);
            }
        }

        if (($exports['export_html'] ?? false) && is_string($workerOutput['html'] ?? null) && $workerOutput['html'] !== '') {
            if (Storage::disk($disk)->put($paths['html']['path'], (string) $workerOutput['html'], ['ContentType' => $paths['html']['mime']]) !== true) {
                throw new \App\Services\Storage\StorageObjectUnavailable('The customer result could not be persisted.');
            }
            $stored['html'] = $this->stored($disk, $paths['html']);
        }

        return $stored;
    }

    /** @return array<string, array{path:string, mime:string}> */
    private function pathsFor(MlJob $job): array
    {
        $inputPath = str_replace('\\', '/', (string) data_get($job->input, 'file_path', ''));
        $base = trim(dirname($inputPath), '/.');
        $base = $base !== '' ? $base : 'ocr/'.(string) $job->id;
        $fileName = pathinfo((string) data_get($job->input, 'file_name', ''), PATHINFO_FILENAME);
        $stem = Str::slug($fileName, '_') ?: 'ocr-result';
        $prefix = $base.'/exports/'.$stem;

        return [
            'docx' => ['path' => $prefix.'.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'markdown' => ['path' => $prefix.'.md', 'mime' => 'text/markdown; charset=UTF-8'],
            'html' => ['path' => $prefix.'.html', 'mime' => 'text/html; charset=UTF-8'],
            'zip' => ['path' => $prefix.'.zip', 'mime' => 'application/zip'],
        ];
    }

    /** @param array{path:string, mime:string} $artifact @return array{path:string, bytes:int, mime:string} */
    private function stored(string $disk, array $artifact): array
    {
        return [
            'path' => $artifact['path'],
            'bytes' => (int) Storage::disk($disk)->size($artifact['path']),
            'mime' => $artifact['mime'],
        ];
    }

    private function html(string $text, string $title): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>{$safeTitle}</title></head><body><pre>{$safeText}</pre></body></html>";
    }

    private function docx(string $text): string
    {
        $xml = '';
        foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
            $line = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $line) ?? '';
            $xml .= '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</w:t></w:r></w:p>';
        }

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$xml.'<w:sectPr/></w:body></w:document>',
        ]);
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'metkurd-ocr-');
        if ($path === false) {
            throw new \RuntimeException('Unable to prepare OCR export.');
        }

        try {
            $archive = new \ZipArchive;
            if ($archive->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create OCR export archive.');
            }
            foreach ($entries as $name => $contents) {
                $archive->addFromString($name, $contents);
            }
            $archive->close();
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read OCR export archive.');
            }

            return $contents;
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
