<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\Youtube\YoutubeOutputStorage;

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

    public function download(string $locale, string $jobId, YoutubeOutputStorage $storage)
    {
        $job = $this->jobOrFail($jobId);

        $path = (string) data_get($job->output, 'local_file_path', '');
        $fileName = (string) data_get($job->output, 'download_name', data_get($job->output, 'file_name', 'download.bin'));
        $mime = (string) data_get($job->output, 'mime', 'application/octet-stream');

        abort_if($path === '', 404);

        if (! $storage->outputExists($path)) {
            abort(404);
        }

        $job->update([
            'output' => array_merge((array) $job->output, [
                'browser_download_opened_at' => now()->toDateTimeString(),
            ]),
        ]);

        return response()->download($path, $fileName, [
            'Content-Type' => $mime,
        ]);
    }
}
