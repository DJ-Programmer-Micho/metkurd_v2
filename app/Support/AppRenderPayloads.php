<?php

namespace App\Support;

use App\Models\MlJob;

class AppRenderPayloads
{
    /**
     * @return array<int, string>
     */
    public static function stemTracks(int $mode): array
    {
        return $mode === 2
            ? ['original', 'vocals', 'instrumental']
            : ['original', 'vocals', 'drums', 'bass', 'other'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stem(MlJob $job, ?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        $mode = (int) (data_get($job->meta, 'separation_mode') ?: data_get($job->input, 'stems', 4));
        $tracks = self::stemTracks($mode);

        $streams = [];
        $downloads = [];

        foreach ($tracks as $track) {
            $streams[$track] = route('app.renders.stem.stream', [
                'locale' => $locale,
                'jobId' => (string) $job->id,
                'track' => $track,
            ]) . '?proxy=1';

            $downloads[$track] = route('app.renders.stem.download', [
                'locale' => $locale,
                'jobId' => (string) $job->id,
                'track' => $track,
            ]);
        }

        $downloads['all'] = route('app.renders.stem.zip', [
            'locale' => $locale,
            'jobId' => (string) $job->id,
        ]);

        return [
            'id' => (string) $job->id,
            'mode' => $mode,
            'tracks' => $tracks,
            'stems' => $streams,
            'downloads' => $downloads,
            'meta' => (array) ($job->meta ?? []),
            'input_name' => (string) data_get($job->input, 'audio_name', __('Untitled audio')),
            'created_at' => optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i'),
            'created_at_human' => optional($job->finished_at ?? $job->created_at)->diffForHumans(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stemSummary(MlJob $job, ?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        $jobId = (string) $job->id;
        $mode = (int) (data_get($job->meta, 'separation_mode') ?: data_get($job->input, 'stems', 4));

        return [
            'id' => $jobId,
            'mode' => $mode,
            'downloads' => [
                'all' => route('app.renders.stem.zip', [
                    'locale' => $locale,
                    'jobId' => $jobId,
                ]),
            ],
            'input_name' => (string) data_get($job->input, 'audio_name', __('Untitled audio')),
            'created_at' => optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i'),
            'created_at_human' => optional($job->finished_at ?? $job->created_at)->diffForHumans(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ocr(MlJob $job, ?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        $textPath = (string) data_get($job->output, 'text.path', '');
        $jsonPath = (string) data_get($job->output, 'json.path', '');
        $inputPath = (string) data_get($job->input, 'file_path', '');
        $jobId = (string) $job->id;

        return [
            'id' => $jobId,
            'input_name' => (string) data_get($job->input, 'file_name', __('Untitled PDF')),
            'input_url' => $inputPath !== '' ? route('app.renders.ocr.input', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) . '?proxy=1' : null,
            'page_range' => (string) data_get($job->input, 'page_range', ''),
            'pages' => (int) data_get($job->input, 'pages_estimated', 0),
            'lang' => (string) data_get($job->input, 'lang', 'ckb'),
            'dpi' => (int) data_get($job->input, 'dpi', 200),
            'psm' => (int) data_get($job->input, 'psm', 6),
            'oem' => (int) data_get($job->input, 'oem', 3),
            'normalize' => (bool) data_get($job->input, 'normalize', false),
            'text_view_url' => $textPath !== '' ? route('app.renders.ocr.text.view', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) . '?proxy=1' : null,
            'text_download_url' => $textPath !== '' ? route('app.renders.ocr.text', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) : null,
            'json_view_url' => $jsonPath !== '' ? route('app.renders.ocr.json.view', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) . '?proxy=1' : null,
            'json_download_url' => $jsonPath !== '' ? route('app.renders.ocr.json', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) : null,
            'created_at' => optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i'),
            'created_at_human' => optional($job->finished_at ?? $job->created_at)->diffForHumans(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ocrSummary(MlJob $job, ?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        $jobId = (string) $job->id;
        $textPath = (string) data_get($job->output, 'text.path', '');
        $jsonPath = (string) data_get($job->output, 'json.path', '');

        return [
            'id' => $jobId,
            'input_name' => (string) data_get($job->input, 'file_name', __('Untitled PDF')),
            'page_range' => (string) data_get($job->input, 'page_range', ''),
            'lang' => (string) data_get($job->input, 'lang', 'ckb'),
            'dpi' => (int) data_get($job->input, 'dpi', 200),
            'psm' => (int) data_get($job->input, 'psm', 6),
            'oem' => (int) data_get($job->input, 'oem', 3),
            'text_download_url' => $textPath !== '' ? route('app.renders.ocr.text', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) : null,
            'json_view_url' => $jsonPath !== '' ? route('app.renders.ocr.json.view', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) . '?proxy=1' : null,
            'created_at' => optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i'),
            'created_at_human' => optional($job->finished_at ?? $job->created_at)->diffForHumans(),
        ];
    }
}
