<?php

namespace App\Services\MetKurd\V2;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/** Read cache for completed Leo transcriptions only; active jobs stay live. */
class LeoWorkspaceCache
{
    /** @param callable(): LengthAwarePaginator $resolver */
    public function recentTranscriptions(int $customerId, int $page, bool $hasActiveJobs, callable $resolver): LengthAwarePaginator
    {
        if ($hasActiveJobs) {
            return $resolver();
        }

        return Cache::remember($this->renderKey($customerId, $page), now()->addSeconds($this->ttl()), $resolver);
    }

    public function forgetTranscriptions(int $customerId): void
    {
        $versionKey = $this->versionKey($customerId);
        Cache::forever($versionKey, ((int) Cache::get($versionKey, 1)) + 1);
    }

    private function renderKey(int $customerId, int $page): string
    {
        $version = max(1, (int) Cache::get($this->versionKey($customerId), 1));

        return "metkurd:v2:leo:renders:customer:{$customerId}:page:{$page}:v{$version}";
    }

    private function versionKey(int $customerId): string
    {
        return "metkurd:v2:leo:renders:customer:{$customerId}:version";
    }

    private function ttl(): int
    {
        return max(5, (int) config('metkurd_v2.cache.leo_completed_render_ttl_seconds', 20));
    }
}
