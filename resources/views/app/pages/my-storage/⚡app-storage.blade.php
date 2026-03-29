<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Support\StorageBrowser;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new
#[Layout('app::layouts.app')]
class extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'path', keep: true)]
    public string $path = '';

    public int $perPage = 12;

    public ?string $selectedFile = null;

    public ?string $pendingDeleteType = null; // file|folder
    public ?string $pendingDeletePath = null;
    public ?string $pendingDeleteLabel = null;

    public array $toolRoots = [];

    public function mount(): void
    {
        $this->toolRoots = [
            'tts'       => ['label' => __('Text to Speech'), 'icon' => 'ri-volume-up-line', 'color' => 'primary'],
            'clone-tts' => ['label' => __('Clone Speech'), 'icon' => 'ri-mic-line', 'color' => 'info'],
            'stem'      => ['label' => __('Stem Separation'), 'icon' => 'ri-equalizer-line', 'color' => 'success'],
            'wasr'      => ['label' => __('Speech to Text'), 'icon' => 'ri-file-text-line', 'color' => 'warning'],
            'ocr'       => ['label' => __('Optical Character Recognition'), 'icon' => 'ri-scan-2-line', 'color' => 'danger'],
        ];

        $this->path = $this->sanitizePath($this->path);

        if ($this->path !== '' && !$this->pathExists($this->path)) {
            $this->path = '';
        }

        $this->syncSelectedFile();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->syncSelectedFile();
    }

    public function updatedPath(): void
    {
        $this->path = $this->sanitizePath($this->path);
        $this->resetPage();
        $this->syncSelectedFile();
    }

    public function navigateTo(string $path = ''): void
    {
        $path = $this->sanitizePath($path);

        if ($path !== '' && !$this->pathExists($path)) {
            return;
        }

        $this->path = $path;
        $this->resetPage();
        $this->syncSelectedFile();
    }

    public function selectFile(string $relativePath): void
    {
        $file = $this->findFileByRelativePath($relativePath);

        if (!$file || !$this->isPreviewable($file['mime'], $file['extension'])) {
            return;
        }

        $this->selectedFile = $file['relative_path'];
    }

    public function confirmDeleteFile(string $relativePath): void
    {
        $file = $this->findFileByRelativePath($relativePath);

        if (!$file) {
            return;
        }

        $deletePrefix = $this->resolveDeletePrefixForFile($relativePath);
        $isCoupled = $deletePrefix !== $relativePath;

        $this->pendingDeleteType = 'file';
        $this->pendingDeletePath = $relativePath;
        $this->pendingDeleteLabel = $isCoupled
            ? basename($deletePrefix) . ' (linked assets)'
            : basename($relativePath);

        $this->dispatch('storage-delete-modal-open');
    }

    public function confirmDeleteFolder(string $folderPath): void
    {
        $folderPath = $this->sanitizePath($folderPath);

        if ($folderPath === '' || !$this->pathExists($folderPath)) {
            return;
        }

        $this->pendingDeleteType = 'folder';
        $this->pendingDeletePath = $folderPath;
        $this->pendingDeleteLabel = basename($folderPath);

        $this->dispatch('storage-delete-modal-open');
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteType = null;
        $this->pendingDeletePath = null;
        $this->pendingDeleteLabel = null;

        $this->dispatch('storage-delete-modal-close');
    }

    public function deleteConfirmed(): void
    {
        $customer = auth('app')->user();

        abort_unless($customer, 403);

        if (!$this->pendingDeleteType || !$this->pendingDeletePath) {
            return;
        }

        try {
            $browser = app(StorageBrowser::class);

            DB::transaction(function () use ($customer, $browser) {
                $prefix = $this->pendingDeleteType === 'folder'
                    ? $this->pendingDeletePath
                    : $this->resolveDeletePrefixForFile($this->pendingDeletePath);

                $targets = $browser->deleteTargetsByPrefix($customer, $prefix);

                if ($targets->isEmpty()) {
                    return;
                }

                foreach ($targets as $file) {
                    $disk = (string) ($file['disk'] ?: 's3');
                    $path = (string) $file['path'];

                    if ($path !== '' && Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                    }

                    CustomerFile::query()
                        ->where('id', $file['id'])
                        ->update([
                            'status'     => 'deleted',
                            'deleted_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                $this->recalculateUsage((int) $customer->id);

                $jobId = $this->extractJobIdFromRelativePrefix($prefix);
                if ($jobId) {
                    MlJob::query()
                        ->where('customer_id', (int) $customer->id)
                        ->where('id', $jobId)
                        ->update([
                            'status'            => 'deleted',
                            'output'            => null,
                            'storage_in_bytes'  => 0,
                            'storage_out_bytes' => 0,
                            'error'             => null,
                            'updated_at'        => now(),
                        ]);
                }
            }, 3);

            if ($this->path !== '' && !$this->pathExists($this->path)) {
                $this->path = $this->parentPath($this->path);
            }

            $this->syncSelectedFile();

            $this->dispatch('alert', type: 'success', message: __('Storage item deleted successfully.'));
        } catch (\Throwable $e) {
            Log::error('APP_STORAGE_DELETE_FAILED', [
                'customer_id' => auth('app')->id(),
                'type'        => $this->pendingDeleteType,
                'path'        => $this->pendingDeletePath,
                'message'     => $e->getMessage(),
            ]);

            $this->dispatch('alert', type: 'error', message: __('Delete failed. Please try again.'));
        } finally {
            $this->cancelDelete();
            $this->resetPage();
        }
    }

    #[Computed]
    public function usage(): array
    {
        $customer = auth('app')->user();

        $usage = CustomerUsage::query()
            ->firstOrCreate(
                ['customer_id' => (int) $customer->id],
                [
                    'storage_used_bytes' => 0,
                    'jobs_total'         => 0,
                    'jobs_succeeded'     => 0,
                    'jobs_failed'        => 0,
                ]
            );

        $used = (int) $usage->storage_used_bytes;

        $limitBytes = (int) (

            data_get($customer, 'storagePlan.quota_mb') * 1024 * 1024
            ?? (20 * 1024 * 1024 * 1024) // fallback 20 GB
        );

        $percent = $limitBytes > 0
            ? min(100, (int) round(($used / $limitBytes) * 100))
            : 0;

        return [
            'used_bytes'  => $used,
            'limit_bytes' => $limitBytes,
            'percent'     => $percent,
        ];
    }

    #[Computed]
    public function toolStats(): array
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->toolStats($customer, $this->toolRoots);
    }

    #[Computed]
    public function storageSegments(): array
    {
        $toolStats = $this->toolStats();
        $usedBytes = max(0, (int) $this->usage()['used_bytes']);

        $segments = collect($this->toolRoots)
            ->map(function (array $cfg, string $tool) use ($toolStats, $usedBytes) {
                $stat = $toolStats[$tool] ?? ['folder_count' => 0, 'file_count' => 0, 'size' => 0];
                $size = (int) $stat['size'];

                if ($size <= 0) {
                    return null;
                }

                return [
                    'tool'            => $tool,
                    'label'           => $cfg['label'],
                    'color'           => $cfg['color'],
                    'folder_count'    => (int) $stat['folder_count'],
                    'file_count'      => (int) $stat['file_count'],
                    'size'            => $size,
                    'percent_of_used' => $usedBytes > 0 ? round(($size / $usedBytes) * 100, 2) : 0,
                ];
            })
            ->filter()
            ->sortByDesc('size')
            ->values();

        return $segments->all();
    }

    #[Computed]
    public function folderCards(): Collection
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->folderCards(
            $customer,
            $this->toolRoots,
            $this->path,
            $this->search,
            $this->toolStats()
        );
    }

    #[Computed]
    public function currentFolderFiles(): Collection
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->currentFolderFiles($customer, $this->path, $this->search);
    }

    #[Computed]
    public function pagedFiles(): Collection
    {
        return $this->filesPaginator()->getCollection();
    }

    #[Computed]
    public function filesPaginator(): LengthAwarePaginator
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->filesPaginator(
            $customer,
            $this->path,
            $this->search,
            $this->getPage(),
            $this->perPage
        );
    }

    #[Computed]
    public function breadcrumbs(): array
    {
        $crumbs = [
            ['label' => __('My Storage'), 'path' => ''],
        ];

        if ($this->path === '') {
            return $crumbs;
        }

        $parts = array_values(array_filter(explode('/', $this->path)));
        $built = '';

        foreach ($parts as $part) {
            $built = trim($built . '/' . $part, '/');
            $crumbs[] = [
                'label' => $this->toolRoots[$part]['label'] ?? $part,
                'path'  => $built,
            ];
        }

        return $crumbs;
    }

    #[Computed]
    public function selectedFileData(): ?array
    {
        if (!$this->selectedFile) {
            return null;
        }

        $file = $this->findFileByRelativePath($this->selectedFile);

        if (!$file || !$this->isPreviewable($file['mime'], $file['extension'])) {
            return null;
        }

        $file['download_url'] = $this->temporaryUrlFor($file, 'attachment');
        $file['stream_url'] = $this->temporaryUrlFor($file, 'inline');
        $file['is_audio'] = $this->isAudio($file['mime'], $file['extension']);
        $file['icon'] = $this->fileIcon($file['mime'], $file['extension']);
        $file['type_label'] = $this->typeLabel($file['mime'], $file['extension']);

        return $file;
    }

    #[Computed]
    public function overviewStats(): array
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->overviewStats($customer);
    }

    protected function sanitizePath(?string $path): string
    {
        $path = trim((string) $path);
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = trim((string) $path, '/');

        $parts = array_values(array_filter(explode('/', $path), fn ($part) => $part !== '' && $part !== '.' && $part !== '..'));

        return implode('/', $parts);
    }

    protected function syncSelectedFile(): void
    {
        $visibleFiles = $this->currentFolderFiles();

        if ($visibleFiles->isEmpty()) {
            $this->selectedFile = null;

            return;
        }

        $selectedVisible = $this->selectedFile
            ? $visibleFiles->first(fn (array $file) => $file['relative_path'] === $this->selectedFile)
            : null;

        if ($selectedVisible && $this->isPreviewable($selectedVisible['mime'], $selectedVisible['extension'])) {
            return;
        }

        $fallback = $visibleFiles->first(
            fn (array $file) => $this->isPreviewable($file['mime'], $file['extension'])
        );

        $this->selectedFile = $fallback['relative_path'] ?? null;
    }

    protected function pathExists(string $path): bool
    {
        $path = $this->sanitizePath($path);

        return app(StorageBrowser::class)->pathExists(auth('app')->user(), $path);
    }

    protected function parentPath(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $this->sanitizePath($path))));
        array_pop($parts);

        return implode('/', $parts);
    }

    protected function findFileByRelativePath(string $relativePath): ?array
    {
        return app(StorageBrowser::class)->findFileByRelativePath(
            auth('app')->user(),
            $this->sanitizePath($relativePath)
        );
    }

    protected function resolveDeletePrefixForFile(string $relativePath): string
    {
        $relativePath = $this->sanitizePath($relativePath);
        $parts = explode('/', $relativePath);

        if (count($parts) >= 3 && array_key_exists($parts[0], $this->toolRoots)) {
            // Delete the whole job directory:
            // tts/{jobId}/...
            // clone-tts/{jobId}/...
            // stem/{jobId}/...
            // wasr/{jobId}/...
            // ocr/{jobId}/...
            return $parts[0] . '/' . $parts[1];
        }

        return $relativePath;
    }

    protected function extractJobIdFromRelativePrefix(string $prefix): ?string
    {
        $parts = explode('/', $this->sanitizePath($prefix));

        if (count($parts) >= 2 && array_key_exists($parts[0], $this->toolRoots)) {
            return $parts[1];
        }

        return null;
    }

    protected function recalculateUsage(int $customerId): void
    {
        $bytes = (int) CustomerFile::query()
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->sum('size_bytes');

        CustomerUsage::query()->updateOrCreate(
            ['customer_id' => $customerId],
            ['storage_used_bytes' => $bytes]
        );
    }

    protected function temporaryUrlFor(array $file, string $disposition = 'attachment'): string
    {
        $disk = (string) ($file['disk'] ?: 's3');
        $path = (string) $file['path'];
        $filename = basename($path) ?: $file['basename'];

        if ($path === '') {
            return '#';
        }

        if (method_exists(Storage::disk($disk), 'temporaryUrl')) {
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(30), [
                'ResponseContentDisposition' => ($disposition === 'inline' ? 'inline' : 'attachment') . '; filename="' . $filename . '"',
                'ResponseContentType' => $file['mime'] ?: 'application/octet-stream',
            ]);
        }

        return Storage::disk($disk)->url($path);
    }

    protected function isAudio(?string $mime, ?string $ext): bool
    {
        $mime = (string) $mime;
        $ext = strtolower((string) $ext);

        return Str::startsWith($mime, 'audio/')
            || in_array($ext, ['mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'opus'], true);
    }

    protected function isJsonFile(?string $mime, ?string $ext): bool
    {
        return strtolower((string) $ext) === 'json'
            || Str::contains((string) $mime, 'json');
    }

    protected function isPreviewable(?string $mime, ?string $ext): bool
    {
        return !$this->isJsonFile($mime, $ext);
    }

    protected function fileIcon(?string $mime, ?string $ext): string
    {
        $ext = strtolower((string) $ext);

        if ($this->isAudio($mime, $ext)) {
            return 'ri-volume-up-fill text-success';
        }

        return match ($ext) {
            'txt'   => 'ri-file-text-fill text-secondary',
            'json'  => 'ri-code-s-slash-fill text-info',
            'pdf'   => 'ri-file-pdf-fill text-danger',
            default => 'ri-file-fill text-primary',
        };
    }

    protected function typeLabel(?string $mime, ?string $ext): string
    {
        $ext = strtolower((string) $ext);

        if ($this->isAudio($mime, $ext)) {
            return __('Audio');
        }

        return match ($ext) {
            'txt', 'pdf', 'doc', 'docx' => __('Document'),
            'json' => __('JSON'),
            default => __('File'),
        };
    }

    public function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return number_format($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }

    public function render()
    {
        return view('app.pages.my-storage.⚡app-storage');
    }
};
?>

<div>
    <style>
        .storage-shell {
            --storage-radius: 1rem;
        }

        .storage-shell .card,
        .storage-shell .modal-content {
            border-radius: var(--storage-radius);
        }

        .storage-shell .file-manager-sidebar,
        .storage-shell .file-manager-content,
        .storage-shell .file-manager-detail-content {
            background: var(--vz-secondary-bg, var(--bs-body-bg));
            border-radius: var(--storage-radius);
            min-height: calc(100vh - 220px);
        }

        .storage-shell .file-manager-sidebar {
            width: 280px;
            flex: 0 0 280px;
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
        }

        .storage-shell .file-manager-content {
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
        }

        .storage-shell .file-manager-detail-content {
            width: 360px;
            flex: 0 0 360px;
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
        }

        .storage-shell .folder-tile {
            cursor: pointer;
            transition: .2s ease;
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
        }

        .storage-shell .folder-tile:hover,
        .storage-shell .folder-tile.active {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, .08);
            border-color: rgba(var(--bs-primary-rgb), .35);
        }

        .storage-shell .file-row {
            cursor: default;
        }

        .storage-shell .file-row.table-active {
            --bs-table-bg: rgba(var(--bs-primary-rgb), .08);
        }

        .storage-shell .sticky-pane {
            position: sticky;
            top: 1rem;
        }

        .storage-shell .audio-preview {
            border: 1px dashed var(--vz-border-color, var(--bs-border-color));
            border-radius: 1rem;
            padding: 1rem;
            background: rgba(var(--bs-secondary-rgb), .05);
        }

        .storage-shell .storage-status-chart {
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
            border-radius: 1rem;
            padding: .875rem;
            background: rgba(var(--bs-secondary-rgb), .04);
        }

        .storage-shell .storage-status-bar {
            height: .85rem;
            border-radius: 999px;
            overflow: hidden;
            background: rgba(var(--bs-secondary-rgb), .12);
            display: flex;
        }

        .storage-shell .storage-status-used {
            height: 100%;
            display: flex;
            overflow: hidden;
            border-radius: inherit;
        }

        .storage-shell .storage-status-segment {
            height: 100%;
        }

        .storage-shell .storage-status-free {
            flex: 1 1 auto;
            background: rgba(var(--bs-secondary-rgb), .08);
        }

        .storage-shell .storage-status-legend {
            display: grid;
            gap: .5rem;
        }

        .storage-shell .storage-status-item {
            display: flex;
            align-items: flex-start;
            gap: .625rem;
        }

        .storage-shell .storage-status-swatch {
            width: .75rem;
            height: .75rem;
            border-radius: 999px;
            margin-top: .2rem;
            flex: 0 0 auto;
        }

        .storage-shell .file-row-selectable {
            cursor: pointer;
        }

        @media (max-width: 1199.98px) {
            .storage-shell .file-manager-detail-content {
                width: 100%;
                flex: 1 1 100%;
            }
        }

        @media (max-width: 991.98px) {
            .storage-shell .chat-wrapper {
                flex-direction: column;
            }

            .storage-shell .file-manager-sidebar,
            .storage-shell .file-manager-detail-content {
                width: 100%;
                flex: 1 1 100%;
            }
        }
    </style>

    @php
        $usage = $this->usage();
        $toolStats = $this->toolStats();
        $storageSegments = $this->storageSegments();
        $folderCards = $this->folderCards();
        $filesPaginator = $this->filesPaginator();
        $pagedFiles = $filesPaginator->getCollection();
        $selectedPreview = $this->selectedFileData();
        $overviewStats = $this->overviewStats();
    @endphp

    <div class="storage-shell">
        {{-- <div class="page-content"> --}}
            <x-slot:title>{{ __('My Storage') }} | {{ __('MET KURD') }}</x-slot:title>

            <div class="container-fluid">

                <div class="chat-wrapper d-lg-flex gap-3 mx-n4 mt-n4 p-3">
                    <!-- Sidebar -->
                    
                    <div class="file-manager-sidebar">
                        <div class="p-3 d-flex flex-column h-100">
                            <div class="mb-3">
                                <h5 class="mb-0 fw-bold">{{ __('My Storage') }}</h5>
                                <div class="text-muted small mt-1">{{ __('Manage your generated AI assets') }}</div>
                            </div>

                            <div class="search-box mb-3">
                                <div class="position-relative">
                                    <input
                                        type="text"
                                        class="form-control bg-light border-light ps-5"
                                        placeholder="{{ __('Search files or folders...') }}"
                                        wire:model.live.debounce.500ms="search"
                                    >
                                    <i class="ri-search-2-line search-icon position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                                </div>
                            </div>

                            <div class="mt-2 flex-grow-1">
                                <h6 class="fs-11 text-muted text-uppercase mb-3">{{ __('Tools') }}</h6>
                                <ul class="list-unstyled vstack gap-2">
                                    <li>
                                        <button type="button" class="btn btn-sm {{ $path === '' ? 'btn-primary' : 'btn-ghost-dark' }} w-100 text-start" wire:click="navigateTo('')">
                                            <i class="ri-hard-drive-2-line align-bottom me-2"></i> {{ __('All Tools') }}
                                        </button>
                                    </li>

                                    @foreach($toolRoots as $toolKey => $cfg)
                                        @php
                                            $stat = $toolStats[$toolKey] ?? ['folder_count' => 0, 'file_count' => 0, 'size' => 0];
                                        @endphp
                                        <li>
                                            <button
                                                type="button"
                                                class="btn btn-sm {{ $path === $toolKey || str_starts_with($path, $toolKey . '/') ? 'btn-primary' : 'btn-ghost-dark' }} w-100 text-start d-flex align-items-center justify-content-between"
                                                wire:click="navigateTo('{{ $toolKey }}')"
                                            >
                                                <span>
                                                    <i class="{{ $cfg['icon'] }} align-bottom me-2"></i> {{ __($cfg['label']) }}
                                                </span>
                                                <span class="badge bg-secondary-subtle text-secondary">{{ $stat['folder_count'] }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            <div class="mt-auto pt-3 border-top">
                                <h6 class="fs-11 text-muted text-uppercase mb-3">{{ __('Storage Status') }}</h6>
                                <div class="storage-status-chart">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="ri-database-2-line fs-17"></i>
                                            <span class="small text-muted">{{ __('Used by tool') }}</span>
                                        </div>
                                        <span class="badge {{ $usage['percent'] >= 85 ? 'bg-danger-subtle text-danger' : ($usage['percent'] >= 60 ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') }}">
                                            {{ $usage['percent'] }}%
                                        </span>
                                    </div>

                                    <div class="storage-status-bar mb-2" role="img" aria-label="{{ __('Storage usage by tool') }}">
                                        @if($usage['percent'] > 0 && count($storageSegments))
                                            <div class="storage-status-used" style="width: {{ $usage['percent'] }}%">
                                                @foreach($storageSegments as $segment)
                                                    <div
                                                        class="storage-status-segment bg-{{ $segment['color'] }}"
                                                        style="width: {{ $segment['percent_of_used'] }}%"
                                                        title="{{ __($segment['label']) }}: {{ $this->formatBytes($segment['size']) }}"
                                                    ></div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if($usage['percent'] < 100)
                                            <div class="storage-status-free"></div>
                                        @endif
                                    </div>

                                    <div class="text-muted fs-12 d-flex justify-content-between gap-2 mb-3">
                                        <span><b>{{ $this->formatBytes($usage['used_bytes']) }}</b> {{ __('used') }}</span>
                                        <span><b>{{ $this->formatBytes($usage['limit_bytes']) }}</b> {{ __('total') }}</span>
                                    </div>

                                    @if(count($storageSegments))
                                        <div class="storage-status-legend">
                                            @foreach($storageSegments as $segment)
                                                <div class="storage-status-item">
                                                    <span class="storage-status-swatch bg-{{ $segment['color'] }}"></span>
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div class="d-flex justify-content-between gap-2">
                                                            <span class="text-truncate">{{ __($segment['label']) }}</span>
                                                            <span class="fw-semibold">{{ $this->formatBytes($segment['size']) }}</span>
                                                        </div>
                                                        <div class="text-muted fs-12">
                                                            {{ number_format($segment['folder_count']) }} {{ __('folder(s)') }}
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-muted fs-12">{{ __('No generated files are stored yet.') }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="file-manager-content w-100 p-3 py-0">
                        <div class="mx-n3 pt-4 px-4">
                            <!-- Breadcrumb -->
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb breadcrumb-separated mb-0">
                                        @foreach($this->breadcrumbs() as $index => $crumb)
                                            @if($loop->last)
                                                <li class="breadcrumb-item active" aria-current="page">{{ __($crumb['label']) }}</li>
                                            @else
                                                <li class="breadcrumb-item">
                                                    <a href="javascript:void(0)" wire:click="navigateTo('{{ $crumb['path'] }}')">{{ __($crumb['label']) }}</a>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ol>
                                </nav>

                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-info-subtle text-info">{{ __('Read Only') }}</span>
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('S3-backed') }}</span>
                                </div>
                            </div>

                            <!-- Folders -->
                            <div id="folder-list" class="mb-4">
                                <div class="row justify-content-between g-2 mb-3">
                                    <div class="col">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-grow-1">
                                                <h5 class="fs-16 mb-0">{{ __('Folders') }}</h5>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="text-muted small">
                                            {{ $folderCards->count() }} {{ __('folder(s)') }}
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row g-3">
                                    @forelse($folderCards as $folder)
                                        <div class="col-xxl-3 col-md-4 col-sm-6">
                                            <div class="card shadow-none folder-tile {{ $path === $folder['path'] ? 'active' : '' }}">
                                                <div class="card-body">
                                                    <div class="d-flex mb-3">
                                                        <div class="flex-grow-1">
                                                            <button type="button" class="btn btn-sm btn-ghost-primary" wire:click="navigateTo('{{ $folder['path'] }}')">
                                                                {{ __('Open') }}
                                                            </button>
                                                        </div>
                                                        <div class="dropdown" wire:ignore.self>
                                                            <button class="btn btn-ghost-primary btn-icon btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" onclick="event.stopPropagation()">
                                                                <i class="ri-more-2-fill fs-16 align-bottom"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end" onclick="event.stopPropagation()">
                                                                <li>
                                                                    <button class="dropdown-item" type="button" wire:click="navigateTo('{{ $folder['path'] }}')">{{ __('Open') }}</button>
                                                                </li>
                                                                <li>
                                                                    <button class="dropdown-item text-danger" type="button" wire:click="confirmDeleteFolder('{{ $folder['path'] }}')">{{ __('Delete') }}</button>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>

                                                    <div class="text-center">
                                                        <div class="mb-2">
                                                            <i class="{{ $folder['icon'] }} align-bottom text-warning display-5"></i>
                                                        </div>
                                                        <h6 class="fs-15 folder-name mb-1">{{ __($folder['label']) }}</h6>
                                                        <div class="small text-muted text-truncate">{{ $folder['path'] }}</div>
                                                    </div>

                                                    <div class="hstack mt-4 text-muted">
                                                        <span class="me-auto"><b>{{ number_format($folder['item_count']) }}</b> {{ $folder['item_label'] }}</span>
                                                        <span><b>{{ $this->formatBytes($folder['size_bytes']) }}</b></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="col-12">
                                            <div class="alert alert-info mb-0">
                                                {{ __('No folders found in this location.') }}
                                            </div>
                                        </div>
                                    @endforelse
                                </div>
                                
                            </div>
                            @if (count($pagedFiles))
                            <!-- Files -->
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <h5 class="flex-grow-1 fs-16 mb-0">{{ __('Files') }}</h5>
                                    <div class="text-muted small">
                                        {{ __('Showing') }} {{ $filesPaginator->firstItem() ?? 0 }} - {{ $filesPaginator->lastItem() ?? 0 }}
                                        {{ __('of') }} {{ $filesPaginator->total() }}
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle table-nowrap mb-0">
                                        <thead class="table-active">
                                            <tr>
                                                <th scope="col">{{ __('Name') }}</th>
                                                <th scope="col">{{ __('Type') }}</th>
                                                <th scope="col">{{ __('Size') }}</th>
                                                <th scope="col">{{ __('Updated') }}</th>
                                                <th scope="col" class="text-center">{{ __('Actions') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($pagedFiles as $file)
                                                @php
                                                    $isAudio = $this->isAudio($file['mime'], $file['extension']);
                                                    $isPreviewable = $this->isPreviewable($file['mime'], $file['extension']);
                                                    $streamUrl = $isAudio ? $this->temporaryUrlFor($file, 'inline') : null;
                                                    $downloadUrl = $this->temporaryUrlFor($file, 'attachment');
                                                @endphp
                                                <tr
                                                    class="file-row {{ $isPreviewable ? 'file-row-selectable' : '' }} {{ $selectedFile === $file['relative_path'] ? 'table-active' : '' }}"
                                                    wire:key="storage-file-{{ $file['id'] }}"
                                                    @if($isPreviewable)
                                                        wire:click="selectFile('{{ $file['relative_path'] }}')"
                                                    @endif
                                                >
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-xs flex-shrink-0 me-2">
                                                                <div class="avatar-title bg-light text-muted rounded fs-16">
                                                                    <i class="{{ $this->fileIcon($file['mime'], $file['extension']) }}"></i>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <div class="fw-semibold text-truncate" style="max-width: 260px;">
                                                                    {{ $file['basename'] }}
                                                                </div>
                                                                <div class="small text-muted text-truncate" style="max-width: 260px;">
                                                                    {{ $file['relative_path'] }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>{{ $this->typeLabel($file['mime'], $file['extension']) }}</td>
                                                    <td>{{ $this->formatBytes($file['size_bytes']) }}</td>
                                                    <td>{{ optional($file['updated_at'])->format('d M Y, h:i A') }}</td>
                                                    <td class="text-center" onclick="event.stopPropagation()">
                                                        <div class="dropdown" wire:ignore.self>
                                                            <button class="btn btn-ghost-primary btn-icon btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" onclick="event.stopPropagation()">
                                                                <i class="ri-more-2-fill fs-16 align-bottom"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end" onclick="event.stopPropagation()">
                                                                @if($isAudio && $streamUrl)
                                                                    <li>
                                                                        <a class="dropdown-item" href="{{ $streamUrl }}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">
                                                                            {{ __('Stream') }}
                                                                        </a>
                                                                    </li>
                                                                @endif
                                                                <li>
                                                                    <a class="dropdown-item" href="{{ $downloadUrl }}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">
                                                                        {{ __('Download') }}
                                                                    </a>
                                                                </li>
                                                                <li>
                                                                    <button class="dropdown-item text-danger" type="button" wire:click.stop="confirmDeleteFile('{{ $file['relative_path'] }}')">
                                                                        {{ __('Delete') }}
                                                                    </button>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted py-5">
                                                        {{ __('No files found in this folder.') }}
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                @if($filesPaginator->hasPages())
                                    <div class="align-items-center mt-3 row g-3 text-center text-sm-start">
                                        <div class="col-sm">
                                            <div class="text-muted">
                                                {{ __('Showing') }} <span class="fw-semibold">{{ $filesPaginator->firstItem() }}</span>
                                                {{ __('to') }} <span class="fw-semibold">{{ $filesPaginator->lastItem() }}</span>
                                                {{ __('of') }} <span class="fw-semibold">{{ $filesPaginator->total() }}</span> {{ __('results') }}
                                            </div>
                                        </div>
                                        <div class="col-sm-auto">
                                            {{ $filesPaginator->links() }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                            @endif
                        </div>
                    </div>

                    <!-- Overview / Preview -->
                    <div class="file-manager-detail-content p-3 py-0">
                        <div class="mx-n3 pt-3 px-3">
                            @if($selectedPreview)
                                @php $preview = $selectedPreview; @endphp
                                <div id="file-overview" class="h-100 sticky-pane">
                                    <div class="d-flex h-100 flex-column">
                                        <div class="d-flex align-items-center pb-3 border-bottom border-bottom-dashed mb-3 gap-2">
                                            <h5 class="flex-grow-1 fw-bold mb-0">{{ __('File Preview') }}</h5>
                                            <div>
                                                <button type="button" class="btn btn-soft-danger btn-icon btn-sm fs-16" wire:click="confirmDeleteFile('{{ $preview['relative_path'] }}')">
                                                    <i class="ri-delete-bin-line align-bottom"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="pb-3 border-bottom border-bottom-dashed mb-3">
                                            <div class="file-details-box bg-light p-3 text-center rounded-3 border border-light mb-3">
                                                <div class="display-4 file-icon">
                                                    <i class="{{ $preview['icon'] }}"></i>
                                                </div>
                                            </div>

                                            <div class="d-flex gap-2 float-end">
                                                @if($preview['is_audio'])
                                                    <a href="{{ $preview['stream_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-icon btn-sm btn-ghost-success fs-16">
                                                        <i class="ri-play-circle-line"></i>
                                                    </a>
                                                @endif

                                                <a href="{{ $preview['download_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-icon btn-sm btn-ghost-primary fs-16">
                                                    <i class="ri-download-2-line"></i>
                                                </a>
                                            </div>

                                            <h5 class="fs-16 mb-1">{{ $preview['basename'] }}</h5>
                                            <p class="text-muted mb-0 fs-12">
                                                {{ $this->formatBytes($preview['size_bytes']) }},
                                                {{ optional($preview['updated_at'])->format('d M, Y') }}
                                            </p>
                                        </div>

                                        @if($preview['is_audio'])
                                            <div class="audio-preview mb-3" wire:key="storage-audio-preview-{{ md5($preview['relative_path']) }}">
                                                <div class="fw-semibold mb-2">{{ __('Audio Player') }}</div>
                                                <div wire:ignore>
                                                    <audio controls class="w-100" preload="none">
                                                        <source src="{{ $preview['stream_url'] }}" type="{{ $preview['mime'] }}">
                                                        {{ __('Your browser does not support audio playback.') }}
                                                    </audio>
                                                </div>
                                            </div>
                                        @endif

                                        <div>
                                            <h5 class="fs-12 text-uppercase text-muted mb-3">{{ __('File Details') }}</h5>

                                            <div class="table-responsive">
                                                <table class="table table-borderless table-nowrap table-sm">
                                                    <tbody>
                                                        <tr>
                                                            <th scope="row" style="width: 35%;">{{ __('File Name :') }}</th>
                                                            <td class="text-break">{{ $preview['basename'] }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('File Type :') }}</th>
                                                            <td>{{ $preview['type_label'] }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('MIME :') }}</th>
                                                            <td class="text-break">{{ $preview['mime'] }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Size :') }}</th>
                                                            <td>{{ $this->formatBytes($preview['size_bytes']) }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Updated :') }}</th>
                                                            <td>{{ optional($preview['updated_at'])->format('d M Y, h:i A') }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Path :') }}</th>
                                                            <td>
                                                                <div class="user-select-all text-break small">{{ $preview['relative_path'] }}</div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Disk :') }}</th>
                                                            <td>{{ $preview['disk'] }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Tool :') }}</th>
                                                            <td>{{ strtoupper(explode('/', $preview['relative_path'])[0] ?? '-') }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            @php
                                                $deletePrefix = $this->resolveDeletePrefixForFile($preview['relative_path']);
                                                $linkedDelete = $deletePrefix !== $preview['relative_path'];
                                            @endphp

                                            @if($linkedDelete)
                                                <div class="alert alert-warning mt-3 mb-0">
                                                    {{ __('Deleting this file will remove its linked job folder too.') }}
                                                </div>
                                            @endif
                                        </div>

                                        <div class="mt-auto border-top border-top-dashed py-3">
                                            <div class="hstack gap-2">
                                                <a href="{{ $preview['download_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-soft-primary w-100">
                                                    <i class="ri-download-2-line align-bottom me-1"></i> {{ __('Download') }}
                                                </a>
                                                @if($preview['is_audio'])
                                                    <a href="{{ $preview['stream_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-soft-success w-100">
                                                        <i class="ri-play-circle-line align-bottom me-1"></i> {{ __('Stream') }}
                                                    </a>
                                                @endif
                                                <button type="button" class="btn btn-soft-danger w-100" wire:click="confirmDeleteFile('{{ $preview['relative_path'] }}')">
                                                    <i class="ri-delete-bin-line align-bottom me-1"></i> {{ __('Delete') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div id="folder-overview" class="sticky-pane">
                                    <div class="d-flex align-items-center pb-3 border-bottom border-bottom-dashed">
                                        <h5 class="flex-grow-1 fw-bold mb-0">{{ __('Overview') }}</h5>
                                    </div>

                                    <div class="mt-4">
                                        <ul class="list-unstyled vstack gap-4">
                                            @foreach($overviewStats as $label => $stat)
                                                <li>
                                                    <div class="d-flex align-items-center">
                                                        <div class="flex-shrink-0">
                                                            <div class="avatar-xs">
                                                                <div class="avatar-title rounded bg-{{ $stat['color'] }}-subtle text-{{ $stat['color'] }}">
                                                                    <i class="{{ $stat['icon'] }} fs-17"></i>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1 ms-3">
                                                            <h5 class="mb-1 fs-15">{{ __($label) }}</h5>
                                                            <p class="mb-0 fs-12 text-muted">{{ number_format($stat['count']) }} {{ __('files') }}</p>
                                                        </div>
                                                        <b>{{ $this->formatBytes($stat['size']) }}</b>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>

                                    <div class="pb-3 mt-4">
                                        <div class="alert alert-info d-flex align-items-center mb-0">
                                            <div class="flex-shrink-0">
                                                <i class="ri-lock-2-line text-info align-bottom display-6"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h5 class="text-info fs-14">{{ __('Storage Rules') }}</h5>
                                                <p class="text-muted mb-0">
                                                    {{ __('This page is read-only. Customers can stream, download, and delete only.') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        {{-- </div> --}}

        <!-- Delete Modal -->
        <div
            id="removeFileItemModal"
            class="modal fade zoomIn"
            tabindex="-1"
            aria-hidden="true"
            wire:ignore.self
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0">
                    <div class="modal-header">
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="cancelDelete"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mt-2 text-center">
                            <div class="avatar-lg mx-auto mb-4">
                                <div class="avatar-title bg-danger-subtle text-danger rounded-circle fs-1">
                                    <i class="ri-delete-bin-5-line"></i>
                                </div>
                            </div>

                            <div class="mt-4 pt-2 fs-15 mx-4 mx-sm-5">
                                <h4>{{ __('Are you sure?') }}</h4>
                                <p class="text-muted mx-4 mb-0">
                                    @if($pendingDeleteType === 'folder')
                                        {{ __('Deleting this folder will permanently remove all files inside it.') }}
                                    @else
                                        {{ __('Deleting this item may also remove all linked assets in the same job folder.') }}
                                    @endif
                                </p>

                                @if($pendingDeleteLabel)
                                    <div class="mt-3 small text-break text-primary">
                                        {{ $pendingDeleteLabel }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="d-flex gap-2 justify-content-center mt-4 mb-2">
                            <button type="button" class="btn w-sm btn-light" data-bs-dismiss="modal" wire:click="cancelDelete">
                                {{ __('Close') }}
                            </button>
                            <button type="button" class="btn w-sm btn-danger" wire:click="deleteConfirmed">
                                {{ __('Yes, Delete It!') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
    let storageDeleteModal;

    const getStorageDeleteModal = () => {
        const el = document.getElementById('removeFileItemModal');
        if (!el) return null;
        storageDeleteModal ??= new bootstrap.Modal(el);
        return storageDeleteModal;
    };

    window.addEventListener('storage-delete-modal-open', () => {
        const modal = getStorageDeleteModal();
        modal?.show();
    });

    window.addEventListener('storage-delete-modal-close', () => {
        const modal = getStorageDeleteModal();
        modal?.hide();
    });
</script>
@endpush
