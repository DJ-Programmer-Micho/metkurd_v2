<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StorageBrowser
{
    protected function customerFolder(Customer $customer): string
    {
        $customer->loadMissing('profile');

        return CustomerFolder::make(
            (int) $customer->id,
            $customer->profile?->first_name ?? $customer->first_name ?? null,
            $customer->profile?->last_name ?? $customer->last_name ?? null,
            $customer->username ?? null
        );
    }

    protected function basePrefix(Customer $customer): string
    {
        return 'renders/' . $this->customerFolder($customer);
    }

    protected function absolutePath(Customer $customer, string $relativePath = ''): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        return $relativePath === ''
            ? $this->basePrefix($customer)
            : $this->basePrefix($customer) . '/' . $relativePath;
    }

    protected function baseQuery(Customer $customer)
    {
        return CustomerFile::query()
            ->where('customer_id', (int) $customer->id)
            ->where('status', 'active');
    }

    protected function extractRelativePath(string $absolutePath): string
    {
        $absolutePath = str_replace('\\', '/', trim($absolutePath));

        if (preg_match('#^renders/[^/]+/(.+)$#', $absolutePath, $matches)) {
            return trim($matches[1], '/');
        }

        return trim($absolutePath, '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapFile(CustomerFile $file): array
    {
        $relative = $this->extractRelativePath((string) $file->path);

        return [
            'id' => (int) $file->id,
            'customer_id' => (int) $file->customer_id,
            'tool_code' => (string) ($file->tool_code ?? ''),
            'disk' => (string) ($file->disk ?? 's3'),
            'path' => (string) $file->path,
            'relative_path' => $relative,
            'size_bytes' => (int) ($file->size_bytes ?? 0),
            'mime' => (string) ($file->mime ?? 'application/octet-stream'),
            'purpose' => (string) ($file->purpose ?? 'render'),
            'meta' => (array) ($file->meta ?? []),
            'created_at' => $file->created_at,
            'updated_at' => $file->updated_at,
            'basename' => basename($relative ?: $file->path),
            'extension' => strtolower(pathinfo($relative ?: $file->path, PATHINFO_EXTENSION)),
        ];
    }

    public function pathExists(Customer $customer, string $relativePath): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '') {
            return true;
        }

        $absolute = $this->absolutePath($customer, $relativePath);

        return $this->baseQuery($customer)
            ->where(function ($query) use ($absolute) {
                $query->where('path', $absolute)
                    ->orWhere('path', 'like', $absolute . '/%');
            })
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFileByRelativePath(Customer $customer, string $relativePath): ?array
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '') {
            return null;
        }

        $row = $this->baseQuery($customer)
            ->where('path', $this->absolutePath($customer, $relativePath))
            ->first([
                'id',
                'customer_id',
                'tool_code',
                'disk',
                'path',
                'size_bytes',
                'mime',
                'purpose',
                'meta',
                'created_at',
                'updated_at',
            ]);

        return $row ? $this->mapFile($row) : null;
    }

    /**
     * @param  array<string, array<string, string>>  $toolRoots
     * @return array<string, array<string, int>>
     */
    public function toolStats(Customer $customer, array $toolRoots): array
    {
        $toolKeys = array_keys($toolRoots);
        $stats = [];

        $aggregates = $this->baseQuery($customer)
            ->whereIn('tool_code', $toolKeys)
            ->selectRaw('tool_code, COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_size')
            ->groupBy('tool_code')
            ->get()
            ->keyBy(fn ($row) => (string) $row->tool_code);

        foreach ($toolKeys as $tool) {
            $paths = $this->baseQuery($customer)
                ->where('tool_code', $tool)
                ->pluck('path');

            $folderCount = collect($paths)
                ->map(function ($path) use ($tool) {
                    $relativePath = $this->extractRelativePath((string) $path);
                    $prefix = $tool . '/';

                    if (!Str::startsWith($relativePath, $prefix)) {
                        return null;
                    }

                    $rest = substr($relativePath, strlen($prefix));

                    if ($rest === false || $rest === '' || !str_contains($rest, '/')) {
                        return null;
                    }

                    return $tool . '/' . explode('/', $rest)[0];
                })
                ->filter()
                ->unique()
                ->count();

            $aggregate = $aggregates->get($tool);

            $stats[$tool] = [
                'folder_count' => $folderCount,
                'file_count' => (int) ($aggregate->file_count ?? 0),
                'size' => (int) ($aggregate->total_size ?? 0),
            ];
        }

        return $stats;
    }

    /**
     * @param  array<string, array<string, string>>  $toolRoots
     * @return Collection<int, array<string, mixed>>
     */
    public function folderCards(Customer $customer, array $toolRoots, string $path, string $search, array $toolStats): Collection
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $search = Str::lower(trim($search));

        if ($path === '') {
            return collect(array_keys($toolRoots))
                ->map(function (string $tool) use ($toolRoots, $toolStats) {
                    $stats = $toolStats[$tool] ?? ['folder_count' => 0, 'file_count' => 0, 'size' => 0];
                    $cfg = $toolRoots[$tool];

                    return [
                        'name' => $tool,
                        'label' => $cfg['label'],
                        'path' => $tool,
                        'item_count' => (int) $stats['folder_count'],
                        'item_label' => __('Folders'),
                        'size_bytes' => (int) $stats['size'],
                        'icon' => $cfg['icon'],
                        'color' => $cfg['color'],
                    ];
                })
                ->filter(fn (array $item) => $item['item_count'] > 0)
                ->values();
        }

        $absolutePrefix = $this->absolutePath($customer, $path);
        $rows = $this->baseQuery($customer)
            ->where('path', 'like', $absolutePrefix . '/%')
            ->select(['path', 'size_bytes'])
            ->cursor();

        $folders = [];

        foreach ($rows as $row) {
            $relative = $this->extractRelativePath((string) $row->path);
            $prefix = $path . '/';

            if (!Str::startsWith($relative, $prefix)) {
                continue;
            }

            $rest = substr($relative, strlen($prefix));

            if ($rest === false || $rest === '' || !str_contains($rest, '/')) {
                continue;
            }

            $next = explode('/', $rest)[0];

            if ($search !== '' && !Str::contains(Str::lower($next), $search)) {
                continue;
            }

            $folderPath = trim($path . '/' . $next, '/');

            if (!isset($folders[$folderPath])) {
                $folders[$folderPath] = [
                    'name' => $next,
                    'label' => $next,
                    'path' => $folderPath,
                    'item_count' => 0,
                    'item_label' => __('Files'),
                    'size_bytes' => 0,
                    'icon' => 'ri-folder-2-fill',
                    'color' => 'warning',
                ];
            }

            $folders[$folderPath]['item_count']++;
            $folders[$folderPath]['size_bytes'] += (int) ($row->size_bytes ?? 0);
        }

        return collect($folders)->sortBy('name')->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function currentFolderFiles(Customer $customer, string $path, string $search): Collection
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            return collect();
        }

        $absolutePrefix = $this->absolutePath($customer, $path);
        $search = Str::lower(trim($search));

        $rows = $this->baseQuery($customer)
            ->where('path', 'like', $absolutePrefix . '/%')
            ->orderByDesc('updated_at')
            ->get([
                'id',
                'customer_id',
                'tool_code',
                'disk',
                'path',
                'size_bytes',
                'mime',
                'purpose',
                'meta',
                'created_at',
                'updated_at',
            ]);

        return $rows
            ->map(fn (CustomerFile $file) => $this->mapFile($file))
            ->filter(function (array $file) use ($path, $search) {
                $prefix = $path . '/';
                $relative = $file['relative_path'];

                if (!Str::startsWith($relative, $prefix)) {
                    return false;
                }

                $rest = substr($relative, strlen($prefix));

                if ($rest === false || $rest === '' || str_contains($rest, '/')) {
                    return false;
                }

                if ($search !== '' && !Str::contains(Str::lower($file['basename']), $search)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    public function filesPaginator(Customer $customer, string $path, string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $items = $this->currentFolderFiles($customer, $path, $search);

        return new LengthAwarePaginator(
            items: $items->forPage($page, $perPage)->values(),
            total: $items->count(),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function deleteTargetsByPrefix(Customer $customer, string $relativePrefix): Collection
    {
        $absolutePrefix = $this->absolutePath($customer, $relativePrefix);

        return $this->baseQuery($customer)
            ->where(function ($query) use ($absolutePrefix) {
                $query->where('path', $absolutePrefix)
                    ->orWhere('path', 'like', $absolutePrefix . '/%');
            })
            ->get(['id', 'disk', 'path', 'size_bytes'])
            ->map(fn (CustomerFile $file) => [
                'id' => (int) $file->id,
                'disk' => (string) ($file->disk ?? 's3'),
                'path' => (string) $file->path,
                'size_bytes' => (int) ($file->size_bytes ?? 0),
            ])
            ->values();
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    public function overviewStats(Customer $customer): array
    {
        $groups = [
            'Documents' => ['count' => 0, 'size' => 0, 'icon' => 'ri-file-text-line', 'color' => 'secondary'],
            'Audio' => ['count' => 0, 'size' => 0, 'icon' => 'ri-volume-up-line', 'color' => 'success'],
            'JSON' => ['count' => 0, 'size' => 0, 'icon' => 'ri-code-s-slash-line', 'color' => 'info'],
            'Others' => ['count' => 0, 'size' => 0, 'icon' => 'ri-folder-line', 'color' => 'warning'],
        ];

        $rows = $this->baseQuery($customer)
            ->select(['path', 'mime', 'size_bytes'])
            ->cursor();

        foreach ($rows as $row) {
            $mime = (string) ($row->mime ?? '');
            $ext = strtolower(pathinfo((string) $row->path, PATHINFO_EXTENSION));

            if (Str::startsWith($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'opus'], true)) {
                $key = 'Audio';
            } elseif (in_array($ext, ['txt', 'doc', 'docx', 'pdf'], true) || Str::contains($mime, 'text/')) {
                $key = 'Documents';
            } elseif ($ext === 'json' || Str::contains($mime, 'json')) {
                $key = 'JSON';
            } else {
                $key = 'Others';
            }

            $groups[$key]['count']++;
            $groups[$key]['size'] += (int) ($row->size_bytes ?? 0);
        }

        return $groups;
    }
}
