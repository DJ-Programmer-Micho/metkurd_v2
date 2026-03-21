<?php

namespace App\Services\Youtube;

use App\Services\Youtube\Concerns\InteractsWithYoutubeWorker;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

class YoutubeDownloadCliService
{
    use InteractsWithYoutubeWorker;

    protected function cookieArguments(): array
    {
        return [];
    }

    public function __construct(
        protected YoutubeOutputStorage $storage,
    ) {}

    public function startDownload(
        string $url,
        string $mode,
        string $format,
        string $quality,
        string $jobId
    ): InvokedProcess {
        $workspace = $this->prepareWorkspace($jobId);

        return Process::path(base_path())
            ->env($this->processEnvironment())
            ->forever()
            ->start($this->workerCommand([
                'download',
                '--url',
                trim($url),
                '--mode',
                $mode,
                '--format',
                $format,
                '--quality',
                $quality,
                '--outdir',
                $workspace['temp_dir'],
                '--progress-file',
                $workspace['progress_file'],
                '--progress-log',
                $workspace['progress_log'],
            ]));
    }

    public function decodeDownloadResult(ProcessResult $result): array
    {
        $raw = trim($result->output());
        $error = trim($result->errorOutput());

        if (! $result->successful()) {
            $data = $this->tryDecodeJson($raw);

            if (is_array($data) && array_key_exists('error', $data)) {
                throw new \RuntimeException($this->friendlyWorkerError((string) $data['error']));
            }

            $message = $raw !== '' ? $raw : ($error !== '' ? $error : 'Download command failed.');

            throw new \RuntimeException($this->friendlyWorkerError($message));
        }

        $data = $this->decodeJson($raw, 'download');

        if (($data['ok'] ?? false) !== true) {
            throw new \RuntimeException($this->friendlyWorkerError((string) ($data['error'] ?? 'Download failed.')));
        }

        return $data['data'] ?? [];
    }

    public function prepareWorkspace(string $jobId): array
    {
        $tempDir = $this->storage->localTempDir($jobId);

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        return [
            'temp_dir' => $tempDir,
            'progress_file' => $this->storage->progressSnapshotPath($jobId),
            'progress_log' => $this->storage->progressLogPath($jobId),
        ];
    }

    public function progressState(string $jobId, int $logLimit = 10): array
    {
        return [
            'snapshot' => $this->storage->readProgressSnapshot($jobId),
            'log_lines' => $this->storage->readProgressLog($jobId, $logLimit),
        ];
    }
}
