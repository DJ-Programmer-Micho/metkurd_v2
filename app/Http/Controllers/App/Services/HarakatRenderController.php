<?php

namespace App\Http\Controllers\App\Services;

use App\Models\MlJob;
use App\Services\Harakat\HarakatResults;
use Illuminate\Support\Facades\Storage;

class HarakatRenderController
{
    public function downloadTxt(string $locale, string $jobId)
    {
        $job = MlJob::query()->where('customer_id', auth('app')->id())->where('job_kind', 'harakat')
            ->whereHas('tool', fn ($query) => $query->where('code', 'harakat')->where('is_active', true))
            ->whereHas('toolAction', fn ($query) => $query->where('full_code', 'harakat.diacritize')->where('is_active', true))
            ->whereNull('input->api_job_id')->findOrFail($jobId);
        $file = app(HarakatResults::class)->file($job);
        abort_unless($file && Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, 'harakat.txt', [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
