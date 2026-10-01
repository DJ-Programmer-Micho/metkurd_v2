<?php

use App\Models\MlJob;
use App\Services\ASR\QasrJobSyncService;
use App\Services\Media\AudioProbeService;
use App\Services\MetKurd\Jobs\CaptionSubmissionService;
use App\Services\MetKurd\V2\CaptionWorkspaceCache;
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

    protected function processQueueAction(): string { return 'caption.standard'; }
    protected function selectProcessQueueJob(MlJob $job): void { $this->currentJobId = (string) $job->id; }

    public $audioFile = null;
    public string $modelVariant = 'fine_tuned';
    public string $language = 'ckb';
    public bool $intelligent = false;
    public ?string $audioName = null;
    public ?string $audioMime = null;
    #[\Livewire\Attributes\Locked] public ?float $audioDurationSec = null;
    #[\Livewire\Attributes\Locked] public int $audioBillableMinutes = 0;
    #[\Livewire\Attributes\Locked] public ?string $audioHash = null;
    #[\Livewire\Attributes\Locked] public int $creditsCost = 0;
    public ?string $currentJobId = null;
    public ?string $selectedCaptionJobId = null;
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
        $this->dispatch('caption-audio-cleared');
    }

    public function submitCaption(CaptionSubmissionService $submissions): void
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
            if ((string) $job->status === 'failed') $this->submissionError = (string) data_get($job->error, 'message', __('Caption could not be completed.'));
            $this->resetPage('captionRendersPage');
            $this->dispatch('header:refresh');
        } catch (\Throwable $e) { $this->submissionError = \App\Support\CustomerFacingError::message($e->getMessage()); }
    }

    public function pollCaption(QasrJobSyncService $sync): void
    {
        $job = $this->currentJob;
        $previousStatus = $job?->status;
        if ($job && $job->tool) $sync->sync($job, $job->tool);
        $job?->refresh();
        $this->hydrateCurrentJob();
        unset($this->currentJob);
        if ($job && $previousStatus !== $job->status) $this->dispatch('header:refresh');
    }

    #[Computed] public function currentJob(): ?MlJob { return $this->currentJobId ? MlJob::query()->with('tool')->whereKey($this->currentJobId)->where('customer_id', auth('app')->id())->where('job_kind', 'caption')->first() : null; }
    #[Computed] public function currentPresentation(): array { return app(MetKurdV2JobStatusPresentation::class)->for((string) ($this->currentJob?->status ?? 'idle')); }
    #[Computed] public function currentCaption(): string { return (string) (data_get($this->currentJob?->output, 'srt') ?: data_get($this->currentJob?->output, 'text', '')); }
    #[Computed] public function currentCaptionBlocks(): array
    {
        $caption = trim($this->currentCaption);
        if ($caption === '') return [];
        $blocks = preg_split('/\R\s*\R/u', $caption) ?: [];
        $result = [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(preg_split('/\R/u', trim($block)) ?: [], fn (string $line) => trim($line) !== ''));
            if ($lines === []) continue;
            if (preg_match('/^\d+$/', trim($lines[0]))) array_shift($lines);
            $timing = isset($lines[0]) && str_contains($lines[0], '-->') ? array_shift($lines) : null;
            $text = trim(implode("\n", $lines));
            if ($text !== '') $result[] = ['timing' => $timing, 'text' => $text];
        }
        return $result ?: [['timing' => null, 'text' => $caption]];
    }
    #[Computed] public function selectedCaption(): ?MlJob { return $this->selectedCaptionJobId ? MlJob::query()->whereKey($this->selectedCaptionJobId)->where('customer_id', auth('app')->id())->where('job_kind', 'caption')->where('status', 'done')->first() : null; }

    #[Computed] public function recentCaptions()
    {
        $customerId = (int) auth('app')->id(); $page = $this->getPage('captionRendersPage');
        $active = MlJob::query()->where('customer_id', $customerId)->where('job_kind', 'caption')->whereIn('status', ['queued', 'running', 'saving'])->exists();
        $resolver = fn () => MlJob::query()->where('customer_id', $customerId)->where('job_kind', 'caption')->whereNotIn('status', ['deleted', 'deleting'])->latest('updated_at')->paginate(5, pageName: 'captionRendersPage');
        $renders = clone app(CaptionWorkspaceCache::class)->recentCaptions($customerId, $page, $active, $resolver);
        $locale = app()->getLocale();
        $renders->setCollection($renders->getCollection()->map(fn (MlJob $job) => [
            'id' => (string) $job->id, 'status' => (string) $job->status, 'name' => (string) data_get($job->input, 'audio_name', __('Uploaded audio')),
            'caption' => Str::limit((string) (data_get($job->output, 'srt') ?: data_get($job->output, 'text', '')), 220),
            'duration' => (float) data_get($job->input, 'audio_duration_sec', 0), 'when' => ($when = $job->finished_at ?: $job->updated_at) ? Carbon::parse($when)->diffForHumans() : '',
            'txt_url' => (string) $job->status === 'done' && data_get($job->output, 'path') ? route('app.v2.caption.txt', ['locale' => $locale, 'jobId' => $job->id]) : null,
            'srt_url' => (string) $job->status === 'done' && (string) (data_get($job->output, 'srt_path') ?: data_get($job->output, 'srt', '')) !== '' ? route('app.v2.caption.srt', ['locale' => $locale, 'jobId' => $job->id]) : null,
            'audio_url' => (string) $job->status === 'done' && data_get($job->input, 'audio_path') ? route('app.v2.caption.audio', ['locale' => $locale, 'jobId' => $job->id]).'?proxy=1' : null,
        ]));
        return $renders;
    }

    public function previousRecentCaptionsPage(): void { $this->previousPage('captionRendersPage'); }
    public function nextRecentCaptionsPage(): void { $this->nextPage('captionRendersPage'); }
    public function deleteCaption(QasrJobSyncService $sync, string $jobId): void
    {
        $job = MlJob::query()->whereKey($jobId)->where('customer_id', auth('app')->id())->where('job_kind', 'caption')->whereIn('status', ['done', 'delete_failed'])->firstOrFail();
        $sync->deleteFinishedTranscription($job);
        app(CaptionWorkspaceCache::class)->forgetCaptions((int) auth('app')->id());
        if ($this->currentJobId === $jobId) $this->hydrateCurrentJob();
    }
    public function copyCaption(): void { $this->dispatch('caption-copy-result', text: $this->currentCaption); }
    public function showCaption(string $jobId): void { $this->selectedCaptionJobId = MlJob::query()->whereKey($jobId)->where('customer_id', auth('app')->id())->where('job_kind', 'caption')->where('status', 'done')->exists() ? $jobId : null; }
    public function closeCaption(): void { $this->selectedCaptionJobId = null; }
    public function copySelectedCaption(): void { $this->dispatch('caption-copy-result', text: (string) (data_get($this->selectedCaption?->output, 'srt') ?: data_get($this->selectedCaption?->output, 'text', ''))); }

    protected function rules(): array { return ['audioFile' => 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac|max:'.\App\Services\MetKurd\V2\InputBoundary::AUDIO_MAX_KIB, 'modelVariant' => 'required|in:fine_tuned', 'language' => 'required|in:ckb,ar,en', 'intelligent' => 'boolean']; }
    private function refreshCost(): void { $customer = auth('app')->user(); $this->creditsCost = $customer && $this->audioBillableMinutes ? max(0, (int) $customer->priceCreditsFor('caption.standard', ['minutes' => $this->audioBillableMinutes, 'metric_code' => 'minute', 'model_variant' => $this->modelVariant, 'language' => $this->language, 'output_format' => 'srt'])) : 0; }
    private function hydrateCurrentJob(): void { $job = MlJob::query()->where('customer_id', auth('app')->id())->where('job_kind', 'caption')->whereIn('status', ['queued', 'running', 'saving', 'done', 'failed'])->orderByRaw("CASE WHEN status IN ('queued','running','saving') THEN 0 ELSE 1 END")->latest('updated_at')->first(); $this->currentJobId = $job ? (string) $job->id : null; }
}; ?>

<section class="v2-tool-page v2-caption-page">
    <nav class="v2-breadcrumb"><a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}">{{ __('MetKurd AI') }}</a><span>/</span><a wire:navigate href="{{ route('app.v2.service', ['locale' => app()->getLocale(), 'service' => 'speech-to-text']) }}">{{ __('Speech-to-Text') }}</a><span>/</span><span>{{ __('Caption') }}</span></nav>
    <header class="v2-tool-context"><div class="v2-tool-identity d-flex align-items-center gap-3"><img class="v2-service-icon" src="{{ asset('app/services_icons/ASR.png') }}" alt=""><div><span>{{ __('Speech-to-Text') }}</span><h1>{{ __('Caption') }}</h1></div></div>@livewire('app::v2.components.shared.account-resources')</header>
    <div class="v2-workspace v2-caption-workspace">
        <main class="v2-workspace-panel v2-create-panel v2-caption-upload-panel">
            <div class="v2-panel-heading"><span>{{ __('Audio Upload') }}</span><small>{{ __('Upload audio to create captions') }}</small></div>
            <div class="v2-caption-upload-copy"><i class="ri-subtitle-line"></i><span>{{ __('Supported audio up to 100 MB. Captions are generated as SRT subtitles.') }}</span></div>
            <div wire:ignore><input id="v2-caption-audio-pond" @include('app.v2.components.upload-size', ['maxKib' => \App\Services\MetKurd\V2\InputBoundary::AUDIO_MAX_KIB]) data-upload-error="{{ __('Upload failed') }}" type="file" accept=".wav,.mp3,.m4a,.aac,.ogg,.webm,.flac,audio/*"></div>
            @if($audioName)<div class="v2-caption-file"><i class="ri-file-music-line"></i><span><strong>{{ $audioName }}</strong><small>{{ $audioMime }} · {{ number_format((float) $audioDurationSec, 1) }}s</small></span><button type="button" wire:click="removeAudio" class="btn btn-sm btn-outline-success">{{ __('Remove') }}</button></div><div class="v2-caption-upload-waveform" wire:ignore data-metkurd-waveform data-caption-upload-waveform data-job="caption-upload" data-accent="success"><div class="v2-render-player-controls"><button type="button" class="v2-waveform-toggle" data-metkurd-waveform-toggle aria-label="{{ __('Play or pause uploaded audio') }}"><i class="ri-play-fill" data-metkurd-waveform-icon></i></button><span class="v2-waveform-time" data-metkurd-waveform-time>00:00 / --:--</span></div><div class="v2-waveform-canvas" data-metkurd-waveform-canvas></div><small class="v2-waveform-load-state" data-metkurd-waveform-state>{{ __('Loading audio preview…') }}</small></div>@endif
            @error('audioFile')<small class="text-danger mt-2">{{ $message }}</small>@enderror
            <label class="v2-asr-intelligent mt-3"><span><strong>{{ __('Intelligent') }} <em>{{ __('Beta') }}</em></strong><small>{{ __('Improves captions using intelligent post-processing.') }}</small></span><span class="v2-asr-switch"><input type="checkbox" wire:model="intelligent" role="switch" aria-label="{{ __('Enable Intelligent captions') }}"><i aria-hidden="true"></i></span></label>
            <div class="v2-editor-footer mt-3"><span>{{ __('Estimated cost') }}</span><span>{{ number_format($creditsCost) }} {{ __('credits') }}</span></div>
            @if($submissionError)<div class="alert alert-danger mt-3 mb-0">{{ \App\Support\CustomerFacingError::message($submissionError) }}</div>@endif
            @php($presentation = $this->currentPresentation)
            <div class="v2-create-actions"><button wire:click="submitCaption" wire:loading.attr="disabled" wire:target="submitCaption,audioFile" @disabled(!$audioFile || $presentation['is_active']) class="btn btn-success px-4"><span wire:loading.remove wire:target="submitCaption,audioFile">{{ __('Create Captions') }}</span><span wire:loading wire:target="submitCaption,audioFile">{{ __('Preparing…') }}</span></button>@if($currentJobId)<span class="v2-job-state glass-load {{ $presentation['glass_class'] }}" @if($presentation['is_active']) wire:poll.5s="pollCaption" @endif>{{ $presentation['label'] }}</span>@endif</div>
        </main>
        <aside class="v2-workspace-panel v2-caption-results-panel">
            <div class="v2-panel-heading"><span>{{ __('Current Caption Result') }}</span><small>{{ __('Latest result') }}</small></div>
            @if($this->currentJob && $presentation['is_active'])<div class="v2-caption-processing"><span class="spinner-border spinner-border-sm"></span>{{ __('Processing your captions…') }}</div>
            @elseif($this->currentJob && $this->currentJob->status === 'failed')<div class="v2-caption-failed">{{ \App\Support\CustomerFacingError::message(data_get($this->currentJob->error, 'message', __('Caption could not be completed.'))) }}</div>
            @elseif($this->currentCaption)<div class="v2-caption-result" dir="auto">@foreach($this->currentCaptionBlocks as $captionBlock)<article class="v2-caption-result-block">@if($captionBlock['timing'])<time>{{ $captionBlock['timing'] }}</time>@endif<p>{{ $captionBlock['text'] }}</p></article>@endforeach</div><div class="d-flex flex-wrap gap-2 mt-2"><button wire:click="copyCaption" class="btn btn-sm btn-outline-success">{{ __('Copy') }}</button>@if(data_get($this->currentJob?->output, 'path'))<a href="{{ route('app.v2.caption.txt', ['locale' => app()->getLocale(), 'jobId' => $currentJobId]) }}" class="btn btn-sm btn-outline-light">{{ __('Download Text') }}</a>@endif@if((string) (data_get($this->currentJob->output, 'srt_path') ?: data_get($this->currentJob->output, 'srt', '')) !== '')<a href="{{ route('app.v2.caption.srt', ['locale' => app()->getLocale(), 'jobId' => $currentJobId]) }}" class="btn btn-sm btn-outline-success">{{ __('Download SRT') }}</a>@endif</div>
            @else<div class="v2-empty-state v2-caption-empty">{{ __('Your caption result will appear here.') }}</div>@endif
            <div class="v2-caption-history"><div class="v2-panel-heading"><span>{{ __('Recent Captions') }}</span><small>{{ __('Caption only') }}</small></div>@forelse($this->recentCaptions as $render)<article class="v2-render-item is-{{ $render['status'] }}" wire:key="caption-render-{{ $render['id'] }}"><div class="d-flex justify-content-between gap-2"><strong dir="auto">{{ $render['name'] }}</strong><span class="v2-render-status is-{{ $render['status'] === 'done' ? 'success' : ($render['status'] === 'failed' ? 'danger' : 'info') }}">{{ app(\App\Support\MetKurdV2JobStatusPresentation::class)->for($render['status'])['label'] }}</span></div><p dir="auto">{{ $render['caption'] ?: __('Caption is still processing.') }}</p><small class="v2-muted">{{ $render['when'] }} @if($render['duration']) · {{ number_format($render['duration'], 1) }}s @endif</small>@if($render['audio_url'])<div class="v2-caption-recent-waveform" wire:ignore data-metkurd-waveform data-job="caption-audio-{{ $render['id'] }}" data-accent="success" data-url="{{ $render['audio_url'] }}"><div class="v2-render-player-controls"><button type="button" class="v2-waveform-toggle" data-metkurd-waveform-toggle aria-label="{{ __('Play or pause original audio') }}"><i class="ri-play-fill" data-metkurd-waveform-icon></i></button><span class="v2-waveform-time" data-metkurd-waveform-time>00:00 / --:--</span></div><div class="v2-waveform-canvas" data-metkurd-waveform-canvas></div></div>@endif<div class="d-flex flex-wrap gap-2 mt-2">@if($render['txt_url'])<a class="btn btn-sm btn-outline-light" href="{{ $render['txt_url'] }}">{{ __('Text') }}</a>@endif@if($render['srt_url'])<a class="btn btn-sm btn-outline-success" href="{{ $render['srt_url'] }}">{{ __('SRT') }}</a>@endif@if($render['status'] === 'done')<button type="button" wire:click="showCaption('{{ $render['id'] }}')" class="btn btn-sm btn-outline-success">{{ __('View Caption') }}</button>@endif@if(in_array($render['status'], ['done', 'delete_failed'], true))<button type="button" wire:click="deleteCaption('{{ $render['id'] }}')" data-v2-confirm="{{ __('Delete this Caption job and its stored files?') }}" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>@endif</div></article>@empty<div class="v2-empty-state">{{ __('Your recent Caption jobs will appear here.') }}</div>@endforelse
            @if($this->recentCaptions->hasPages())<nav class="v2-render-pagination"><button wire:click="previousRecentCaptionsPage" @disabled($this->recentCaptions->onFirstPage())>{{ __('Previous') }}</button><span>{{ $this->recentCaptions->currentPage() }} / {{ $this->recentCaptions->lastPage() }}</span><button wire:click="nextRecentCaptionsPage" @disabled(! $this->recentCaptions->hasMorePages())>{{ __('Next') }}</button></nav>@endif</div>
        </aside>
    </div>
    @if($this->selectedCaption)<div class="modal fade show d-block v2-caption-modal" tabindex="-1" role="dialog" aria-modal="true" wire:keydown.escape="closeCaption"><div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header"><div><small>{{ __('Caption result') }}</small><h2 class="h5 mb-0">{{ data_get($this->selectedCaption->input, 'audio_name', __('Uploaded audio')) }}</h2></div><button type="button" wire:click="closeCaption" class="btn-close btn-close-white" aria-label="{{ __('Close') }}"></button></div><div class="modal-body"><div class="v2-caption-modal-result" dir="auto">{{ data_get($this->selectedCaption->output, 'srt') ?: data_get($this->selectedCaption->output, 'text', '') }}</div></div><div class="modal-footer"><button type="button" wire:click="copySelectedCaption" class="btn btn-success btn-sm">{{ __('Copy') }}</button><a class="btn btn-outline-light btn-sm" href="{{ route('app.v2.caption.txt', ['locale' => app()->getLocale(), 'jobId' => $this->selectedCaption->id]) }}">{{ __('Download Text') }}</a>@if((string) (data_get($this->selectedCaption->output, 'srt_path') ?: data_get($this->selectedCaption->output, 'srt', '')) !== '')<a class="btn btn-outline-success btn-sm" href="{{ route('app.v2.caption.srt', ['locale' => app()->getLocale(), 'jobId' => $this->selectedCaption->id]) }}">{{ __('Download SRT') }}</a>@endif<button type="button" wire:click="closeCaption" class="btn btn-outline-success btn-sm">{{ __('Close') }}</button></div></div></div></div><div class="modal-backdrop fade show"></div>@endif
</section>

@push('styles')
<link href="{{ asset('app/libs/filepond/filepond.min.css') }}" rel="stylesheet">
<style>.metkurd-v2 .v2-caption-workspace{grid-template-columns:minmax(290px,.8fr) minmax(440px,1.35fr);border-color:rgba(var(--v2-accent-rgb),.4);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.14),rgba(3,18,13,.3))}.metkurd-v2 .v2-caption-workspace .v2-panel-heading>span{color:var(--v2-accent-text)}.metkurd-v2 .v2-caption-upload-copy,.metkurd-v2 .v2-caption-file,.metkurd-v2 .v2-caption-processing,.metkurd-v2 .v2-caption-failed{display:flex;gap:.65rem;align-items:center;margin:1rem 0;padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.22);border-radius:.8rem;background:rgba(var(--v2-accent-rgb),.07);font-size:.78rem}.metkurd-v2 .v2-caption-upload-copy i,.metkurd-v2 .v2-caption-file>i{font-size:1.2rem;color:var(--v2-accent-text)}.metkurd-v2 .v2-caption-file span{display:grid;min-width:0;flex:1}.metkurd-v2 .v2-caption-file small,.metkurd-v2 .v2-asr-intelligent small{color:rgba(226,232,240,.55)}.metkurd-v2 .v2-asr-intelligent{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.18);border-radius:.75rem;cursor:pointer}.metkurd-v2 .v2-asr-intelligent span{display:grid;gap:.14rem}.metkurd-v2 .v2-asr-intelligent em{padding:.12rem .34rem;border-radius:999px;background:rgba(245,158,11,.16);color:#fde68a;font-size:.6rem;font-style:normal;text-transform:uppercase}.metkurd-v2 .v2-asr-intelligent input{width:2.35rem;height:1.25rem;accent-color:rgb(var(--v2-accent-rgb))}.metkurd-v2 .v2-caption-results-panel{min-height:575px}.metkurd-v2 .v2-caption-result{max-height:510px;overflow-y:scroll;margin-top:1rem;padding:1rem;border:1px solid rgba(var(--v2-accent-rgb),.22);border-radius:.9rem;background:rgba(2,6,23,.34);white-space:pre-wrap;line-height:1.9;user-select:text}.metkurd-v2 .v2-caption-history{margin-top:1.25rem;padding-top:1rem;border-top:1px solid rgba(148,163,184,.12);display:grid;gap:.65rem}.metkurd-v2 .v2-caption-history .v2-panel-heading{padding-bottom:.65rem}.metkurd-v2 .v2-caption-history .v2-render-item{margin:0}.metkurd-v2 .v2-caption-processing{color:#bbf7d0}.metkurd-v2 .v2-caption-failed{color:#fecaca;border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08)}.metkurd-v2 .v2-caption-upload-waveform,.metkurd-v2 .v2-caption-recent-waveform{margin-top:.75rem}.metkurd-v2 .v2-caption-upload-waveform{padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.28);border-radius:.8rem;background:rgba(var(--v2-accent-rgb),.06)}.metkurd-v2 .v2-caption-recent-waveform .v2-waveform-canvas{min-height:32px}.metkurd-v2 .v2-caption-recent-waveform .v2-render-player-controls{margin:.55rem 0 .3rem}.metkurd-v2 .v2-caption-upload-waveform .v2-waveform-toggle,.metkurd-v2 .v2-caption-recent-waveform .v2-waveform-toggle{border-color:rgba(var(--v2-accent-rgb),.62);background:rgba(var(--v2-accent-rgb),.16);color:var(--v2-accent-text)}.metkurd-v2 .v2-caption-modal .modal-content{border:1px solid rgba(var(--v2-accent-rgb),.38);background:linear-gradient(160deg,#17231a,#0d1210);color:#e5ebe5;box-shadow:0 24px 70px rgba(0,0,0,.5),0 0 30px rgba(var(--v2-accent-rgb),.12)}.metkurd-v2 .v2-caption-modal .modal-header,.metkurd-v2 .v2-caption-modal .modal-footer{border-color:rgba(var(--v2-accent-rgb),.16)}.metkurd-v2 .v2-caption-modal small{color:var(--v2-accent-text);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em}.metkurd-v2 .v2-caption-modal-result{white-space:pre-wrap;line-height:1.95;user-select:text}@media(max-width:767.98px){.metkurd-v2 .v2-caption-workspace{grid-template-columns:1fr}.metkurd-v2 .v2-caption-results-panel{min-height:0}}</style>
@endpush
@push('styles')
<style>
.metkurd-v2 .v2-caption-workspace{display:grid!important;grid-template-columns:minmax(300px,.8fr) minmax(0,1.35fr)!important}.metkurd-v2 .v2-caption-workspace>.v2-workspace-panel{grid-column:auto!important}.metkurd-v2 .v2-asr-intelligent{min-height:4.35rem;border-color:rgba(var(--v2-accent-rgb),.32);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.1),rgba(2,6,23,.36));transition:border-color .16s ease,box-shadow .16s ease}.metkurd-v2 .v2-asr-intelligent:has(input:checked){border-color:rgba(var(--v2-accent-rgb),.82);box-shadow:0 0 0 1px rgba(var(--v2-accent-rgb),.14),0 8px 18px rgba(var(--v2-accent-rgb),.08)}.metkurd-v2 .v2-asr-switch{position:relative;display:block;flex:0 0 2.65rem;width:2.65rem;height:1.45rem}.metkurd-v2 .v2-asr-switch input{position:absolute;inset:0;z-index:1;width:100%;height:100%;margin:0;opacity:0;cursor:pointer}.metkurd-v2 .v2-asr-switch i{position:absolute;inset:0;border:1px solid rgba(148,163,184,.45);border-radius:999px;background:rgba(15,23,42,.9);transition:border-color .16s ease,background .16s ease}.metkurd-v2 .v2-asr-switch i::after{position:absolute;top:3px;left:3px;width:calc(1.45rem - 8px);height:calc(1.45rem - 8px);border-radius:50%;background:#94a3b8;box-shadow:0 1px 5px rgba(0,0,0,.4);content:"";transition:transform .16s ease,background .16s ease}.metkurd-v2 .v2-asr-switch input:checked+i{border-color:rgba(var(--v2-accent-rgb),.9);background:rgba(var(--v2-accent-rgb),.7)}.metkurd-v2 .v2-asr-switch input:checked+i::after{transform:translateX(1.18rem);background:#f0fdf4}.metkurd-v2 .v2-asr-switch input:focus-visible+i{outline:2px solid var(--v2-accent-text);outline-offset:3px}.metkurd-v2 .v2-caption-result{display:grid;gap:.6rem;height:24rem;overflow-y:auto;overflow-x:hidden;padding:.8rem;background:linear-gradient(135deg,rgba(2,6,23,.7),rgba(var(--v2-accent-rgb),.05))}.metkurd-v2 .v2-caption-result-block{display:grid;grid-template-columns:minmax(9.8rem,auto) minmax(0,1fr);gap:.75rem;align-items:start;padding:.7rem .75rem;border:1px solid rgba(var(--v2-accent-rgb),.18);border-radius:.7rem;background:rgba(15,23,42,.48)}.metkurd-v2 .v2-caption-result-block time{padding:.24rem .42rem;border-radius:.42rem;background:rgba(var(--v2-accent-rgb),.12);color:var(--v2-accent-text);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.68rem;font-variant-numeric:tabular-nums;white-space:nowrap}.metkurd-v2 .v2-caption-result-block p{margin:0;line-height:1.75;white-space:pre-wrap}@media(max-width:767.98px){.metkurd-v2 .v2-caption-workspace{grid-template-columns:1fr!important}.metkurd-v2 .v2-caption-result{height:20rem}.metkurd-v2 .v2-caption-result-block{grid-template-columns:1fr;gap:.45rem}.metkurd-v2 .v2-caption-result-block time{justify-self:start}}
</style>
@endpush
