<?php

use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Services\MetKurd\Jobs\OmniSubmissionService;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use App\Services\XTTS\XttsJobSyncService;
use App\Support\MetKurdV2ToolCatalog;
use App\Support\AppToolCatalog;
use App\Support\MetKurdV2JobStatusPresentation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::v2.layouts.app')] class extends Component {
    use WithPagination;

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

        if (($definition['kind'] ?? null) === 'omni_tts') {
            $customer = auth('app')->user();
            $limit = $customer && method_exists($customer, 'entitlementLimitFor')
                ? $customer->entitlementLimitFor((string) $definition['legacy_action'], 'max_chars_per_submit')
                : null;
            $this->maxCharacters = max(1, (int) ($limit ?? 400));
            $this->speakerGroups = $speakers->forCustomer($customer, app()->getLocale());
            $this->expandedSpeakerGroup = (string) array_key_first($this->speakerGroups);
            $this->speakerId = (string) data_get($this->speakerGroups, "{$this->expandedSpeakerGroup}.speakers.0.code", '');
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

    public function pollOmni(XttsJobSyncService $sync): void
    {
        if (! $this->currentJobId) {
            return;
        }

        $job = MlJob::query()->with('tool')->whereKey($this->currentJobId)->where('customer_id', auth('app')->id())->first();
        if ($job?->tool && $job->isActive()) {
            $sync->sync($job, $job->tool);
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
        $toolId = app(AppToolCatalog::class)->toolId($toolCode);
        $streamRoute = $toolCode === 'xomni-v2'
            ? 'app.renders.xomni-v2.stream'
            : 'app.renders.xomni.stream';
        $downloadRoute = $toolCode === 'xomni-v2'
            ? 'app.renders.xomni-v2.download'
            : 'app.renders.xomni.download';
        if (! $customerId || ! $toolId) {
            return MlJob::query()->whereRaw('1 = 0')->paginate(3, pageName: 'v2ApolloRendersPage');
        }

        $locale = app()->getLocale();
        $statusPresentation = app(MetKurdV2JobStatusPresentation::class);
        $paginator = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('tool_id', $toolId)
            ->whereIn('status', ['queued', 'submitting', 'running', 'saving', 'done', 'failed', 'cancelled', 'canceled'])
            ->latest('updated_at')
            ->paginate(3, ['id', 'status', 'input', 'output', 'error', 'created_at', 'updated_at', 'finished_at', 'model_key'], 'v2ApolloRendersPage');

        $outputPaths = $paginator->getCollection()
            ->map(fn (MlJob $job) => trim((string) data_get($job->output, 'path')))
            ->filter()
            ->unique()
            ->values();

        $storageRecordsByPath = CustomerFile::query()
            ->where('customer_id', $customerId)
            ->whereIn('path', $outputPaths)
            ->get(['id', 'path', 'status'])
            ->groupBy('path');

        $paginator->setCollection(
            $paginator->getCollection()->map(function (MlJob $job) use ($locale, $statusPresentation, $storageRecordsByPath, $streamRoute, $downloadRoute): array {
                $text = trim((string) data_get($job->input, 'text'));
                $path = (string) data_get($job->output, 'path');
                $status = (string) $job->status;
                $presentation = $statusPresentation->for($status);
                $records = $storageRecordsByPath->get($path, collect());
                $outputUnavailable = $status === 'done' && ($path === '' || ($records->isNotEmpty() && ! $records->contains('status', 'active')));
                return [
                    'id' => (string) $job->id,
                    'label' => (string) ($this->toolDefinition['name'] ?? __('Apollo')),
                    'status' => $presentation['status'],
                    'status_label' => $presentation['label'],
                    'status_semantic' => $presentation['semantic'],
                    'text' => $text === '' ? __('Audio render') : Str::limit($text, 92),
                    'speaker' => (string) data_get($job->input, 'speaker_id', __('Reference voice')),
                    'when' => optional($job->finished_at ?? $job->updated_at ?? $job->created_at)->diffForHumans(),
                    'output_unavailable' => $outputUnavailable,
                    'stream_url' => $status === 'done' && ! $outputUnavailable ? route($streamRoute, ['locale' => $locale, 'jobId' => $job->id]).'?proxy=1' : null,
                    'download_url' => $status === 'done' && ! $outputUnavailable ? route($downloadRoute, ['locale' => $locale, 'jobId' => $job->id]) : null,
                ];
            })
        );

        return $paginator;
    }

    public function previousRecentRendersPage(): void
    {
        $this->previousPage(pageName: 'v2ApolloRendersPage');
    }

    public function nextRecentRendersPage(): void
    {
        $this->nextPage(pageName: 'v2ApolloRendersPage');
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
    <header class="v2-tool-context"><div class="v2-tool-identity d-flex align-items-center gap-3"><img class="v2-service-icon" src="{{ asset($serviceDefinition['icon_asset']) }}" alt=""><div><span>{{ __($serviceDefinition['name']) }}</span><h1>{{ __($toolDefinition['name']) }}</h1></div></div>@livewire('app::v2.components.account-resources')</header>

    @if (($toolDefinition['kind'] ?? null) === 'omni_tts')
        <div class="v2-workspace">
            @include('app.v2.components.omni-speaker-picker', ['groups' => $speakerGroups, 'selected' => $speakerId, 'expanded' => $expandedSpeakerGroup])
            <main class="v2-workspace-panel v2-create-panel">
                <div class="v2-panel-heading"><span>{{ __('Create audio') }}</span><small>{{ __('Selected voice: :voice', ['voice' => $this->selectedSpeakerName()]) }}</small></div>
                <div class="v2-editor-wrap"><textarea dir="rtl" wire:model.live="text" class="v2-audio-editor" rows="12" maxlength="{{ $maxCharacters }}" placeholder="{{ __('Write the text you want to hear…') }}"></textarea><div class="v2-editor-footer"><span dir="ltr">{{ $this->currentChars }} / {{ $maxCharacters }} {{ __('characters') }}</span><span dir="ltr">{{ __('Estimated cost: :cost credits', ['cost' => number_format($creditsCost)]) }}</span></div></div>
                <div class="row g-3 mt-1"><div class="col-sm-6"><label class="form-label" for="v2-language">{{ __('Generation language') }}</label><select id="v2-language" wire:model.change="language" class="form-select v2-control"><option value="ckb">{{ __('Kurdish / Sorani') }}</option><option value="en">{{ __('English') }}</option><option value="ar">{{ __('Arabic') }}</option></select></div></div>
                @if ($submissionError)<div class="alert alert-danger mt-3 mb-0">{{ $submissionError }}</div>@endif
                @php($currentJobPresentation = $this->currentJobPresentation)
                <div class="v2-create-actions {{ $currentJobId ? 'is-'.$currentJobPresentation['semantic'] : '' }}"><button wire:click="submitOmni" wire:loading.attr="disabled" wire:target="submitOmni" class="btn btn-primary waves-effect px-4" @disabled(empty($speakerGroups) || $currentJobPresentation['is_active'])><span wire:loading.remove wire:target="submitOmni">{{ __('Generate audio') }}</span><span wire:loading wire:target="submitOmni">{{ __('Preparing…') }}</span></button>@if($currentJobId)<span class="v2-job-state glass-load {{ $currentJobPresentation['glass_class'] }} {{ $currentJobPresentation['is_active'] ? 'is-active' : '' }}" @if($currentJobPresentation['is_active']) wire:poll.500ms="pollOmni" @endif>{{ $currentJobPresentation['label'] }}@if($currentJob['message'] ?? false): {{ $currentJob['message'] }}@endif</span>@endif</div>
                @if($this->showQueueMessage)<div dir="{{ in_array(app()->getLocale(), ['ar', 'ku'], true) ? 'rtl' : 'ltr' }}" class="v2-queue-message glass-load glass-load--warning"><i class="ri-time-line" aria-hidden="true"></i><span>{{ __('MetKurd AI GPUs are currently busy. Your job is queued and will start automatically as soon as capacity is available.') }}</span></div>@endif
            </main>
            @include('app.v2.components.recent-renders', ['renders' => $this->recentRenders, 'locale' => app()->getLocale()])
        </div>
    @else
        @if (in_array($toolDefinition['kind'] ?? '', ['qasr', 'caption', 'kocr'], true))<div class="glass-load glass-load--info p-4 mb-4"><h2 class="h5">{{ __('Processing mode') }}</h2><div class="btn-group"><button wire:click="$set('processingMode', 'standard')" class="btn {{ $processingMode === 'standard' ? 'btn-primary' : 'btn-outline-secondary' }}">{{ __('Standard') }}</button><button wire:click="$set('processingMode', 'intelligent')" class="btn {{ $processingMode === 'intelligent' ? 'btn-primary' : 'btn-outline-secondary' }}">{{ __('Intelligent') }}</button></div></div>@endif
        @include('app.v2.components.legacy-workspace', ['tool' => $toolDefinition])
    @endif
</section>

@if (($toolDefinition['kind'] ?? null) === 'omni_tts')
    @push('scripts')
        <script data-navigate-once src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js" onload="window.dispatchEvent(new CustomEvent('metkurd:wavesurfer-ready'))"></script>
    @endpush
@endif
