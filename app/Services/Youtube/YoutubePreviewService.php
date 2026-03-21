<?php

namespace App\Services\Youtube;

use App\Services\Youtube\Concerns\InteractsWithYoutubeWorker;
use Illuminate\Support\Facades\Process;

class YoutubePreviewService
{
    use InteractsWithYoutubeWorker;

    protected function cookieArguments(): array
    {
        return [];
    }

    public function preview(string $url): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $raw = $this->runWorker([
            'preview',
            '--url',
            trim($url),
        ], 180);

        $data = $this->decodeJson($raw, 'preview');

        if (($data['ok'] ?? false) !== true) {
            throw new \RuntimeException($this->friendlyWorkerError((string) ($data['error'] ?? 'Preview failed.')));
        }

        return array_merge($data['data'] ?? [], [
            'available_audio_formats' => ['mp3', 'wav'],
            'available_video_formats' => ['mp4'],
            'available_video_qualities' => ['p480', 'p720', 'p1080', 'p4k'],
        ]);
    }

    protected function runWorker(array $arguments, int $timeoutSeconds): string
    {
        $result = Process::path(base_path())
            ->env($this->processEnvironment())
            ->timeout($timeoutSeconds)
            ->run($this->workerCommand($arguments));

        $raw = trim($result->output());
        $error = trim($result->errorOutput());

        if (! $result->successful()) {
            $data = $this->tryDecodeJson($raw);

            if (is_array($data) && array_key_exists('error', $data)) {
                throw new \RuntimeException($this->friendlyWorkerError((string) $data['error']));
            }

            $message = $raw !== '' ? $raw : ($error !== '' ? $error : 'Preview command failed.');

            throw new \RuntimeException($this->friendlyWorkerError($message));
        }

        return $raw;
    }
}
