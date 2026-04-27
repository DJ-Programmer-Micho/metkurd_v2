<?php

namespace App\Support\Landing;

use App\Models\MlJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LandingPublicMetrics
{
    protected const CACHE_KEY = 'landing-public-metrics:v1';

    protected const CACHE_TTL_MINUTES = 10;

    /**
     * @var array<string, array{title: string, copy: string, job_kinds: array<int, string>}>
     */
    protected const METRIC_DEFINITIONS = [
        'generated_audio' => [
            'title' => 'Generated Audio',
            'copy' => 'Finished Apollo, Delta, and Vector jobs',
            'job_kinds' => ['tts', 'ftts', 'clone_tts'],
        ],
        'transcribed' => [
            'title' => 'Transcribed',
            'copy' => 'Finished WASR NEO and QASR LEO',
            'job_kinds' => ['wasr', 'qasr'],
        ],
        'ocr_pages' => [
            'title' => 'OCR Pages',
            'copy' => 'Finished OCR jobs',
            'job_kinds' => ['ocr'],
        ],
        'translated' => [
            'title' => 'Translated',
            'copy' => 'Finished translation jobs',
            'job_kinds' => ['tran'],
        ],
    ];

    /**
     * @return array<int, array{key: string, title: string, value: string, copy: string, raw_value: int}>
     */
    public function cards(): array
    {
        $counts = $this->counts();

        $cards = [];

        foreach (self::METRIC_DEFINITIONS as $key => $definition) {
            $count = (int) ($counts[$key] ?? 0);

            $cards[] = [
                'key' => $key,
                'title' => __($definition['title']),
                'value' => $this->formatCompact($count),
                'copy' => __($definition['copy']),
                'raw_value' => $count,
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        try {
            /** @var array<string, int> $counts */
            $counts = Cache::remember(
                self::CACHE_KEY,
                now()->addMinutes(self::CACHE_TTL_MINUTES),
                fn (): array => $this->queryCounts()
            );

            return $counts;
        } catch (\Throwable $e) {
            Log::warning('Landing public metrics query failed.', [
                'message' => $e->getMessage(),
            ]);

            return $this->emptyCounts();
        }
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function formatCompact(int $value): string
    {
        $value = max(0, $value);

        if ($value < 1000) {
            return (string) $value;
        }

        $units = ['K', 'M', 'B', 'T', 'P'];
        $scaled = (float) $value;
        $unit = '';

        foreach ($units as $candidate) {
            $scaled /= 1000;
            $unit = $candidate;

            if ($scaled < 1000) {
                break;
            }
        }

        $display = $scaled >= 100
            ? (string) floor($scaled)
            : number_format(floor($scaled * 10) / 10, 1, '.', '');

        $display = rtrim(rtrim($display, '0'), '.');

        return $display . $unit;
    }

    /**
     * @return array<string, int>
     */
    protected function queryCounts(): array
    {
        $jobKinds = collect(self::METRIC_DEFINITIONS)
            ->pluck('job_kinds')
            ->flatten()
            ->unique()
            ->values()
            ->all();

        $grouped = MlJob::query()
            ->where('status', 'done')
            ->whereIn('job_kind', $jobKinds)
            ->selectRaw('job_kind, COUNT(*) as aggregate_count')
            ->groupBy('job_kind')
            ->pluck('aggregate_count', 'job_kind');

        $counts = [];

        foreach (self::METRIC_DEFINITIONS as $key => $definition) {
            $counts[$key] = collect($definition['job_kinds'])
                ->sum(fn (string $jobKind): int => (int) ($grouped[$jobKind] ?? 0));
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    protected function emptyCounts(): array
    {
        $counts = [];

        foreach (array_keys(self::METRIC_DEFINITIONS) as $key) {
            $counts[$key] = 0;
        }

        return $counts;
    }
}
