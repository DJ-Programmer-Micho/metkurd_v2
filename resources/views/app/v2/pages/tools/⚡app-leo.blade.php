<?php

use App\Models\MlJob;
use App\Services\ASR\QasrJobSyncService;
use App\Services\Media\AudioProbeService;
use App\Services\MetKurd\Jobs\LeoSubmissionService;
use App\Services\MetKurd\V2\LeoWorkspaceCache;
use App\Support\MetKurdV2JobStatusPresentation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('app::v2.layouts.app')] class extends Component {
    use WithFileUploads, WithPagination;
    use \App\Support\OpensProcessQueueJob;

    protected function processQueueAction(): string { return 'leo.transcribe'; }
    protected function selectProcessQueueJob(MlJob $job): void { $this->currentJobId = (string) $job->id; }

    public $audioFile = null;
    public string $language = 'ckb';
    public string $modelVariant = 'fine_tuned';
    public bool $intelligent = false;
    public ?string $audioName = null;
    public ?string $audioMime = null;
    #[\Livewire\Attributes\Locked] public ?float $audioDurationSec = null;
    #[\Livewire\Attributes\Locked] public int $audioBillableMinutes = 0;
    #[\Livewire\Attributes\Locked] public ?string $audioHash = null;
    #[\Livewire\Attributes\Locked] public int $creditsCost = 0;
    public ?string $currentJobId = null;
    public ?string $selectedTranscriptJobId = null;
    #[\Livewire\Attributes\Locked] public string $submissionKey = '';
    public string $submissionError = '';

    public function mount(): void { $this->submissionKey = (string) \Illuminate\Support\Str::uuid(); $this->hydrateCurrentJob(); $this->openProcessQueueJob(); }

    public function updatedAudioFile(AudioProbeService $probe): void
    {
        $this->submissionKey = (string) \Illuminate\Support\Str::uuid();
        $this->validateOnly('audioFile');
        if (! $this->audioFile) return;
        try {
            $info = app(\App\Services\MetKurd\V2\InputBoundary::class)->audio($this->audioFile);
            $this->audioName = $this->audioFile->getClientOriginalName();
            $this->audioMime = $this->audioFile->getMimeType() ?: 'audio/*';
            $this->audioDurationSec = (float) $info['duration_sec'];
            $this->audioBillableMinutes = (int) $info['billable_min'];
            $path = $this->audioFile->getRealPath();
            $this->audioHash = $info['input_hash'];
            $this->refreshCost();
        } catch (\Throwable) {
            $this->removeAudio();
            $this->addError('audioFile', __('Failed to inspect the uploaded audio.'));
        }
    }

    public function removeAudio(): void
    {
        $this->audioFile = null; $this->audioName = null; $this->audioMime = null; $this->audioDurationSec = null;
        $this->audioBillableMinutes = 0; $this->audioHash = null; $this->creditsCost = 0;
        $this->dispatch('leo-audio-cleared');
    }

    public function submitLeo(LeoSubmissionService $submissions): void
    {
        $this->submissionError = '';
        $this->validate();
        if ($this->audioBillableMinutes < 1) { $this->submissionError = __('Could not determine audio duration.'); return; }
        try {
            $job = $submissions->submit(auth('app')->user(), $this->audioFile, ['submission_key' => $this->submissionKey ?: (string) \Illuminate\Support\Str::uuid(),
                'model_variant' => $this->modelVariant, 'language' => $this->language, 'intelligent' => $this->intelligent,
                'duration_sec' => (float) $this->audioDurationSec, 'billable_minutes' => $this->audioBillableMinutes, 'input_hash' => (string) $this->audioHash,
                'audio_name' => (string) $this->audioName, 'audio_mime' => (string) $this->audioMime,
            ]);
            $this->currentJobId = (string) $job->id;
            $this->dispatch('metkurd:job-submitted');
            if ((string) $job->status === 'failed') $this->submissionError = (string) data_get($job->error, 'message', __('Transcription could not be started.'));
            $this->resetPage('leoRendersPage');
            $this->dispatch('header:refresh');
        } catch (\Throwable $e) { $this->submissionError = \App\Support\CustomerFacingError::message($e->getMessage()); }
    }

    public function pollLeo(QasrJobSyncService $sync): void
    {
        $job = $this->currentJob;
        $previousStatus = $job?->status;
        if ($job && $job->tool) $sync->sync($job, $job->tool);
        $job?->refresh();
        $this->hydrateCurrentJob();
        unset($this->currentJob);
        if ($job && $previousStatus !== $job->status) $this->dispatch('header:refresh');
    }

    #[Computed] public function currentJob(): ?MlJob
    {
        return $this->currentJobId ? MlJob::query()->with('tool')->whereKey($this->currentJobId)->where('customer_id', auth('app')->id())->first() : null;
    }
    #[Computed] public function currentPresentation(): array { return app(MetKurdV2JobStatusPresentation::class)->for((string) ($this->currentJob?->status ?? 'idle')); }
    #[Computed] public function currentTranscript(): string { return (string) data_get($this->currentJob?->output, 'text', ''); }
    #[Computed] public function selectedTranscript(): ?MlJob
    {
        return $this->selectedTranscriptJobId
            ? MlJob::query()->whereKey($this->selectedTranscriptJobId)->where('customer_id', auth('app')->id())->where('job_kind', 'leo')->where('status', 'done')->first()
            : null;
    }

    #[Computed] public function recentTranscriptions()
    {
        $customerId = (int) auth('app')->id(); $page = $this->getPage('leoRendersPage');
        $active = MlJob::query()->where('customer_id', $customerId)->where('job_kind', 'leo')->whereIn('status', ['queued', 'running', 'saving'])->exists();
        $resolver = fn () => MlJob::query()->where('customer_id', $customerId)->where('job_kind', 'leo')->whereNotIn('status', ['deleted', 'deleting'])->latest('updated_at')->paginate(5, pageName: 'leoRendersPage');
        $renders = clone app(LeoWorkspaceCache::class)->recentTranscriptions($customerId, $page, $active, $resolver);
        $locale = app()->getLocale();
        $renders->setCollection($renders->getCollection()->map(fn (MlJob $job) => [
            'id' => (string) $job->id, 'status' => (string) $job->status, 'name' => (string) data_get($job->input, 'audio_name', __('Uploaded audio')),
            'text' => Str::limit((string) data_get($job->output, 'text', ''), 180), 'duration' => (float) data_get($job->input, 'audio_duration_sec', 0),
            'when' => ($when = $job->finished_at ?: $job->updated_at) ? Carbon::parse($when)->diffForHumans() : '',
            'download_url' => (string) $job->status === 'done' && data_get($job->output, 'path') ? route('app.v2.leo.txt', ['locale' => $locale, 'jobId' => $job->id]) : null,
            'audio_url' => (string) $job->status === 'done' && data_get($job->input, 'audio_path') ? route('app.v2.leo.audio', ['locale' => $locale, 'jobId' => $job->id]).'?proxy=1' : null,
        ]));
        return $renders;
    }

    public function previousRecentTranscriptionsPage(): void { $this->previousPage('leoRendersPage'); }
    public function nextRecentTranscriptionsPage(): void { $this->nextPage('leoRendersPage'); }
    public function copyTranscript(): void { $this->dispatch('leo-copy-transcript', text: $this->currentTranscript); }
    public function showTranscript(string $jobId): void { $this->selectedTranscriptJobId = MlJob::query()->whereKey($jobId)->where('customer_id', auth('app')->id())->where('job_kind', 'leo')->where('status', 'done')->exists() ? $jobId : null; }
    public function closeTranscript(): void { $this->selectedTranscriptJobId = null; }
    public function copySelectedTranscript(): void { $this->dispatch('leo-copy-transcript', text: (string) data_get($this->selectedTranscript?->output, 'text', '')); }

    protected function rules(): array { return ['audioFile' => 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac|max:'.\App\Services\MetKurd\V2\InputBoundary::AUDIO_MAX_KIB, 'modelVariant' => 'required|in:fine_tuned', 'language' => 'required|in:ckb,ar,en', 'intelligent' => 'boolean']; }
    private function refreshCost(): void { $customer = auth('app')->user(); $this->creditsCost = $customer && $this->audioBillableMinutes ? max(0, (int) $customer->priceCreditsFor('leo.transcribe', ['minutes' => $this->audioBillableMinutes, 'metric_code' => 'minute', 'model_variant' => $this->modelVariant, 'language' => $this->language])) : 0; }
    private function hydrateCurrentJob(): void { $job = MlJob::query()->where('customer_id', auth('app')->id())->where('job_kind', 'leo')->whereIn('status', ['queued', 'running', 'saving', 'done', 'failed'])->orderByRaw("CASE WHEN status IN ('queued','running','saving') THEN 0 ELSE 1 END")->latest('updated_at')->first(); $this->currentJobId = $job ? (string) $job->id : null; }
}; ?>

<section class="v2-tool-page v2-leo-page">
    <nav class="v2-breadcrumb"><a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}">{{ __('MetKurd AI') }}</a><span>/</span><a wire:navigate href="{{ route('app.v2.service', ['locale' => app()->getLocale(), 'service' => 'speech-to-text']) }}">{{ __('Speech-to-Text') }}</a><span>/</span><span>{{ __('Leo') }}</span></nav>
    <header class="v2-tool-context"><div class="v2-tool-identity d-flex align-items-center gap-3"><img class="v2-service-icon" src="{{ asset('app/services_icons/ASR.png') }}" alt=""><div><span>{{ __('Speech-to-Text') }}</span><h1>{{ __('Leo') }}</h1></div></div>@livewire('app::v2.components.shared.account-resources')</header>
    <div class="v2-workspace v2-leo-workspace">
        <main class="v2-workspace-panel v2-create-panel v2-leo-upload-panel">
            <div class="v2-panel-heading"><span>{{ __('Audio Upload') }}</span><small>{{ __('Upload audio to transcribe') }}</small></div>
            <div class="v2-leo-upload-copy"><i class="ri-mic-2-line"></i><span>{{ __('Supported audio up to 100 MB. Existing ASR validation rules apply.') }}</span></div>
            <div wire:ignore><input id="v2-leo-audio-pond" @include('app.v2.components.upload-size', ['maxKib' => \App\Services\MetKurd\V2\InputBoundary::AUDIO_MAX_KIB]) data-upload-error="{{ __('Upload failed') }}" type="file" accept=".wav,.mp3,.m4a,.aac,.ogg,.webm,.flac,audio/*"></div>
            @if($audioName)<div class="v2-leo-file"><i class="ri-file-music-line"></i><span><strong>{{ $audioName }}</strong><small>{{ $audioMime }} · {{ number_format((float) $audioDurationSec, 1) }}s</small></span><button type="button" wire:click="removeAudio" class="btn btn-sm btn-outline-success">{{ __('Remove') }}</button></div><div class="v2-leo-upload-waveform" wire:ignore data-metkurd-waveform data-leo-upload-waveform data-job="leo-upload" data-accent="success"><div class="v2-render-player-controls"><button type="button" class="v2-waveform-toggle" data-metkurd-waveform-toggle aria-label="{{ __('Play or pause uploaded audio') }}"><i class="ri-play-fill" data-metkurd-waveform-icon></i></button><span class="v2-waveform-time" data-metkurd-waveform-time>00:00 / --:--</span></div><div class="v2-waveform-canvas" data-metkurd-waveform-canvas></div><small class="v2-waveform-load-state" data-metkurd-waveform-state>{{ __('Loading audio preview…') }}</small></div>@endif
            @error('audioFile')<small class="text-danger mt-2">{{ $message }}</small>@enderror
            <label class="v2-leo-intelligent mt-3"><span><strong>{{ __('Intelligent') }} <em>{{ __('Beta') }}</em></strong><small>{{ __('Improves transcription using intelligent post-processing.') }}</small></span><input type="checkbox" wire:model="intelligent" role="switch"></label>
            <div class="v2-editor-footer mt-3"><span>{{ __('Estimated cost') }}</span><span>{{ number_format($creditsCost) }} {{ __('credits') }}</span></div>
            @if($submissionError)<div class="alert alert-danger mt-3 mb-0">{{ \App\Support\CustomerFacingError::message($submissionError) }}</div>@endif
            @php($presentation = $this->currentPresentation)
            <div class="v2-create-actions"><button wire:click="submitLeo" wire:loading.attr="disabled" wire:target="submitLeo,audioFile" @disabled(!$audioFile || $presentation['is_active']) class="btn btn-success px-4"><span wire:loading.remove wire:target="submitLeo,audioFile">{{ __('Transcribe') }}</span><span wire:loading wire:target="submitLeo,audioFile">{{ __('Preparing…') }}</span></button>@if($currentJobId)<span class="v2-job-state glass-load {{ $presentation['glass_class'] }}" @if($presentation['is_active']) wire:poll.5s="pollLeo" @endif>{{ $presentation['label'] }}</span>@endif</div>
        </main>
        <aside class="v2-workspace-panel v2-leo-results-panel">
            <div class="v2-panel-heading"><span>{{ __('Transcribed Text') }}</span><small>{{ __('Latest result') }}</small></div>
            @if($this->currentJob && $presentation['is_active'])<div class="v2-leo-processing"><span class="spinner-border spinner-border-sm"></span>{{ __('Processing your transcription…') }}</div>
            @elseif($this->currentJob && $this->currentJob->status === 'failed')<div class="v2-leo-failed">{{ \App\Support\CustomerFacingError::message(data_get($this->currentJob->error, 'message', __('Transcription could not be completed.'))) }}</div>
            @elseif($this->currentTranscript)<div class="v2-leo-transcript" dir="auto">{{ $this->currentTranscript }}</div><div class="d-flex gap-2 mt-2"><button wire:click="copyTranscript" class="btn btn-sm btn-outline-success">{{ __('Copy') }}</button>@if(data_get($this->currentJob?->output, 'path'))<a href="{{ route('app.v2.leo.txt', ['locale' => app()->getLocale(), 'jobId' => $currentJobId]) }}" class="btn btn-sm btn-outline-light">{{ __('Download') }}</a>@endif</div>
            @else<div class="v2-empty-state v2-leo-empty">{{ __('Your transcription will appear here.') }}</div>@endif
            <div class="v2-leo-history"><div class="v2-panel-heading"><span>{{ __('Recent Transcriptions') }}</span><small>{{ __('Leo only') }}</small></div>@forelse($this->recentTranscriptions as $render)<article class="v2-render-item is-{{ $render['status'] }}" wire:key="leo-render-{{ $render['id'] }}"><div class="d-flex justify-content-between gap-2"><strong dir="auto">{{ $render['name'] }}</strong><span class="v2-render-status is-{{ $render['status'] === 'done' ? 'success' : ($render['status'] === 'failed' ? 'danger' : 'info') }}">{{ app(\App\Support\MetKurdV2JobStatusPresentation::class)->for($render['status'])['label'] }}</span></div><p dir="auto">{{ $render['text'] ?: __('Transcription is still processing.') }}</p><small class="v2-muted">{{ $render['when'] }} @if($render['duration']) · {{ number_format($render['duration'], 1) }}s @endif</small>@if($render['audio_url'])<div class="v2-leo-recent-waveform" wire:ignore data-metkurd-waveform data-job="leo-audio-{{ $render['id'] }}" data-accent="success" data-url="{{ $render['audio_url'] }}"><div class="v2-render-player-controls"><button type="button" class="v2-waveform-toggle" data-metkurd-waveform-toggle aria-label="{{ __('Play or pause original audio') }}"><i class="ri-play-fill" data-metkurd-waveform-icon></i></button><span class="v2-waveform-time" data-metkurd-waveform-time>00:00 / --:--</span></div><div class="v2-waveform-canvas" data-metkurd-waveform-canvas></div></div>@endif<div class="d-flex flex-wrap gap-2 mt-2">@if($render['download_url'])<a class="btn btn-sm btn-outline-success" href="{{ $render['download_url'] }}">{{ __('Download') }}</a>@endif@if($render['status'] === 'done')<button type="button" wire:click="showTranscript('{{ $render['id'] }}')" class="btn btn-sm btn-outline-success">{{ __('View Transcript') }}</button>@endif</div></article>@empty<div class="v2-empty-state">{{ __('Your recent Leo transcriptions will appear here.') }}</div>@endforelse
            @if($this->recentTranscriptions->hasPages())<nav class="v2-render-pagination"><button wire:click="previousRecentTranscriptionsPage" @disabled($this->recentTranscriptions->onFirstPage())>{{ __('Previous') }}</button><span>{{ $this->recentTranscriptions->currentPage() }} / {{ $this->recentTranscriptions->lastPage() }}</span><button wire:click="nextRecentTranscriptionsPage" @disabled(! $this->recentTranscriptions->hasMorePages())>{{ __('Next') }}</button></nav>@endif</div>
        </aside>
    </div>
@if($this->selectedTranscript)
    <div class="modal fade show d-block v2-leo-transcript-modal" tabindex="-1" role="dialog" aria-modal="true" wire:keydown.escape="closeTranscript">
        <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header"><div><small>{{ __('Leo transcription') }}</small><h2 class="h5 mb-0">{{ data_get($this->selectedTranscript->input, 'audio_name', __('Uploaded audio')) }}</h2></div><button type="button" wire:click="closeTranscript" class="btn-close btn-close-white" aria-label="{{ __('Close') }}"></button></div><div class="modal-body"><div class="v2-leo-modal-transcript" dir="auto">{{ data_get($this->selectedTranscript->output, 'text', '') }}</div></div><div class="modal-footer"><button type="button" wire:click="copySelectedTranscript" class="btn btn-success btn-sm">{{ __('Copy') }}</button><a class="btn btn-outline-light btn-sm" href="{{ route('app.v2.leo.txt', ['locale' => app()->getLocale(), 'jobId' => $this->selectedTranscript->id]) }}">{{ __('Download') }}</a><button type="button" wire:click="closeTranscript" class="btn btn-outline-success btn-sm">{{ __('Close') }}</button></div></div></div>
    </div><div class="modal-backdrop fade show"></div>
@endif
</section>

@push('styles')<link href="{{ asset('app/libs/filepond/filepond.min.css') }}" rel="stylesheet"><style>.metkurd-v2 .v2-leo-workspace{grid-template-columns:minmax(290px,.8fr) minmax(440px,1.35fr);border-color:rgba(var(--v2-accent-rgb),.4);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.14),rgba(3,18,13,.3));}.metkurd-v2 .v2-leo-workspace .v2-panel-heading>span{color:var(--v2-accent-text)}.metkurd-v2 .v2-leo-upload-copy,.metkurd-v2 .v2-leo-file,.metkurd-v2 .v2-leo-processing,.metkurd-v2 .v2-leo-failed{display:flex;gap:.65rem;align-items:center;margin:1rem 0;padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.22);border-radius:.8rem;background:rgba(var(--v2-accent-rgb),.07);font-size:.78rem}.metkurd-v2 .v2-leo-upload-copy i,.metkurd-v2 .v2-leo-file>i{font-size:1.2rem;color:var(--v2-accent-text)}.metkurd-v2 .v2-leo-file span{display:grid;min-width:0;flex:1}.metkurd-v2 .v2-leo-file small{color:rgba(226,232,240,.55)}.metkurd-v2 .v2-leo-intelligent{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.18);border-radius:.75rem;cursor:pointer}.metkurd-v2 .v2-leo-intelligent span{display:grid;gap:.14rem}.metkurd-v2 .v2-leo-intelligent small{color:rgba(226,232,240,.55);font-size:.7rem}.metkurd-v2 .v2-leo-intelligent em{padding:.12rem .34rem;border-radius:999px;background:rgba(245,158,11,.16);color:#fde68a;font-size:.6rem;font-style:normal;text-transform:uppercase}.metkurd-v2 .v2-leo-intelligent input{width:2.35rem;height:1.25rem;accent-color:rgb(var(--v2-accent-rgb))}.metkurd-v2 .v2-leo-results-panel{min-height:575px}.metkurd-v2 .v2-leo-transcript{min-height:180px;margin-top:1rem;padding:1rem;border:1px solid rgba(var(--v2-accent-rgb),.82);border-radius:.9rem;background:rgba(2,6,23,.34);white-space:pre-wrap;line-height:1.9;user-select:text}.metkurd-v2 .v2-leo-empty{padding:2rem 0}.metkurd-v2 .v2-leo-history{margin-top:1.25rem;padding-top:1rem;border-top:1px solid rgba(148,163,184,.12);display:grid;gap:.65rem}.metkurd-v2 .v2-leo-history .v2-panel-heading{padding-bottom:.65rem}.metkurd-v2 .v2-leo-history .v2-render-item{margin:0}.metkurd-v2 .v2-leo-processing{color:#bbf7d0}.metkurd-v2 .v2-leo-failed{color:#fecaca;border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08)}@media(max-width:767.98px){.metkurd-v2 .v2-leo-workspace{grid-template-columns:1fr}.metkurd-v2 .v2-leo-results-panel{min-height:0}}</style>@endpush
@push('styles')<style>.metkurd-v2 .v2-leo-upload-waveform,.metkurd-v2 .v2-leo-recent-waveform{margin-top:.75rem}.metkurd-v2 .v2-leo-upload-waveform{padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.28);border-radius:.8rem;background:rgba(var(--v2-accent-rgb),.06)}.metkurd-v2 .v2-leo-recent-waveform .v2-waveform-canvas{min-height:32px}.metkurd-v2 .v2-leo-recent-waveform .v2-render-player-controls{margin:.55rem 0 .3rem}.metkurd-v2 .v2-leo-upload-waveform .v2-waveform-toggle,.metkurd-v2 .v2-leo-recent-waveform .v2-waveform-toggle{border-color:rgba(var(--v2-accent-rgb),.62);background:rgba(var(--v2-accent-rgb),.16);color:var(--v2-accent-text)}.metkurd-v2 .v2-leo-transcript-modal .modal-content{border:1px solid rgba(var(--v2-accent-rgb),.38);background:linear-gradient(160deg,#17231a,#0d1210);color:#e5ebe5;box-shadow:0 24px 70px rgba(0,0,0,.5),0 0 30px rgba(var(--v2-accent-rgb),.12)}.metkurd-v2 .v2-leo-transcript-modal .modal-header,.metkurd-v2 .v2-leo-transcript-modal .modal-footer{border-color:rgba(var(--v2-accent-rgb),.16)}.metkurd-v2 .v2-leo-transcript-modal small{color:var(--v2-accent-text);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em}.metkurd-v2 .v2-leo-modal-transcript{white-space:pre-wrap;line-height:1.95;user-select:text}.metkurd-v2 .v2-leo-history .btn-outline-success,.metkurd-v2 .v2-leo-file .btn-outline-success{border-color:rgba(var(--v2-accent-rgb),.68);color:var(--v2-accent-text)}.metkurd-v2 .v2-leo-history .btn-outline-success:hover,.metkurd-v2 .v2-leo-file .btn-outline-success:hover{background:rgba(var(--v2-accent-rgb),.18);color:#fff}</style>@endpush
