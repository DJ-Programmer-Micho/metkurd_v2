<?php

use App\Models\MlJob;
use App\Support\AppToolCatalog;
use App\Services\XTTS\XttsJobSyncService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public string $toolCode = 'tts';
    public string $eventPrefix = 'xtts';
    public string $streamRoute = 'app.renders.xtts.stream';
    public string $downloadRoute = 'app.renders.xtts.download';
    public string $domPrefix = 'xtts';
    public string $pageName = 'xttsRendersPage';
    public string $panelTitle = 'Recent Renders';
    public string $modelLabel = 'MK-TTS';

    public int $refreshKey = 0;

    #[On('xtts-renders-refresh')]
    #[On('f5tts-renders-refresh')]
    public function refreshPanel(): void
    {
        $this->resetPage(pageName: $this->pageName);
        $this->refreshKey++;
    }

    #[Computed]
    public function renders()
    {
        $customerId = auth('app')->id();
        $locale = app()->getLocale();

        if (!$customerId) {
            return MlJob::query()->whereRaw('1=0')->paginate(3, pageName: $this->pageName);
        }

        $toolId = app(AppToolCatalog::class)->toolId($this->toolCode);

        $paginator = MlJob::query()
            ->where('customer_id', $customerId)
            ->when($toolId, fn ($q) => $q->where('tool_id', $toolId))
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->paginate(3, pageName: $this->pageName);

        $paginator->setCollection(
            $paginator->getCollection()->values()->map(function ($job, $index) use ($locale) {
                $jobId = (string) $job->id;
                $path = (string) data_get($job->output, 'path', '');
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mime = $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav';

                $fullText = trim((string) (
                    data_get($job->input, 'text')
                    ?: data_get($job->input, 'gen_text', '')
                ));
                $snippet = mb_strlen($fullText) > 240
                    ? mb_substr($fullText, 0, 160) . '...'
                    : $fullText;

                return [
                    'id' => $jobId,
                    'speaker' => (string) (
                        data_get($job->input, 'speaker_id')
                        ?: data_get($job->input, 'speaker_key', '-')
                    ),
                    'model' => $this->modelLabel,
                    'created_at' => optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i'),
                    'full_url' => route($this->streamRoute, [
                        'locale' => $locale,
                        'jobId' => $jobId,
                    ]) . '?proxy=1',
                    'mime' => $mime,
                    'bytes' => (int) data_get($job->output, 'bytes', 0),
                    'download_url' => route($this->downloadRoute, [
                        'locale' => $locale,
                        'jobId' => $jobId,
                    ]),
                    'text_snippet' => $snippet,
                    'words' => $this->wordsCount($fullText),
                    'is_latest' => $index === 0,
                ];
            })
        );

        return $paginator;
    }

    public function deleteRender(string $jobId, XttsJobSyncService $sync): void
    {
        $customerId = auth('app')->id();
        $toolId = app(AppToolCatalog::class)->toolId($this->toolCode);

        $job = MlJob::query()
            ->with('tool')
            ->where('id', $jobId)
            ->where('customer_id', $customerId)
            ->when($toolId, fn ($query) => $query->where('tool_id', $toolId))
            ->where('status', 'done')
            ->first();

        if (!$job) {
            $this->dispatch('alert', type: 'error', message: __('Render not found.'));
            return;
        }

        try {
            $sync->deleteFinishedRender($job);
            $this->resetPage(pageName: $this->pageName);
            $this->refreshKey++;
            $this->dispatch('customerStorageUpdated');
            $this->dispatch($this->refreshEventName());
            $this->dispatch('alert', type: 'success', message: __('Deleted.'));
        } catch (\Throwable $e) {
            $this->dispatch('alert', type: 'error', message: __('Delete failed: :message', ['message' => $e->getMessage()]));
        }
    }

    protected function wordsCount(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    protected function refreshEventName(): string
    {
        $prefix = strtolower(trim($this->eventPrefix));

        return ($prefix !== '' ? $prefix : 'xtts') . '-renders-refresh';
    }

    public function render()
    {
        return view('app.partials.⚡xtts-renders-panel');
    }
};
?>

<div class="col-lg-5" wire:key="{{ $domPrefix }}-renders-panel-{{ $refreshKey }}">
    <div class="turbo-border mb-3">
        <div class="turbo-inner">
            <div class="card mb-0">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>{{ __($panelTitle) }}</strong>
                    <button class="btn btn-sm btn-outline-secondary" wire:click="$refresh" type="button">
                        {{ __('Refresh') }}
                    </button>
                </div>

                <div class="card-body">
                    @if($this->renders->count() === 0)
                        <div class="text-muted">{{ __('No renders yet.') }}</div>
                    @else
                        @foreach($this->renders as $r)
                        {{-- @php
                            dd($this->renders);
                        @endphp --}}
                            <div
                                class="border rounded p-2 mb-2 render-card"
                                wire:key="{{ $domPrefix }}-render-{{ $r['id'] }}"
                                id="{{ $domPrefix }}-render-card-{{ $r['id'] }}"
                            >
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="small text-muted">
                                            {{-- {{ __(':created | :model | :speaker', ['created' => $r['created_at'], 'model' => $r['model'], 'speaker' => $r['speaker']]) }} --}}
                                            {{ __(':created | :model', ['created' => $r['created_at'], 'model' => $r['model']]) }}
                                        </div>
                                        <div class="small text-muted">
                                            {{ __('Words: :words | Bytes: :bytes', ['words' => $r['words'], 'bytes' => number_format($r['bytes'])]) }}
                                        </div>
                                    </div>

                                    <div class="text-end">
                                        <button class="btn btn-sm btn-outline-danger"
                                                wire:click="deleteRender('{{ $r['id'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="deleteRender('{{ $r['id'] }}')"
                                                type="button">
                                            {{ __('Delete') }}
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-2 small">{{ $r['text_snippet'] }}</div>

                                <div class="mt-2" wire:ignore>
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <span class="small text-muted" id="{{ $domPrefix }}-time-{{ $r['id'] }}">--:-- / --:--</span>

                                        <div class="btn-group btn-group-sm">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-{{ $domPrefix }}-preview"
                                                    data-job="{{ $r['id'] }}"
                                                    data-url="{{ $r['full_url'] }}"
                                                    data-latest="{{ $r['is_latest'] ? '1' : '0' }}"
                                                    data-preload-rank="{{ $loop->index }}">
                                                <i class="fa fa-play me-1"></i> {{ __('Play/Pause') }}
                                            </button>

                                            <button type="button"
                                                    class="btn btn-outline-secondary btn-{{ $domPrefix }}-stop"
                                                    data-job="{{ $r['id'] }}">
                                                <i class="fa fa-stop me-1"></i> {{ __('Stop') }}
                                            </button>
                                        </div>
                                    </div>

                                    <div id="{{ $domPrefix }}-wrap-{{ $r['id'] }}" class="mt-1">
                                        <div id="{{ $domPrefix }}-ph-{{ $r['id'] }}" class="border rounded bg-dark" style="height:90px; opacity:.25;"></div>
                                        <div id="{{ $domPrefix }}-wave-{{ $r['id'] }}" class="border rounded" style="height:90px; display:none;"></div>
                                    </div>

                                    <div class="mt-2">
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="{{ $r['download_url'] }}"
                                           target="_blank"
                                           rel="noopener">
                                            {{ __('Download') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <div class="mt-3">
                            {{ $this->renders->links(data: ['scrollTo' => false]) }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
