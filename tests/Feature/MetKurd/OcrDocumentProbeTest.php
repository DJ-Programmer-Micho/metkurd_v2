<?php

use App\Services\OCR\OcrDocumentProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

it('counts PDF pages on the server and deduplicates bounded page ranges', function () {
    Process::fake(['*' => Process::result(output: "Pages: 12\n", exitCode: 0)]);
    $probe = app(OcrDocumentProbe::class);
    $count = $probe->pageCount(\Tests\Support\PdfFixture::upload(12));
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
            fwrite($stream, \Tests\Support\PdfFixture::content(3));
            rewind($stream);

            return $stream;
        }
    };
    $temporaryPath = null;
    Process::fake(function ($process) use (&$temporaryPath) {
        $temporaryPath = $process->command[1];
        expect(file_get_contents($temporaryPath))->toBe(\Tests\Support\PdfFixture::content(3));

        return Process::result(output: "Pages: 3\n", exitCode: 0);
    });
    expect(app(OcrDocumentProbe::class)->pageCount($remote))->toBe(3);
    clearstatcache(true, $temporaryPath);
    expect(is_file($temporaryPath))->toBeFalse();
});

it('falls back to structural page objects when pdfinfo is unavailable for digital or scanned PDFs', function (int $pages, bool $scanned) {
    Process::fake(['*' => Process::result(errorOutput: 'pdfinfo unavailable', exitCode: 127)]);
    $probe = app(OcrDocumentProbe::class);
    $count = $probe->pageCount(\Tests\Support\PdfFixture::upload($pages, $scanned));
    expect($count)->toBe($pages)->and($probe->selectedPages($count, 'all'))->toBe(range(1, min(20, $pages)));
})->with([[1, false], [7, false], [10, false], [20, false], [100, false], [10, true]]);

it('keeps unsupported page counts unknown while bounding requested pages', function () {
    Process::fake(['*' => Process::result(output: 'Unavailable', exitCode: 1)]);
    $probe = new class extends OcrDocumentProbe
    {
        protected function structuralPageCount(string $path): ?int
        {
            return null;
        }
    };
    expect($probe->pageCount(\Tests\Support\PdfFixture::upload(10, true)))->toBeNull()
        ->and($probe->selectedPages(null, 'all'))->toBe(range(1, 20))
        ->and($probe->selectedPages(null, '21-40'))->toBe(range(21, 40));
    expect(fn () => $probe->selectedPages(null, '1-21'))->toThrow(RuntimeException::class);
});

it('rejects clearly corrupt input and password blocked PDFs with specific errors', function () {
    $probe = app(OcrDocumentProbe::class);
    expect(fn () => $probe->pageCount(UploadedFile::fake()->createWithContent('bad.pdf', 'not a PDF')))
        ->toThrow(RuntimeException::class, 'This PDF is invalid or corrupt. Please upload a valid PDF.');
    Process::fake(['*' => Process::result(errorOutput: 'Command Line Error: Incorrect password', exitCode: 1)]);
    expect(fn () => $probe->pageCount(\Tests\Support\PdfFixture::upload(1)))
        ->toThrow(RuntimeException::class, 'This PDF is password-protected. Please upload an unlocked PDF.');
});

it('rejects oversized selections regardless of whether the total is known', function (?int $total) {
    $probe = app(OcrDocumentProbe::class);
    foreach (['1-21', '1-20,21', '1-9999999999'] as $range) {
        expect(fn () => $probe->selectedPages($total, $range))->toThrow(RuntimeException::class);
    }
    expect($probe->selectedPages($total, '21-40'))->toBe(range(21, 40));
})->with([100, null]);

it('rejects invalid or excessive ranges before allocating pages', function ($range) {
    expect(fn () => app(OcrDocumentProbe::class)->selectedPages(12, $range))->toThrow(RuntimeException::class);
})->with(['1-9999999999', '0', '12-3', '3,bad', '13']);
