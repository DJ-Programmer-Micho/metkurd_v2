<?php

namespace App\Services\MetKurd\V2;

use App\Models\CustomerFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/** Customer-scoped read cache for the CTTS workspace. */
class CttsWorkspaceCache
{
    private const REFERENCE_CODES = ['clone_tts', 'clone_xomni', 'vector-v2', 'theta'];

    /** @return array<int, array<string, mixed>> */
    public function references(int $customerId): array
    {
        return Cache::remember($this->referenceKey($customerId), now()->addSeconds($this->referenceTtl()), function () use ($customerId): array {
            return CustomerFile::query()
                ->where('customer_id', $customerId)
                ->where('status', 'active')
                ->where('purpose', 'reference')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereIn('tool_code', self::REFERENCE_CODES)
                ->latest('updated_at')
                ->get(['id', 'disk', 'path', 'size_bytes', 'mime', 'meta', 'updated_at'])
                ->filter(fn (CustomerFile $file): bool => str_starts_with(strtolower((string) $file->mime), 'audio/')
                    && (string) data_get($file->meta, 'role', 'speaker_reference') === 'speaker_reference')
                ->unique('path')
                ->map(fn (CustomerFile $file): array => [
                    'id' => (int) $file->id,
                    'disk' => (string) $file->disk,
                    'path' => (string) $file->path,
                    'name' => (string) (data_get($file->meta, 'original_name') ?: basename((string) $file->path)),
                    'bytes' => (int) $file->size_bytes,
                    'mime' => (string) $file->mime,
                    'duration' => data_get($file->meta, 'duration'),
                    'updated_at' => $file->updated_at?->toIso8601String(),
                    'model' => (string) data_get($file->meta, 'model_key', ''),
                ])
                ->values()
                ->all();
        });
    }

    public function forgetReferences(int $customerId): void
    {
        Cache::forget($this->referenceKey($customerId));
    }

    /** @param callable(): LengthAwarePaginator $resolver */
    public function recentRenders(int $customerId, string $toolCode, int $page, bool $hasActiveJobs, callable $resolver): LengthAwarePaginator
    {
        if ($hasActiveJobs) {
            return $resolver();
        }

        return Cache::remember(
            $this->renderKey($customerId, $toolCode, $page),
            now()->addSeconds($this->renderTtl()),
            $resolver,
        );
    }

    public function forgetRenders(int $customerId, string $toolCode): void
    {
        $versionKey = $this->renderVersionKey($customerId, $toolCode);
        Cache::forever($versionKey, ((int) Cache::get($versionKey, 1)) + 1);
    }

    private function referenceKey(int $customerId): string
    {
        return "metkurd:v2:ctts:references:customer:{$customerId}";
    }

    private function renderKey(int $customerId, string $toolCode, int $page): string
    {
        $version = max(1, (int) Cache::get($this->renderVersionKey($customerId, $toolCode), 1));

        return "metkurd:v2:ctts:renders:customer:{$customerId}:tool:".strtolower($toolCode).":page:{$page}:v{$version}";
    }

    private function renderVersionKey(int $customerId, string $toolCode): string
    {
        return "metkurd:v2:ctts:renders:customer:{$customerId}:tool:".strtolower($toolCode).':version';
    }

    private function referenceTtl(): int
    {
        return max(60, (int) config('metkurd_v2.cache.ctts_reference_ttl_seconds', 600));
    }

    private function renderTtl(): int
    {
        return max(5, (int) config('metkurd_v2.cache.ctts_completed_render_ttl_seconds', 20));
    }
}
