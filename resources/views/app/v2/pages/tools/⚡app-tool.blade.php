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

    public string $serviceSlug;
    public string $toolSlug;
    public array $serviceDefinition = [];
    public array $toolDefinition = [];
    public string $processingMode = 'standard';
    public string $text = '';
    public string $speakerId = '';
    public string $language = 'ckb';
    public string $submissionKey = '';
    public int $maxCharacters = 400;
    public int $creditsCost = 0;
    public array $speakerGroups = [];
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
        $this->serviceSlug = $service;
        $this->toolSlug = $tool;
        $this->serviceDefinition = $catalog->service($service);
        $this->toolDefinition = $definition;
        $this->submissionKey = (string) Str::uuid();

        if (in_array($definition['kind'] ?? null, ['omni_tts', 'omni_clone'], true)) {
            $customer = auth('app')->user();
            $limit = $customer && method_exists($customer, 'entitlementLimitFor')
                ? $customer->entitlementLimitFor((string) $definition['legacy_action'], 'max_chars_per_submit')
                : null;
            $this->maxCharacters = max(1, (int) ($limit ?? 400));
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
            $job = $submissions->submit(auth('app')->user(), $this->serviceSlug, $this->toolSlug, $this->submissionKey, [
                'text' => $this->text,
                'ref_audio' => (string) $speaker['ref_audio'],
                'language' => $this->language,
            ]);
            $this->currentJobId = (string) $job->id;
            $this->queuedSince = null;
            $this->syncCurrentJobState();
            $this->resetPage(pageName: 'v2ApolloRendersPage');
            $this->dispatch('header:refresh');
        } catch (\Throwable $exception) {
            $this->submissionError = $exception->getMessage();
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
            $job = $submissions->submit(auth('app')->user(), $this->serviceSlug, $this->toolSlug, $this->submissionKey, ['text' => $this->text, 'language' => $this->language], $this->referenceAudio, $this->selectedReferenceId);
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
            $this->submissionError = $exception->getMessage();
        }
    }

    public function pollOmni(XttsJobSyncService $sync): void
    {
        if (! $this->currentJobId) {
            return;
        }

        $job = MlJob::query()->with('tool')->whereKey($this->currentJobId)->where('customer_id', auth('app')->id())->first();
        if ($job?->tool && $job->isActive()) {
            $sync->sync($job, $job->tool);
            if (($this->toolDefinition['kind'] ?? null) === 'omni_clone') {
                app(CttsWorkspaceCache::class)->forgetRenders((int) $job->customer_id, (string) $job->tool->code);
            }
        }

        $this->syncCurrentJobState();
        $this->dispatch('header:refresh');
    }

    public function modeLabel(): string
    {
        return $this->processingMode === 'intelligent' ? __('Intelligent') : __('Standard');
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
        $this->currentJob = $job ? ['status' => $status, 'message' => (string) data_get($job->error, 'customer_message')] : [];

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
                <div class="v2-editor-wrap"><textarea dir="rtl" wire:model.live="text" class="v2-audio-editor" rows="12" maxlength="{{ $maxCharacters }}" placeholder="{{ __('Write the text you want to hear…') }}"></textarea><div class="v2-editor-footer"><span dir="ltr">{{ $this->currentChars }} / {{ $maxCharacters }} {{ __('characters') }}</span><span dir="ltr">{{ __('Estimated cost: :cost credits', ['cost' => number_format($creditsCost)]) }}</span></div></div>
                <div class="row g-3 mt-1"><div class="col-sm-6"><label class="form-label" for="v2-language">{{ __('Generation language') }}</label><select id="v2-language" wire:model.change="language" class="form-select v2-control"><option value="ckb">{{ __('Kurdish / Sorani') }}</option><option value="en">{{ __('English') }}</option><option value="ar">{{ __('Arabic') }}</option></select></div></div>
                @if ($submissionError)<div class="alert alert-danger mt-3 mb-0">{{ $submissionError }}</div>@endif
                @php($currentJobPresentation = $this->currentJobPresentation)
                <div class="v2-create-actions {{ $currentJobId ? 'is-'.$currentJobPresentation['semantic'] : '' }}"><button wire:click="submitOmni" wire:loading.attr="disabled" wire:target="submitOmni" class="btn btn-primary waves-effect px-4" @disabled(empty($speakerGroups) || $currentJobPresentation['is_active'])><span wire:loading.remove wire:target="submitOmni">{{ __('Generate audio') }}</span><span wire:loading wire:target="submitOmni">{{ __('Preparing…') }}</span></button>@if($currentJobId)<span class="v2-job-state glass-load {{ $currentJobPresentation['glass_class'] }} {{ $currentJobPresentation['is_active'] ? 'is-active' : '' }}" @if($currentJobPresentation['is_active']) wire:poll.500ms="pollOmni" @endif>{{ $currentJobPresentation['label'] }}@if($currentJob['message'] ?? false): {{ $currentJob['message'] }}@endif</span>@endif</div>
                @if($this->showQueueMessage)<div dir="{{ in_array(app()->getLocale(), ['ar', 'ku'], true) ? 'rtl' : 'ltr' }}" class="v2-queue-message glass-load glass-load--warning"><i class="ri-time-line" aria-hidden="true"></i><span>{{ __('MetKurd AI GPUs are currently busy. Your job is queued and will start automatically as soon as capacity is available.') }}</span></div>@endif
            </main>
            @include('app.v2.components.shared.recent-renders', ['renders' => $this->recentRenders, 'locale' => app()->getLocale(), 'subtitle' => __('Your Apollo history')])
        </div>
    @elseif (($toolDefinition['kind'] ?? null) === 'omni_clone')
        <div class="v2-workspace v2-ctts-workspace">
            @include('app.v2.components.ctts.reference-history', ['references' => $this->referencePage, 'selectedId' => $selectedReferenceId])
            <main class="v2-workspace-panel v2-create-panel v2-ctts-create-panel">
                @include('app.v2.components.ctts.reference-upload', ['selectedReference' => $this->selectedReference, 'referenceAudioName' => $referenceAudioName, 'referenceAudioBytes' => $referenceAudioBytes, 'referenceAudioMime' => $referenceAudioMime])
                <div class="v2-editor-wrap v2-ctts-editor-wrap"><textarea dir="rtl" wire:model.live="text" class="v2-audio-editor" rows="8" maxlength="{{ $maxCharacters }}" placeholder="{{ __('Enter the text that should be spoken using the cloned voice…') }}"></textarea><div class="v2-editor-footer"><span dir="ltr">{{ $this->currentChars }} / {{ $maxCharacters }} {{ __('characters') }}</span><span dir="ltr">{{ __('Estimated cost: :cost credits', ['cost' => number_format($creditsCost)]) }}</span></div></div>
                <div class="row g-3 mt-1"><div class="col-sm-6"><label class="form-label" for="v2-ctts-language">{{ __('Generation language') }}</label><select id="v2-ctts-language" wire:model.change="language" class="form-select v2-control"><option value="ckb">{{ __('Kurdish / Sorani') }}</option><option value="en">{{ __('English') }}</option><option value="ar">{{ __('Arabic') }}</option></select></div></div>
                @if ($submissionError)<div class="alert alert-danger mt-3 mb-0">{{ $submissionError }}</div>@endif
                @php($currentJobPresentation = $this->currentJobPresentation)
                <div class="v2-create-actions v2-ctts-actions {{ $currentJobId ? 'is-'.$currentJobPresentation['semantic'] : '' }}"><button wire:click="submitClone" wire:loading.attr="disabled" wire:target="submitClone,referenceAudio" class="btn btn-danger waves-effect px-4" @disabled((!$selectedReferenceId && !$referenceAudio) || $currentJobPresentation['is_active'])><span wire:loading.remove wire:target="submitClone,referenceAudio">{{ __('Generate cloned speech') }}</span><span wire:loading wire:target="submitClone,referenceAudio">{{ __('Preparing…') }}</span></button>@if($currentJobId)<span class="v2-job-state glass-load {{ $currentJobPresentation['glass_class'] }} {{ $currentJobPresentation['is_active'] ? 'is-active' : '' }}" @if($currentJobPresentation['is_active']) wire:poll.500ms="pollOmni" @endif>{{ $currentJobPresentation['label'] }}@if($currentJob['message'] ?? false): {{ $currentJob['message'] }}@endif</span>@endif</div>
            </main>
            @include('app.v2.components.shared.recent-renders', ['renders' => $this->recentRenders, 'locale' => app()->getLocale(), 'subtitle' => __('Your CTTS history'), 'accent' => 'danger', 'keyPrefix' => 'v2-ctts-render'])
        </div>
    @else
        @if (in_array($toolDefinition['kind'] ?? '', ['qasr', 'caption', 'kocr'], true))<div class="glass-load glass-load--info p-4 mb-4"><h2 class="h5">{{ __('Processing mode') }}</h2><div class="btn-group"><button wire:click="$set('processingMode', 'standard')" class="btn {{ $processingMode === 'standard' ? 'btn-primary' : 'btn-outline-secondary' }}">{{ __('Standard') }}</button><button wire:click="$set('processingMode', 'intelligent')" class="btn {{ $processingMode === 'intelligent' ? 'btn-primary' : 'btn-outline-secondary' }}">{{ __('Intelligent') }}</button></div></div>@endif
        @include('app.v2.components.shared.legacy-workspace', ['tool' => $toolDefinition])
    @endif
</section>

@if (in_array(($toolDefinition['kind'] ?? null), ['omni_tts', 'omni_clone'], true))
    @push('scripts')
        <script data-navigate-once src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js" onload="window.dispatchEvent(new CustomEvent('metkurd:wavesurfer-ready'))"></script>
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
            const destroy = () => { try { state.pond?.destroy(); } catch (_) {} state.pond = null; };
            const boot = () => {
                const field = input(), host = root();
                if (!field || !host || !window.FilePond || state.pond) return;
                if (!state.plugins) { FilePond.registerPlugin(FilePondPluginFileValidateType, FilePondPluginFileValidateSize); state.plugins = true; }
                const lw = Livewire.find(host.getAttribute('wire:id'));
                state.pond = FilePond.create(field, {
                    allowMultiple: false, credits: false,
                    acceptedFileTypes: ['audio/wav','audio/x-wav','audio/mpeg','audio/mp3','audio/mp4','audio/x-m4a','audio/aac','audio/ogg','audio/webm'],
                    maxFileSize: '20MB',
                    labelIdle: '<strong>{{ __('Drop a reference audio file') }}</strong><br><span class="filepond--label-action">{{ __('Browse') }}</span>',
                    server: {
                        process: (name, file, meta, load, error, progress, abort) => {
                            lw.upload('referenceAudio', file, () => load(file.name), () => error('{{ __('Upload failed') }}'), event => progress(event.lengthComputable, event.loaded, event.total));
                            return { abort: () => { lw.removeUpload('referenceAudio', file.name, () => {}); abort(); } };
                        },
                        revert: (id, load) => { lw.call('removeReferenceAudio'); load(); },
                    },
                });
            };
            const reconcile = () => requestAnimationFrame(() => {
                if (state.pond && !input()) destroy();
                boot();
            });
            if (!state.listeners) {
                state.listeners = true;
                document.addEventListener('livewire:navigating', destroy);
                document.addEventListener('livewire:navigated', reconcile);
                document.addEventListener('livewire:initialized', () => {
                    Livewire.on('ctts-reference-audio-cleared', () => state.pond?.removeFiles());
                    Livewire.hook?.('commit', ({ succeed }) => succeed(reconcile));
                    reconcile();
                });
            }
            reconcile();
        })();
        </script>
    @endpush
@endif
