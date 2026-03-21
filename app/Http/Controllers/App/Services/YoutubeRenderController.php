<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\Youtube\YoutubeOutputStorage;
use Illuminate\Http\Request;

class YoutubeRenderController extends Controller
{
    protected function jobOrFail(string $jobId): MlJob
    {
        $toolIds = Tool::query()
            ->whereIn('code', ['youtube_audio', 'youtube_video'])
            ->pluck('id')
            ->all();

        return MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->when(! empty($toolIds), fn ($q) => $q->whereIn('tool_id', $toolIds))
            ->where('status', 'done')
            ->firstOrFail();
    }

    public function download(Request $request, string $locale, string $jobId, YoutubeOutputStorage $storage)
    {
        $job = $this->jobOrFail($jobId);

        abort_if($storage->isExpired($job), 410, 'Prepared download expired.');

        $disk = (string) data_get($job->output, 'disk', '');
        $path = (string) data_get($job->output, 'path', '');
        $fileName = (string) data_get($job->output, 'download_name', data_get($job->output, 'file_name', 'download.bin'));
        $mime = (string) data_get($job->output, 'mime', 'application/octet-stream');

        abort_if($disk === '' || $path === '', 404, 'Prepared download missing.');
        abort_unless($storage->storedOutputExists($disk, $path), 404, 'Prepared download file was not found.');

        $job->update([
            'output' => array_replace((array) $job->output, [
                'browser_download_opened_at' => now()->toDateTimeString(),
            ]),
        ]);

        if (! $request->boolean('proxy')) {
            $temporaryUrl = $storage->temporaryUrl($disk, $path, $fileName, $mime);

            if ($temporaryUrl !== null) {
                return redirect()->away($temporaryUrl);
            }
        }

        $stream = $storage->storedOutputStream($disk, $path);
        abort_unless($stream, 500, 'Unable to open prepared download.');

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $fileName, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300, stale-while-revalidate=60',
        ]);
    }
}
