<?php

use App\Services\OCR\OcrDocumentProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

it('counts PDF pages on the server and deduplicates bounded page ranges', function () {
    Process::fake(['*' => Process::result(output: "Pages: 12\n", exitCode: 0)]);
    $probe = app(OcrDocumentProbe::class);
    $count = $probe->pageCount(UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'));
    expect($count)->toBe(12)->and($probe->selectedPages($count, '1-3,3,8'))->toBe([1, 2, 3, 8]);
    Process::assertRan(fn ($process) => is_array($process->command) && count($process->command) === 2);
});

it('probes a shared-disk upload through a bounded local temporary file and removes it', function () {
    $upload = UploadedFile::fake()->create('remote.pdf', 1, 'application/pdf');
    $remote = new class($upload->getRealPath(), 'remote.pdf', 'application/pdf', null, true) extends UploadedFile
    {
        public function getRealPath(): string|false
        {
            return false;
        }

        public function readStream()
        {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, '%PDF-fixture');
            rewind($stream);

            return $stream;
        }
    };
    $temporaryPath = null;
    Process::fake(function ($process) use (&$temporaryPath) {
        $temporaryPath = $process->command[1];
        expect(file_get_contents($temporaryPath))->toBe('%PDF-fixture');

        return Process::result(output: "Pages: 3\n", exitCode: 0);
    });
    expect(app(OcrDocumentProbe::class)->pageCount($remote))->toBe(3);
    expect(is_file($temporaryPath))->toBeFalse();
});

it('rejects unverified PDFs before submission', function ($output, $exit) {
    Process::fake(['*' => Process::result(output: $output, exitCode: $exit)]);
    expect(fn () => app(OcrDocumentProbe::class)->pageCount(UploadedFile::fake()->create('document.pdf', 1, 'application/pdf')))->toThrow(RuntimeException::class);
})->with([['', 1], ['Pages: 0', 0], ['Pages: 4000', 0], ['unexpected', 0]]);

it('rejects invalid or excessive ranges before allocating pages', function ($range) {
    expect(fn () => app(OcrDocumentProbe::class)->selectedPages(12, $range))->toThrow(RuntimeException::class);
})->with(['1-9999999999', '0', '12-3', '3,bad', '13']);
