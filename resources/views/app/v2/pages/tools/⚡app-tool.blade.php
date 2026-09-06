<?php

use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Services\MetKurd\Jobs\OmniSubmissionService;
use App\Services\MetKurd\Jobs\CloneOmniSubmissionService;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use App\Services\MetKurd\V2\CttsWorkspaceCache;
use App\Services\XTTS\XttsJobSyncService;
use App\Support\MetKurdV2ToolCatalog;
use App\Support\AppToolCatalog;
use App\Support\MetKurdV2JobStatusPresentation;
use Illuminate\Support\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::v2.layouts.app')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    protected $paginationTheme = 'bootstrap';

    #[\Livewire\Attributes\Locked] public string $serviceSlug;
    #[\Livewire\Attributes\Locked] public string $toolSlug;
    #[\Livewire\Attributes\Locked] public array $serviceDefinition = [];
    #[\Livewire\Attributes\Locked] public array $toolDefinition = [];
    public string $text = '';
    public string $speakerId = '';
    public string $language = 'ckb';
    #[\Livewire\Attributes\Locked] public string $submissionKey = '';
    #[\Livewire\Attributes\Locked] public int $maxCharacters = 400;
    public int $creditsCost = 0;
    #[\Livewire\Attributes\Locked] public array $speakerGroups = [];
    public string $expandedSpeakerGroup = '';
    public ?string $currentJobId = null;
    public array $currentJob = [];
    public ?string $submissionError = null;
    public ?string $queuedSince = null;
    public $referenceAudio = null;
    public ?int $selectedReferenceId = null;
    public ?string $referenceAudioName = null;
    public ?int $referenceAudioBytes = null;
    public ?string $referenceAudioMime = null;

    public function mount(string $service, string $tool, MetKurdV2ToolCatalog $catalog, OmniSpeakerCatalog $speakers): void
    {
        abort_unless($catalog->isKnownTool($service, $tool), 404);
        $definition = $catalog->tool($service, $tool);
        abort_if(($definition['coming_soon'] ?? false) === true, 404);
        abort_unless(in_array($definition['kind'] ?? null, ['omni_tts', 'omni_clone'], true), 404);
        $this->serviceSlug = $service;
        $this->toolSlug = $tool;
        $this->serviceDefinition = $catalog->service($service);
        $this->toolDefinition = $definition;
        $this->submissionKey = (string) Str::uuid();

        if (in_array($definition['kind'] ?? null, ['omni_tts', 'omni_clone'], true)) {
            $customer = auth('app')->user();
            $this->maxCharacters = $customer ? app(\App\Services\MetKurd\V2\InputBoundary::class)->characterLimit($customer, (string) $definition['legacy_action']) : 400;
            if (($definition['kind'] ?? null) === 'omni_tts') {
                $this->speakerGroups = $speakers->forCustomer($customer, app()->getLocale());
                $this->expandedSpeakerGroup = (string) array_key_first($this->speakerGroups);
                $this->speakerId = (string) data_get($this->speakerGroups, "{$this->expandedSpeakerGroup}.speakers.0.code", '');
            }
        }
    }

    #[Computed]
    public function currentChars(): int
    {
        return mb_strlen(trim($this->text));
    }

    public function updatedText(): void
    {
        $this->refreshCost();
    }

    public function updatedLanguage(): void
    {
        $this->refreshCost();
    }

    public function toggleSpeakerGroup(string $group): void
    {
        if (! array_key_exists($group, $this->speakerGroups)) {
            return;
        }

        $this->expandedSpeakerGroup = $this->expandedSpeakerGroup === $group ? '' : $group;
    }

    public function selectSpeaker(string $speaker): void
    {
        if (! $this->speaker($speaker)) {
            return;
        }

        $this->speakerId = $speaker;
        $this->refreshCost();
    }

    public function updatedReferenceAudio(): void
    {
        if (($this->toolDefinition['kind'] ?? null) !== 'omni_clone') return;
        $this->validateOnly('referenceAudio', ['referenceAudio' => 'nullable|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm|max:20480']);
        $this->selectedReferenceId = null;
        $this->referenceAudioName = $this->referenceAudio?->getClientOriginalName();
        $this->referenceAudioBytes = $this->referenceAudio ? (int) $this->referenceAudio->getSize() : null;
        $this->referenceAudioMime = $this->referenceAudio?->getMimeType();
    }

    public function selectReference(int $referenceId): void
    {
        if (! $this->referenceHistory()->firstWhere('id', $referenceId)) {
            $this->submissionError = __('That saved reference voice is no longer available.');
            return;
        }
        $this->selectedReferenceId = $referenceId;
        $this->removeReferenceAudio();
        $this->dispatch('ctts-reference-selected', referenceId: $referenceId);
    }

    public function removeReferenceAudio(): void
    {
        $this->referenceAudio = null;
        $this->referenceAudioName = null;
        $this->referenceAudioBytes = null;
        $this->referenceAudioMime = null;
        $this->dispatch('ctts-reference-audio-cleared');
    }

    public function useAnotherReference(): void
    {
        $this->selectedReferenceId = null;
        $this->removeReferenceAudio();
    }

    public function submitOmni(OmniSubmissionService $submissions): void
    {
        $this->submissionError = null;
        $this->validate([
            'text' => ['required', 'string', 'min:1', 'max:'.$this->maxCharacters],
            'speakerId' => ['required', 'string'],
            'language' => ['required', 'in:ckb,en,ar'],
        ]);

        $speaker = $this->speaker($this->speakerId);
        if (! $speaker) {
            $this->submissionError = __('The selected voice is no longer available.');

            return;
        }

        try {
            $input = app(\App\Services\MetKurd\V2\InputBoundary::class)->text(auth('app')->user(), (string) $this->toolDefinition['legacy_action'], ['text' => $this->text, 'voice' => $this->speakerId, 'language' => $this->language], true);
            $job = $submissions->submit(auth('app')->user(), $this->serviceSlug, $this->toolSlug, $this->submissionKey, $input);
            $this->currentJobId = (string) $job->id;
            if ($job->provider_job_id) $this->submissionKey = (string) Str::uuid();
            $this->queuedSince = null;
            $this->syncCurrentJobState();
            $this->resetPage(pageName: 'v2ApolloRendersPage');
            $this->dispatch('header:refresh');
        } catch (\Throwable $exception) {
            $this->submissionError = \App\Support\CustomerFacingError::message($exception->getMessage());
        }
    }

    public function submitClone(CloneOmniSubmissionService $submissions): void
    {
        $this->submissionError = null;
        $this->validate([
            'text' => ['required', 'string', 'min:1', 'max:'.$this->maxCharacters],
            'language' => ['required', 'in:ckb,en,ar'],
            'referenceAudio' => ['nullable', 'file', 'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm', 'max:20480'],
        ]);
        try {
            $boundary = app(\App\Services\MetKurd\V2\InputBoundary::class);
            $input = $boundary->text(auth('app')->user(), (string) $this->toolDefinition['legacy_action'], ['text' => $this->text, 'language' => $this->language]);
            if ($this->referenceAudio) $boundary->audio($this->referenceAudio, true);
            elseif ($this->selectedReferenceId) $boundary->reference(auth('app')->user(), $this->selectedReferenceId);
            $job = $submissions->submit(auth('app')->user(), $this->serviceSlug, $this->toolSlug, $this->submissionKey, $input, $this->referenceAudio, $this->selectedReferenceId);
            $this->currentJobId = (string) $job->id;
            $this->queuedSince = null;
            $this->syncCurrentJobState();
            if ((string) $job->status === 'failed') {
                $this->submissionError = (string) data_get($job->error, 'message', __('The CTTS job could not be started.'));
            }
            $this->resetPage(pageName: 'v2CttsRendersPage');
            $this->submissionKey = (string) Str::uuid();
            $this->dispatch('header:refresh');
        } catch (\Throwable $exception) {
            $this->submissionError = \App\Support\CustomerFacingError::message($exception->getMessage());
        }
    }

    public function pollOmni(XttsJobSyncService $sync): void
    {
        if (! $this->currentJobId) {
            return;
        }

        $job = MlJob::query()->with('tool')->whereKey($this->currentJobId)->where('customer_id', auth('app')->id())->first();
        $previousStatus = $job?->status;
        if ($job?->tool && $job->isActive()) {
            $sync->sync($job, $job->tool);

        }

        $this->syncCurrentJobState();
        if ($job && $previousStatus !== ($this->currentJob['status'] ?? null)) $this->dispatch('header:refresh');
    }

    public function selectedSpeakerName(): string
    {
        return (string) data_get($this->speaker($this->speakerId), 'name', __('Choose a voice'));
    }

    protected function refreshCost(): void
    {
        $customer = auth('app')->user();
        $this->creditsCost = $customer && $this->currentChars > 0
            ? max(0, (int) $customer->priceCreditsFor((string) ($this->toolDefinition['legacy_action'] ?? ''), ['chars' => $this->currentChars, 'metric_code' => 'character', 'language' => $this->language, 'speaker_id' => $this->speakerId]))
            : 0;
    }

    /** @return array<string,mixed>|null */
    protected function speaker(string $code): ?array
    {
        foreach ($this->speakerGroups as $group) {
            foreach ($group['speakers'] as $speaker) {
                if (($speaker['code'] ?? '') === $code) {
                    return $speaker;
                }
            }
        }

        return null;
    }

    protected function syncCurrentJobState(): void
    {
        $job = $this->currentJobId
            ? MlJob::query()->whereKey($this->currentJobId)->where('customer_id', auth('app')->id())->first()
            : null;
        $status = (string) ($job?->status ?? '');
        $this->currentJob = $job ? ['status' => $status, 'message' => (string) data_get($job->error, 'customer_message', data_get($job->error, 'message'))] : [];

        if ($status === 'queued') {
            $this->queuedSince ??= now()->toIso8601String();
        } else {
            $this->queuedSince = null;
        }
    }

    #[Computed]
    public function recentRenders()
    {
        $customerId = auth('app')->id();
        $toolCode = (string) ($this->toolDefinition['legacy_tool'] ?? '');
        $isClone = ($this->toolDefinition['kind'] ?? null) === 'omni_clone';
        $toolIds = [app(AppToolCatalog::class)->toolId($toolCode)];
        $streamRoute = $toolCode === 'xomni-v2'
            ? 'app.renders.xomni-v2.stream'
            : 'app.renders.xomni.stream';
        $downloadRoute = $toolCode === 'xomni-v2'
            ? 'app.renders.xomni-v2.download'
            : 'app.renders.xomni.download';
        $pageName = $isClone ? 'v2CttsRendersPage' : 'v2ApolloRendersPage';
        if (! $customerId || empty(array_filter($toolIds))) {
            return MlJob::query()->whereRaw('1 = 0')->paginate(3, pageName: $pageName);
        }

        $locale = app()->getLocale();
        $statusPresentation = app(MetKurdV2JobStatusPresentation::class);
        $hasActiveJobs = MlJob::query()->where('customer_id', $customerId)->whereIn('tool_id', $toolIds)->whereIn('status', ['queued', 'submitting', 'running', 'saving'])->exists();
        $resolvePage = fn () => MlJob::query()
            ->where('customer_id', $customerId)
            ->whereIn('tool_id', $toolIds)
            ->whereIn('status', ['queued', 'submitting', 'running', 'saving', 'done', 'failed', 'cancelled', 'canceled'])
            ->latest('updated_at')
            ->paginate(3, ['id', 'tool_id', 'status', 'input', 'output', 'error', 'created_at', 'updated_at', 'finished_at', 'model_key'], $pageName);
        $paginator = $isClone
            ? app(CttsWorkspaceCache::class)->recentRenders((int) $customerId, $toolCode, $this->getPage($pageName), $hasActiveJobs, $resolvePage)
            : $resolvePage();

        $toolCodesById = $isClone ? \App\Models\Tool::query()->whereIn('id', $toolIds)->pluck('code', 'id')->all() : [];

        $outputPaths = $paginator->getCollection()
            ->map(fn ($job) => trim((string) data_get($job, 'output.path')))
            ->filter()
            ->unique()
            ->values();

        $storageRecordsByPath = CustomerFile::query()
            ->where('customer_id', $customerId)
            ->whereIn('path', $outputPaths)
            ->get(['id', 'path', 'status'])
            ->groupBy('path');

        $paginator->setCollection(
            $paginator->getCollection()->map(function ($job) use ($locale, $statusPresentation, $storageRecordsByPath, $streamRoute, $downloadRoute, $isClone, $toolCodesById, $toolCode): array {
                $jobToolCode = (string) ($toolCodesById[(int) data_get($job, 'tool_id')] ?? $toolCode ?? '');
                $jobStreamRoute = $isClone ? ($jobToolCode === 'vector-v2' ? 'app.renders.vector-v2.stream' : ($jobToolCode === 'clone_xomni' ? 'app.renders.clone_xomni.stream' : 'app.renders.clone_xtts.stream')) : $streamRoute;
                $jobDownloadRoute = $isClone ? ($jobToolCode === 'vector-v2' ? 'app.renders.vector-v2.download' : ($jobToolCode === 'clone_xomni' ? 'app.renders.clone_xomni.download' : 'app.renders.clone_xtts.download')) : $downloadRoute;
                $text = trim((string) data_get($job, 'input.text'));
                $path = (string) data_get($job, 'output.path');
                $status = (string) data_get($job, 'status');
                $presentation = $statusPresentation->for($status);
                $records = $storageRecordsByPath->get($path, collect());
                $outputUnavailable = $status === 'done' && ($path === '' || ($records->isNotEmpty() && ! $records->contains('status', 'active')));
                return [
                    'id' => (string) data_get($job, 'id'),
                    'label' => $isClone ? ($jobToolCode === 'vector-v2' ? __('Vector 2.0v') : ($jobToolCode === 'clone_xomni' ? __('Vector 1.5v') : __('Vector 1.0v'))) : (string) ($this->toolDefinition['name'] ?? __('Apollo')),
                    'status' => $presentation['status'],
                    'status_label' => $presentation['label'],
                    'status_semantic' => $presentation['semantic'],
                    'text' => $text === '' ? __('Audio render') : Str::limit($text, 92),
                    'speaker' => (string) (data_get($job, 'input.reference_audio_name') ?: data_get($job, 'input.speaker_id', __('Reference voice'))),
                    'when' => ($when = data_get($job, 'finished_at') ?: data_get($job, 'updated_at') ?: data_get($job, 'created_at')) ? Carbon::parse($when)->diffForHumans() : '',
                    'output_unavailable' => $outputUnavailable,
                    'stream_url' => $status === 'done' && ! $outputUnavailable ? route($jobStreamRoute, ['locale' => $locale, 'jobId' => data_get($job, 'id')]).'?proxy=1' : null,
                    'download_url' => $status === 'done' && ! $outputUnavailable ? route($jobDownloadRoute, ['locale' => $locale, 'jobId' => data_get($job, 'id')]) : null,
                ];
            })
        );

        return $paginator;
    }

    public function previousRecentRendersPage(): void
    {
        $this->previousPage(pageName: ($this->toolDefinition['kind'] ?? null) === 'omni_clone' ? 'v2CttsRendersPage' : 'v2ApolloRendersPage');
    }

    public function nextRecentRendersPage(): void
    {
        $this->nextPage(pageName: ($this->toolDefinition['kind'] ?? null) === 'omni_clone' ? 'v2CttsRendersPage' : 'v2ApolloRendersPage');
    }

    #[Computed]
    public function referenceHistory()
    {
        $customerId = (int) auth('app')->id();
        if (! $customerId) return collect();
        return collect(app(CttsWorkspaceCache::class)->references($customerId))->map(function (array $reference): array {
            return [
                ...$reference,
                'when' => $reference['updated_at'] ? Carbon::parse($reference['updated_at'])->diffForHumans() : '',
                // Same-origin streaming avoids object-store CORS and re-checks customer ownership per request.
                'preview_url' => route('app.ctts-references.stream', ['locale' => app()->getLocale(), 'file' => $reference['id']]),
            ];
        });
    }

    #[Computed]
    public function referencePage(): LengthAwarePaginator
    {
        $pageName = 'v2CttsReferencesPage';
        $references = $this->referenceHistory();
        $page = $this->getPage($pageName);

        return new LengthAwarePaginator(
            $references->forPage($page, 6)->values(),
            $references->count(),
            6,
            $page,
            ['path' => request()->url(), 'pageName' => $pageName],
        );
    }

    public function previousReferencePage(): void
    {
        $this->previousPage('v2CttsReferencesPage');
    }

    public function nextReferencePage(): void
    {
        $this->nextPage('v2CttsReferencesPage');
    }

    #[Computed]
    public function selectedReference(): ?array
    {
        return $this->selectedReferenceId ? $this->referenceHistory()->firstWhere('id', $this->selectedReferenceId) : null;
    }

    #[Computed]
    public function currentJobPresentation(): array
    {
        return app(MetKurdV2JobStatusPresentation::class)->for((string) ($this->currentJob['status'] ?? 'idle'));
    }

    #[Computed]
    public function showQueueMessage(): bool
    {
        return ($this->currentJob['status'] ?? null) === 'queued'
            && $this->queuedSince !== null
            && Carbon::parse($this->queuedSince)->lte(now()->subSeconds(5));
    }
};
?>

<section class="v2-tool-page">
    <nav class="v2-breadcrumb" aria-label="breadcrumb"><a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}">{{ __('MetKurd AI') }}</a><span>/</span><a wire:navigate href="{{ route('app.v2.service', ['locale' => app()->getLocale(), 'service' => $serviceSlug]) }}">{{ __($serviceDefinition['name']) }}</a><span>/</span><span>{{ __($toolDefinition['name']) }}</span></nav>
    <header class="v2-tool-context"><div class="v2-tool-identity d-flex align-items-center gap-3"><img class="v2-service-icon" src="{{ asset($serviceDefinition['icon_asset']) }}" alt=""><div><span>{{ __($serviceDefinition['name']) }}</span><h1>{{ __($toolDefinition['name']) }}</h1></div></div>@livewire('app::v2.components.shared.account-resources')</header>

    @if (($toolDefinition['kind'] ?? null) === 'omni_tts')
        <div class="v2-workspace">
            @include('app.v2.components.xomni-tts.speaker-picker', ['groups' => $speakerGroups, 'selected' => $speakerId, 'expanded' => $expandedSpeakerGroup])
            <main class="v2-workspace-panel v2-create-panel">
                <div class="v2-panel-heading"><span>{{ __('Create audio') }}</span><small>{{ __('Selected voice: :voice', ['voice' => $this->selectedSpeakerName()]) }}</small></div>
                <div class="v2-editor-wrap"><textarea dir="auto" wire:model.live="text" class="v2-audio-editor" rows="12" maxlength="{{ $maxCharacters }}" placeholder="{{ __('Write the text you want to hear…') }}"></textarea><div class="v2-editor-footer"><span dir="ltr">{{ $this->currentChars }} / {{ $maxCharacters }} {{ __('characters') }}</span><span>{{ __('Estimated cost: :cost credits', ['cost' => number_format($creditsCost)]) }}</span></div></div>
                <div class="row g-3 mt-1"><div class="col-sm-6"><label class="form-label" for="v2-language">{{ __('Generation language') }}</label><select id="v2-language" wire:model.change="language" class="form-select v2-control"><option value="ckb">{{ __('Kurdish / Sorani') }}</option><option value="en">{{ __('English') }}</option><option value="ar">{{ __('Arabic') }}</option></select></div></div>
                @if ($submissionError)<div class="alert alert-danger mt-3 mb-0">{{ \App\Support\CustomerFacingError::message($submissionError) }}</div>@endif
                @php($currentJobPresentation = $this->currentJobPresentation)
                <div class="v2-create-actions {{ $currentJobId ? 'is-'.$currentJobPresentation['semantic'] : '' }}"><button wire:click="submitOmni" wire:loading.attr="disabled" wire:target="submitOmni" class="btn btn-primary waves-effect px-4" @disabled(empty($speakerGroups) || $currentJobPresentation['is_active'])><span wire:loading.remove wire:target="submitOmni">{{ __('Generate audio') }}</span><span wire:loading wire:target="submitOmni">{{ __('Preparing…') }}</span></button>@if($currentJobId)<span class="v2-job-state glass-load {{ $currentJobPresentation['glass_class'] }} {{ $currentJobPresentation['is_active'] ? 'is-active' : '' }}" @if($currentJobPresentation['is_active']) wire:poll.5s="pollOmni" @endif>{{ $currentJobPresentation['label'] }}@if($currentJob['message'] ?? false): {{ \App\Support\CustomerFacingError::message($currentJob['message']) }}@endif</span>@endif</div>
                @if($this->showQueueMessage)<div dir="{{ in_array(app()->getLocale(), ['ar', 'ku'], true) ? 'rtl' : 'ltr' }}" class="v2-queue-message glass-load glass-load--warning"><i class="ri-time-line" aria-hidden="true"></i><span>{{ __('MetKurd AI GPUs are currently busy. Your job is queued and will start automatically as soon as capacity is available.') }}</span></div>@endif
            </main>
            @include('app.v2.components.shared.recent-renders', ['renders' => $this->recentRenders, 'locale' => app()->getLocale(), 'subtitle' => __('Your Apollo history')])
        </div>
    @elseif (($toolDefinition['kind'] ?? null) === 'omni_clone')
        <div class="v2-workspace v2-ctts-workspace">
            @include('app.v2.components.ctts.reference-history', ['references' => $this->referencePage, 'selectedId' => $selectedReferenceId])
            <main class="v2-workspace-panel v2-create-panel v2-ctts-create-panel">
                @include('app.v2.components.ctts.reference-upload', ['selectedReference' => $this->selectedReference, 'referenceAudioName' => $referenceAudioName, 'referenceAudioBytes' => $referenceAudioBytes, 'referenceAudioMime' => $referenceAudioMime])
                <div class="v2-editor-wrap v2-ctts-editor-wrap"><textarea dir="auto" wire:model.live="text" class="v2-audio-editor" rows="8" maxlength="{{ $maxCharacters }}" placeholder="{{ __('Enter the text that should be spoken using the cloned voice…') }}"></textarea><div class="v2-editor-footer"><span dir="ltr">{{ $this->currentChars }} / {{ $maxCharacters }} {{ __('characters') }}</span><span>{{ __('Estimated cost: :cost credits', ['cost' => number_format($creditsCost)]) }}</span></div></div>
                <div class="row g-3 mt-1"><div class="col-sm-6"><label class="form-label" for="v2-ctts-language">{{ __('Generation language') }}</label><select id="v2-ctts-language" wire:model.change="language" class="form-select v2-control"><option value="ckb">{{ __('Kurdish / Sorani') }}</option><option value="en">{{ __('English') }}</option><option value="ar">{{ __('Arabic') }}</option></select></div></div>
                @if ($submissionError)<div class="alert alert-danger mt-3 mb-0">{{ \App\Support\CustomerFacingError::message($submissionError) }}</div>@endif
                @php($currentJobPresentation = $this->currentJobPresentation)
                <div class="v2-create-actions v2-ctts-actions {{ $currentJobId ? 'is-'.$currentJobPresentation['semantic'] : '' }}"><button wire:click="submitClone" wire:loading.attr="disabled" wire:target="submitClone,referenceAudio" class="btn btn-danger waves-effect px-4" @disabled((!$selectedReferenceId && !$referenceAudio) || $currentJobPresentation['is_active'])><span wire:loading.remove wire:target="submitClone,referenceAudio">{{ __('Generate cloned speech') }}</span><span wire:loading wire:target="submitClone,referenceAudio">{{ __('Preparing…') }}</span></button>@if($currentJobId)<span class="v2-job-state glass-load {{ $currentJobPresentation['glass_class'] }} {{ $currentJobPresentation['is_active'] ? 'is-active' : '' }}" @if($currentJobPresentation['is_active']) wire:poll.5s="pollOmni" @endif>{{ $currentJobPresentation['label'] }}@if($currentJob['message'] ?? false): {{ \App\Support\CustomerFacingError::message($currentJob['message']) }}@endif</span>@endif</div>
            </main>
            @include('app.v2.components.shared.recent-renders', ['renders' => $this->recentRenders, 'locale' => app()->getLocale(), 'subtitle' => __('Your CTTS history'), 'accent' => 'danger', 'keyPrefix' => 'v2-ctts-render'])
        </div>
    @endif
</section>

@if (in_array(($toolDefinition['kind'] ?? null), ['omni_tts', 'omni_clone'], true))
    @push('scripts')
        <script data-navigate-once src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js" onload="window.dispatchEvent(new CustomEvent('metkurd:wavesurfer-ready'))"></script>
    @endpush
@endif

@if (($toolDefinition['kind'] ?? null) === 'kocr')
    @push('styles')
        <style>
            .metkurd-v2 .v2-asr-intelligent { display:flex; justify-content:space-between; gap:1rem; align-items:center; padding:.75rem; border:1px solid rgba(var(--v2-accent-rgb),.18); border-radius:.75rem; cursor:pointer; }
            .metkurd-v2 .v2-asr-intelligent>span:first-child { display:grid; gap:.14rem; }
            .metkurd-v2 .v2-asr-intelligent small { color:rgba(226,232,240,.55); font-size:.7rem; }
            .metkurd-v2 .v2-asr-intelligent em { padding:.12rem .34rem; border-radius:999px; background:rgba(245,158,11,.16); color:#fde68a; font-size:.6rem; font-style:normal; text-transform:uppercase; }
            .metkurd-v2 .v2-ocr-intelligent { min-height:4.35rem; border-color:rgba(var(--v2-accent-rgb),.32); background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.1),rgba(2,6,23,.36)); transition:border-color .16s ease,box-shadow .16s ease; }
            .metkurd-v2 .v2-ocr-intelligent:has(input:checked) { border-color:rgba(var(--v2-accent-rgb),.82); box-shadow:0 0 0 1px rgba(var(--v2-accent-rgb),.14),0 8px 18px rgba(var(--v2-accent-rgb),.08); }
            .metkurd-v2 .v2-asr-switch { position:relative; display:block; flex:0 0 2.65rem; width:2.65rem; height:1.45rem; }
            .metkurd-v2 .v2-asr-switch input { position:absolute; inset:0; z-index:1; width:100%; height:100%; margin:0; opacity:0; cursor:pointer; }
            .metkurd-v2 .v2-asr-switch i { position:absolute; inset:0; border:1px solid rgba(148,163,184,.45); border-radius:999px; background:rgba(15,23,42,.9); transition:border-color .16s ease,background .16s ease; }
            .metkurd-v2 .v2-asr-switch i::after { position:absolute; top:3px; left:3px; width:calc(1.45rem - 8px); height:calc(1.45rem - 8px); border-radius:50%; background:#94a3b8; box-shadow:0 1px 5px rgba(0,0,0,.4); content:""; transition:transform .16s ease,background .16s ease; }
            .metkurd-v2 .v2-asr-switch input:checked + i { border-color:rgba(var(--v2-accent-rgb),.9); background:rgba(var(--v2-accent-rgb),.7); }
            .metkurd-v2 .v2-asr-switch input:checked + i::after { transform:translateX(1.18rem); background:#f0fdf4; }
            .metkurd-v2 .v2-asr-switch input:focus-visible + i { outline:2px solid var(--v2-accent-text); outline-offset:3px; }
        </style>
    @endpush
@endif

@if (($toolDefinition['kind'] ?? null) === 'omni_clone')
    @push('styles')<link href="https://unpkg.com/filepond@^4/dist/filepond.min.css" rel="stylesheet">@endpush
    @push('scripts')
        <script data-navigate-once src="https://unpkg.com/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.min.js"></script>
        <script data-navigate-once src="https://unpkg.com/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.min.js"></script>
        <script data-navigate-once src="https://unpkg.com/filepond@^4/dist/filepond.min.js"></script>
        <script data-navigate-once>
        (() => {
            const state = window.__METKURD_V2_CTTS_POND__ ||= {};
            const input = () => document.getElementById('v2-ctts-reference-pond');
            const root = () => document.querySelector('.v2-ctts-workspace')?.closest('[wire\\:id]');
            const component = () => {
                const host = root();
                return host && window.Livewire ? window.Livewire.find(host.getAttribute('wire:id')) : null;
            };
            const destroy = () => {
                const pond = state.pond;
                state.pond = null;
                state.host = null;
                try { pond?.destroy(); } catch (_) {}
            };
            const boot = () => {
                const field = input(), host = root();
                if (!field || !host || !window.FilePond || state.pond || !component()) return;
                if (!state.plugins) { FilePond.registerPlugin(FilePondPluginFileValidateType, FilePondPluginFileValidateSize); state.plugins = true; }
                state.host = host;
                state.pond = FilePond.create(field, {
                    allowMultiple: false, credits: false,
                    acceptedFileTypes: ['audio/wav','audio/x-wav','audio/mpeg','audio/mp3','audio/mp4','audio/x-m4a','audio/aac','audio/ogg','audio/webm'],
                    maxFileSize: '20MB',
                    labelIdle: '<strong>{{ __('Drop a reference audio file') }}</strong><br><span class="filepond--label-action">{{ __('Browse') }}</span>',
                    server: {
                        process: (name, file, meta, load, error, progress, abort) => {
                            // Resolve at upload time: navigation can replace the Livewire component.
                            const lw = component();
                            if (!lw) { error('{{ __('Upload failed') }}'); return { abort }; }
                            lw.upload('referenceAudio', file, temporaryName => load(temporaryName), () => error('{{ __('Upload failed') }}'), event => progress(event.lengthComputable, event.loaded, event.total));
                            return { abort: () => { lw.cancelUpload('referenceAudio'); abort(); } };
                        },
                        revert: (id, load, error) => {
                            const lw = component();
                            if (!lw) { error('{{ __('Upload failed') }}'); return; }
                            Promise.resolve(lw.call('removeReferenceAudio')).then(load, () => error('{{ __('Upload failed') }}'));
                        },
                    },
                });
            };
            const reconcile = () => requestAnimationFrame(() => {
                if (state.pond && (state.host !== root() || state.pond.element?.isConnected === false)) destroy();
                boot();
            });
            const bindLivewire = () => {
                if (!window.Livewire || state.livewireBound) return;
                state.livewireBound = true;
                window.Livewire.on('ctts-reference-audio-cleared', () => state.pond?.removeFiles({ revert: false }));
                window.Livewire.hook('morphed', reconcile);
            };
            if (!state.listeners) {
                state.listeners = true;
                document.addEventListener('livewire:navigating', destroy);
                document.addEventListener('livewire:navigated', () => { bindLivewire(); reconcile(); });
                document.addEventListener('livewire:initialized', () => { bindLivewire(); reconcile(); });
            }
            bindLivewire();
            reconcile();
        })();
        </script>
    @endpush
@endif
