<?php

use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Services\MetKurd\Jobs\MultiSpeakerSubmissionService;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use App\Services\MetKurd\V2\CttsWorkspaceCache;
use App\Services\MetKurd\V2\MultiSpeakerReferences;
use App\Services\XTTS\XttsJobSyncService;
use App\Support\MetKurdV2JobStatusPresentation;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('app::v2.layouts.app')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Locked] public string $serviceSlug;
    #[Locked] public string $toolSlug;
    #[Locked] public array $definition = [];
    #[Locked] public array $serviceDefinition = [];
    #[Locked] public string $submissionKey;
    #[Locked] public ?string $currentJobId = null;
    public array $segments = [];
    public $referenceAudio = null;
    public string $submissionError = '';

    public function mount(string $service, string $tool): void
    {
        $catalog = app(MetKurdV2ToolCatalog::class);
        $definition = $catalog->tool($service, $tool);
        abort_unless(in_array($definition['kind'] ?? '', ['omni_tts_batch', 'omni_clone_batch'], true), 404);
        abort_unless(auth('app')->user()?->isAllowed($definition['legacy_action'], 'app'), 403);
        $this->serviceSlug = $service;
        $this->toolSlug = $tool;
        $this->definition = $definition;
        $this->serviceDefinition = $catalog->service($service);
        $this->submissionKey = (string) Str::uuid();
        $this->addSegment();
        $this->currentJobId = $this->jobs()->active()->latest()->value('id');
    }

    #[Computed] public function isClone(): bool { return $this->definition['kind'] === 'omni_clone_batch'; }
    #[Computed] public function voices(): array { return app(OmniSpeakerCatalog::class)->forCustomer(auth('app')->user(), app()->getLocale()); }
    #[Computed] public function references(): array { return app(CttsWorkspaceCache::class)->references((int) auth('app')->id()); }
    #[Computed] public function totalChars(): int { return array_sum(array_map(fn ($s) => is_string($s['text'] ?? null) ? mb_strlen(trim($s['text'])) : 0, $this->segments)); }
    #[Computed] public function creditsCost(): int
    {
        return app(MultiSpeakerSubmissionService::class)->quote(auth('app')->user(), $this->definition['legacy_action'], ['segments' => $this->segments, 'total_chars' => $this->totalChars]);
    }
    #[Computed] public function currentJob(): ?MlJob { return $this->currentJobId ? $this->jobs()->find($this->currentJobId) : null; }
    #[Computed] public function presentation(): array { return app(MetKurdV2JobStatusPresentation::class)->for($this->currentJob?->status ?? 'idle'); }

    public function addSegment(): void
    {
        if (count($this->segments) >= config('metkurd_v2.multi_speaker.max_segments')) return;
        $this->segments[] = ['id' => (string) Str::uuid(), 'voice' => '', 'reference_id' => '', 'ref_text' => '', 'text' => '', 'language' => 'ckb', 'pause_after_ms' => 0];
    }
    public function deleteSegment(string $id): void
    {
        $this->segments = array_values(array_filter($this->segments, fn ($segment) => ($segment['id'] ?? '') !== $id));
    }
    public function moveSegment(string $id, int $direction): void
    {
        if (! in_array($direction, [-1, 1], true)) return;
        $index = array_search($id, array_column($this->segments, 'id'), true);
        if ($index === false || ! isset($this->segments[$index + $direction])) return;
        [$this->segments[$index], $this->segments[$index + $direction]] = [$this->segments[$index + $direction], $this->segments[$index]];
    }
    public function reorderSegments(array $ids): void
    {
        if (! array_is_list($ids) || count($ids) > config('metkurd_v2.multi_speaker.max_segments') || collect($ids)->contains(fn ($id) => ! is_string($id))) return;
        $current = array_column($this->segments, 'id');
        if (count($ids) !== count($current) || count(array_unique($ids, SORT_REGULAR)) !== count($current) || array_diff($ids, $current)) return;
        $byId = array_column($this->segments, null, 'id');
        $this->segments = array_map(fn ($id) => $byId[$id], $ids);
    }
    public function removeReferenceAudio(): void
    {
        $this->referenceAudio = null;
        $this->dispatch('theta-reference-audio-cleared');
    }
    public function saveReference(): void
    {
        abort_unless($this->isClone, 404);
        $this->validate(['referenceAudio' => 'required|file|max:20480']);
        try {
            $file = app(MultiSpeakerReferences::class)->upload(auth('app')->user(), $this->referenceAudio);
            foreach ($this->segments as &$segment) {
                if (empty($segment['reference_id'])) { $segment['reference_id'] = $file->id; break; }
            }
            unset($segment);
            $this->removeReferenceAudio();
            unset($this->references);
            $this->submissionError = '';
            $this->dispatch('app-header-refresh');
        } catch (\Illuminate\Validation\ValidationException $e) { throw $e;
        } catch (\Throwable $e) { $this->submissionError = \App\Support\CustomerFacingError::message($e->getMessage()); }
    }
    public function submitProject(): void
    {
        if ($this->currentJob?->isActive()) return;
        $this->submissionError = '';
        try {
            $job = app(MultiSpeakerSubmissionService::class)->submit(auth('app')->user(), $this->serviceSlug, $this->toolSlug, $this->submissionKey, $this->segments);
            $this->currentJobId = (string) $job->id;
            unset($this->currentJob, $this->presentation, $this->recentRenders);
            if ($job->provider_job_id || $job->status === 'failed') $this->submissionKey = (string) Str::uuid();
            $this->resetPage('multiRenders');
            $this->dispatch('app-header-refresh');
        } catch (\Illuminate\Validation\ValidationException $e) { throw $e;
        } catch (\Throwable $e) { $this->submissionError = \App\Support\CustomerFacingError::message($e->getMessage()); }
    }
    public function pollProject(): void
    {
        $job = $this->currentJob;
        $previousStatus = $job?->status;
        if ($job?->isActive()) app(XttsJobSyncService::class)->sync($job, $job->tool);
        unset($this->currentJob, $this->presentation, $this->recentRenders);
        if ($this->currentJob?->status !== $previousStatus) $this->dispatch('app-header-refresh');
    }
    private function jobs()
    {
        return MlJob::query()->where('customer_id', auth('app')->id())->whereHas('tool', fn ($q) => $q->where('code', $this->definition['legacy_tool']))->whereNull('input->api_job_id');
    }
    #[Computed] public function recentRenders()
    {
        $active = $this->jobs()->active()->exists();
        $page = clone app(CttsWorkspaceCache::class)->recentRenders((int) auth('app')->id(), $this->definition['legacy_tool'], $this->getPage('multiRenders'), $active,
            fn () => $this->jobs()->whereNotIn('status', ['deleted', 'deleting'])->latest()->paginate(3, pageName: 'multiRenders'));
        $files = CustomerFile::query()->where('customer_id', auth('app')->id())->whereIn('path', $page->getCollection()->pluck('output.path')->filter())->get()->keyBy('path');
        $page->setCollection($page->getCollection()->map(function ($job) use ($files) {
            $status = app(MetKurdV2JobStatusPresentation::class)->for($job->status);
            $file = $files->get(data_get($job->output, 'path'));
            $available = $job->status === 'done' && $file && $file->status === 'active' && (! $file->expires_at || $file->expires_at->isFuture());
            $parameters = ['locale' => app()->getLocale(), 'jobId' => $job->id];
            return ['id' => $job->id, 'label' => __($this->definition['name']), 'status' => $status['status'], 'status_label' => $status['label'], 'status_semantic' => $status['semantic'],
                'text' => Str::limit((string) data_get($job->input, 'segments.0.text'), 92),
                'speaker' => __(':count segments · :chars characters', ['count' => data_get($job->input, 'segment_count'), 'chars' => data_get($job->input, 'total_chars')]),
                'when' => ($job->finished_at ?? $job->created_at)->diffForHumans(), 'output_unavailable' => $job->status === 'done' && ! $available,
                'stream_url' => $available ? route('app.renders.'.$this->definition['legacy_tool'].'.stream', $parameters).'?proxy=1' : null,
                'download_url' => $available ? route('app.renders.'.$this->definition['legacy_tool'].'.download', $parameters) : null];
        }));
        return $page;
    }
    public function previousRecentRendersPage(): void { $this->previousPage('multiRenders'); }
    public function nextRecentRendersPage(): void { $this->nextPage('multiRenders'); }
};
?>

<section class="v2-tool-page v2-multi-speaker-page">
    <nav class="v2-breadcrumb"><a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}">{{ __('MetKurd AI') }}</a><span>/</span><a wire:navigate href="{{ route('app.v2.service', ['locale' => app()->getLocale(), 'service' => $serviceSlug]) }}">{{ __($serviceDefinition['name']) }}</a><span>/</span><span>{{ __($definition['name']) }}</span></nav>
    <header class="v2-tool-context"><div class="v2-tool-identity d-flex align-items-center gap-3"><img class="v2-service-icon" src="{{ asset($serviceDefinition['icon_asset']) }}" alt=""><div><span>{{ __($serviceDefinition['name']) }}</span><h1>{{ __($definition['name']) }}</h1></div></div>@livewire('app::v2.components.shared.account-resources')</header>
    <div class="v2-workspace {{ $this->isClone ? 'v2-theta-workspace' : '' }}">
        <aside class="v2-workspace-panel p-3">
            <div class="v2-panel-heading"><span>{{ __('Project voices') }}</span></div>
            @if($this->isClone)
                <p>{{ __('Save a reference once, then select it in any segment.') }}</p>
                <div wire:ignore><input type="file" id="v2-theta-reference-pond" aria-label="{{ __('Reference audio') }}" data-upload-error="{{ __('Upload failed') }}" data-label-idle="{{ __('Drop a reference audio file') }} &lt;span class=&quot;filepond--label-action&quot;&gt;{{ __('Browse') }}&lt;/span&gt;" accept=".wav,.mp3,.m4a,.aac,.ogg,.webm,audio/*"></div>
                <button type="button" class="btn btn-danger" wire:click="saveReference" wire:loading.attr="disabled" @disabled(!$referenceAudio)>{{ __('Save reference') }}</button>
                <p class="v2-muted mt-2">{{ __('Saved references remain in your storage until you delete them.') }}</p>
                @foreach(collect($this->references)->whereIn('id', array_column($segments, 'reference_id')) as $reference)
                    <div class="v2-render-item" wire:key="pool-reference-{{ $reference['id'] }}">
                        <p dir="auto">{{ $reference['name'] }}</p>
                        <div wire:ignore data-metkurd-waveform data-accent="danger" data-job="theta-reference-{{ $reference['id'] }}" data-url="{{ route('app.ctts-references.stream', ['locale' => app()->getLocale(), 'file' => $reference['id']]) }}">
                            <button type="button" class="v2-waveform-toggle" data-metkurd-waveform-toggle aria-label="{{ __('Play or pause audio') }}"><i class="ri-play-fill" data-metkurd-waveform-icon></i></button>
                            <span class="v2-waveform-time" data-metkurd-waveform-time>00:00 / --:--</span><div class="v2-waveform-canvas" data-metkurd-waveform-canvas></div>
                        </div>
                    </div>
                @endforeach
            @else
                <p>{{ __('Choose an existing voice for each segment. Segments play in the order shown.') }}</p>
            @endif
            <p class="v2-muted">{{ __('Up to :segments segments, :per characters each and :total characters per project.', ['segments' => config('metkurd_v2.multi_speaker.max_segments'), 'per' => config('metkurd_v2.multi_speaker.max_segment_chars'), 'total' => config('metkurd_v2.multi_speaker.max_total_chars')]) }}</p>
        </aside>
        <main class="v2-workspace-panel v2-create-panel">
            <div class="v2-panel-heading"><span>{{ __('Create audio project') }}</span></div>
            <div data-multi-segments>
                @foreach($segments as $index => $segment)
                    <article class="v2-segment border rounded p-3 mb-3" wire:key="segment-{{ $segment['id'] }}" data-segment-id="{{ $segment['id'] }}">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <button type="button" draggable="true" data-segment-handle class="btn btn-sm btn-outline-light" aria-label="{{ __('Drag to reorder') }}">☰</button>
                            <strong>{{ __('Segment :number', ['number' => $index + 1]) }}</strong>
                            <button type="button" class="btn btn-sm btn-outline-light" wire:click="moveSegment('{{ $segment['id'] }}', -1)" @disabled($index === 0) aria-label="{{ __('Move up') }}">↑</button>
                            <button type="button" class="btn btn-sm btn-outline-light" wire:click="moveSegment('{{ $segment['id'] }}', 1)" @disabled($loop->last) aria-label="{{ __('Move down') }}">↓</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="deleteSegment('{{ $segment['id'] }}')">{{ __('Delete') }}</button>
                        </div>
                        <div class="row g-2 mb-2"><div class="col-sm-7">
                            <label class="form-label" for="voice-{{ $segment['id'] }}">{{ __('Reference voice') }}</label>
                            @if($this->isClone)
                                <select id="voice-{{ $segment['id'] }}" class="form-select v2-control" wire:model.change="segments.{{ $index }}.reference_id"><option value="">{{ __('Choose a reference') }}</option>@foreach($this->references as $reference)<option value="{{ $reference['id'] }}">{{ $reference['name'] }}</option>@endforeach</select>
                            @else
                                <select id="voice-{{ $segment['id'] }}" class="form-select v2-control" wire:model.change="segments.{{ $index }}.voice"><option value="">{{ __('Choose a voice') }}</option>@foreach($this->voices as $group)<optgroup label="{{ $group['label'] }}">@foreach($group['speakers'] as $voice)<option value="{{ $voice['code'] }}">{{ $voice['name'] }} {{ $voice['style'] }}</option>@endforeach</optgroup>@endforeach</select>
                            @endif
                        </div><div class="col-sm-5"><label class="form-label" for="language-{{ $segment['id'] }}">{{ __('Generation language') }}</label><select id="language-{{ $segment['id'] }}" class="form-select v2-control" wire:model.change="segments.{{ $index }}.language"><option value="ckb">{{ __('Kurdish / Sorani') }}</option><option value="en">{{ __('English') }}</option><option value="ar">{{ __('Arabic') }}</option></select></div></div>
                        @if($this->isClone)<label class="form-label" for="reference-text-{{ $segment['id'] }}">{{ __('Reference transcript (optional)') }}</label><textarea id="reference-text-{{ $segment['id'] }}" class="form-control v2-control mb-2" dir="auto" maxlength="4000" rows="2" wire:model.blur="segments.{{ $index }}.ref_text"></textarea>@endif
                        <label class="form-label" for="text-{{ $segment['id'] }}">{{ __('Text') }}</label>
                        <textarea id="text-{{ $segment['id'] }}" class="v2-audio-editor" dir="auto" rows="4" maxlength="{{ config('metkurd_v2.multi_speaker.max_segment_chars') }}" wire:model.live.debounce.400ms="segments.{{ $index }}.text"></textarea>
                        <label class="form-label" for="pause-{{ $segment['id'] }}">{{ __('Pause after') }}</label>
                        @if($loop->last)
                            <select id="pause-{{ $segment['id'] }}" class="form-select v2-control" disabled><option value="0">{{ __('No pause') }}</option></select>
                        @else
                            <select id="pause-{{ $segment['id'] }}" class="form-select v2-control" wire:model.change="segments.{{ $index }}.pause_after_ms"><option value="0">{{ __('No pause') }}</option><option value="500">{{ __('0.5 seconds') }}</option><option value="1000">{{ __('1 second') }}</option><option value="2000">{{ __('2 seconds') }}</option></select>
                        @endif
                        @if($loop->last)<small class="v2-muted">{{ __('The final segment has no trailing pause.') }}</small>@endif
                    </article>
                @endforeach
            </div>
            <button type="button" class="btn btn-outline-light mb-3" wire:click="addSegment" @disabled(count($segments) >= config('metkurd_v2.multi_speaker.max_segments'))>{{ __('Add segment') }}</button>
            <div class="v2-editor-footer"><span>{{ __(':count segments · :chars characters', ['count' => count($segments), 'chars' => $this->totalChars]) }}</span><span>{{ __('Estimated cost: :cost credits', ['cost' => number_format($this->creditsCost)]) }}</span></div>
            @if($errors->any())<div class="alert alert-danger mt-3" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
            @if($submissionError)<div class="alert alert-danger mt-3" role="alert">{{ $submissionError }}</div>@endif
            <div class="v2-create-actions"><button type="button" class="btn btn-{{ $serviceDefinition['color'] }}" wire:click="submitProject" wire:loading.attr="disabled" @disabled($this->presentation['is_active'] || !$segments)>{{ __('Generate full project') }}</button>
                @if($this->currentJob)<span class="v2-job-state glass-load {{ $this->presentation['glass_class'] }}" @if($this->presentation['is_active']) wire:poll.5s="pollProject" @endif>{{ $this->presentation['label'] }}</span>@endif
            </div>
            @if($this->currentJob?->status === 'failed')<div class="alert alert-danger mt-3">{{ \App\Support\CustomerFacingError::message(data_get($this->currentJob->error, 'message')) }} @if(is_int(data_get($this->currentJob->error, 'failed_segment.index'))){{ __('Segment :number', ['number' => data_get($this->currentJob->error, 'failed_segment.index') + 1]) }}@endif</div>@endif
            @if($this->currentJob?->failure_stage === 'provider_submission_unknown')<div class="alert alert-warning mt-3">{{ __('Processing status is being reviewed. Please do not resubmit.') }}</div>@endif
        </main>
        @include('app.v2.components.shared.recent-renders', ['renders' => $this->recentRenders, 'locale' => app()->getLocale(), 'subtitle' => __('Your project history'), 'accent' => $serviceDefinition['color'], 'keyPrefix' => 'multi-render'])
    </div>
</section>

@push('styles')
    @if($this->isClone)<link href="{{ asset('app/libs/filepond/filepond.min.css') }}" rel="stylesheet">@endif
    <style>
        .metkurd-v2 .v2-multi-speaker-page .v2-audio-editor { min-height: 8rem; border: 1px solid rgba(var(--v2-accent-rgb),.35); border-radius: .6rem; margin-bottom: .75rem; }
        .metkurd-v2 .v2-multi-speaker-page .v2-segment > .d-flex { flex-wrap: wrap; }
        .metkurd-v2 .v2-multi-speaker-page [data-segment-handle] { cursor: grab; }
        .metkurd-v2 .v2-multi-speaker-page .v2-audio-editor:focus-visible { outline: 2px solid var(--v2-accent-text); }
    </style>
@endpush
