<?php

use App\Models\MlJob;
use App\Services\Media\AudioProbeService;
use App\Services\MetKurd\Jobs\StemV2SubmissionService;
use App\Services\STEM\StemJobSyncService;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\Storage\StorageDestructiveOperationBlocked;
use App\Support\AppRenderPayloads;
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

    protected function processQueueAction(): string { return 'stem.sep'.$this->stems; }
    protected function selectProcessQueueJob(MlJob $job): void
    {
        if (! $this->workspaceJobs()->whereKey($job->id)->exists()) return;
        if ($job->status === 'done') $this->selectedRenderId = (string) $job->id;
        else $this->currentJobId = (string) $job->id;
        unset($this->currentJob, $this->currentPresentation, $this->selectedRender, $this->recentRenders);
        $this->processQueueHistoryPage($this->workspaceJobs(), $job, 'stemV2RendersPage', 'updated_at', 6);
    }

    #[\Livewire\Attributes\Locked] public string $submissionKey = '';
    #[\Livewire\Attributes\Locked] public int $stems = 4;
    public $audioFile = null;
    public ?string $audioName = null;
    public ?string $audioMime = null;
    #[\Livewire\Attributes\Locked] public ?string $audioHash = null;
    #[\Livewire\Attributes\Locked] public ?float $audioDurationSec = null;
    #[\Livewire\Attributes\Locked] public int $audioBillableMinutes = 0;
    #[\Livewire\Attributes\Locked] public int $creditsCost = 0;
    public ?string $currentJobId = null;
    public ?string $selectedRenderId = null;
    public string $submissionError = '';

    public function mount(string $mode): void
    {
        abort_unless(in_array($mode, ['2', '4'], true), 404);
        $this->submissionKey = (string) Str::uuid();
        $this->stems = (int) $mode;
        $this->hydrateWorkspace();
        $this->openProcessQueueJob();
    }

    protected function rules(): array
    {
        return [
            'audioFile' => 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac|max:'.\App\Services\MetKurd\V2\InputBoundary::AUDIO_MAX_KIB,
        ];
    }

    public function updatedAudioFile(AudioProbeService $probe): void
    {
        $this->submissionKey = (string) Str::uuid();
        $this->validateOnly('audioFile');
        if (! $this->audioFile) return;

        try {
            $info = app(\App\Services\MetKurd\V2\InputBoundary::class)->audio($this->audioFile);
            $this->audioName = (string) $this->audioFile->getClientOriginalName();
            $this->audioMime = (string) ($this->audioFile->getMimeType() ?: 'audio/*');
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
        $this->audioFile = null;
        $this->audioName = $this->audioMime = $this->audioHash = null;
        $this->audioDurationSec = null;
        $this->audioBillableMinutes = $this->creditsCost = 0;
        $this->dispatch('stem-v2-audio-cleared');
    }

    public function submitStem(StemV2SubmissionService $submissions): void
    {
        $this->submissionError = '';
        $this->validate();

        try {
            $job = $submissions->submit(auth('app')->user(), $this->audioFile, [
                'submission_key' => $this->submissionKey,
                'stems' => $this->stems,
                'duration_sec' => (float) $this->audioDurationSec,
                'billable_minutes' => $this->audioBillableMinutes,
                'input_hash' => (string) $this->audioHash,
                'audio_name' => (string) $this->audioName,
                'audio_mime' => (string) $this->audioMime,
                'model' => 'htdemucs_ft',
                'stem_codec' => 'mp3',
                'stem_bitrate' => '192k',
            ]);
            $this->currentJobId = (string) $job->id;
            $this->dispatch('metkurd:job-submitted');
            unset($this->currentJob, $this->currentPresentation, $this->recentRenders);
            if ($job->error) $this->submissionError = (string) data_get($job->error, 'message', '');
            $this->removeAudio();
            $this->resetPage('stemV2RendersPage');
            $this->dispatch('header:refresh');
        } catch (\Throwable $exception) {
            $this->submissionError = \App\Support\CustomerFacingError::message($exception->getMessage());
        }
    }

    public function pollStem(StemJobSyncService $sync): void
    {
        $job = $this->currentJob;
        $previousStatus = $job?->status;
        if ($job && $job->isActive()) {
            $sync->sync($job);
            $job->refresh();

            // Keep the result the customer just created in focus. Without
            // this, an older manually selected history item could remain
            // selected after a new separation completes.
            if ((string) $job->status === 'done') {
                $this->selectedRenderId = (string) $job->id;
                $this->currentJobId = null;
                $this->resetPage('stemV2RendersPage');
            }
        }
        $this->hydrateWorkspace();
        if ($job && $previousStatus !== $job->status) $this->dispatch('header:refresh');
    }

    public function selectRender(string $jobId): void
    {
        if ($this->workspaceJobs()->whereKey($jobId)->where('status', 'done')->exists()) {
            $this->selectedRenderId = $jobId;
        }
    }

    public function deleteRender(string $jobId, CustomerOutputStorage $storage): void
    {
        $job = $this->workspaceJobs()->whereKey($jobId)->whereIn('status', ['done', 'delete_failed'])->first();
        if (! $job) return;

        try {
            $storage->deleteStemOutputs($job);
            if ($this->selectedRenderId === (string) $job->id) $this->selectedRenderId = null;
            $this->resetPage('stemV2RendersPage');
            $this->hydrateWorkspace();
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('header:refresh');
        } catch (StorageDestructiveOperationBlocked $exception) {
            $this->submissionError = \App\Support\CustomerFacingError::message($exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->submissionError = __('Failed to delete render. Please try again.');
        }
    }

    #[Computed]
    public function currentJob(): ?MlJob
    {
        return $this->currentJobId ? $this->workspaceJobs()->whereKey($this->currentJobId)->first() : null;
    }

    #[Computed]
    public function currentPresentation(): array
    {
        return app(MetKurdV2JobStatusPresentation::class)->for((string) ($this->currentJob?->status ?? 'idle'));
    }

    #[Computed]
    public function selectedRender(): ?array
    {
        $job = $this->selectedRenderId ? $this->workspaceJobs()->whereKey($this->selectedRenderId)->where('status', 'done')->first() : null;
        $job ??= $this->workspaceJobs()->where('status', 'done')->latest('finished_at')->first();
        if (! $job) return null;

        $locale = app()->getLocale();
        $tracks = collect(AppRenderPayloads::stemTracks($this->stems))->filter(function (string $track) use ($job): bool {
            return $track === 'original'
                ? (string) data_get($job->output, 'original.path', '') !== ''
                : (string) data_get($job->output, "stems.{$track}.path", '') !== '';
        })->values()->all();

        return [
            'id' => (string) $job->id,
            'name' => (string) data_get($job->input, 'audio_name', __('Untitled audio')),
            'tracks' => $tracks,
            'download_all_url' => route('app.v2.stem.zip', ['locale' => $locale, 'jobId' => $job->id]),
            // Stable same-origin playback URL; no object-storage redirect/CORS fetch.
            'stream_urls' => collect($tracks)->mapWithKeys(fn (string $track) => [$track => route('app.v2.stem.stream', ['locale' => $locale, 'jobId' => $job->id, 'track' => $track]).'?proxy=1'])->all(),
            'download_urls' => collect($tracks)->mapWithKeys(fn (string $track) => [$track => route('app.v2.stem.download', ['locale' => $locale, 'jobId' => $job->id, 'track' => $track])])->all(),
        ];
    }

    #[Computed]
    public function recentRenders()
    {
        $locale = app()->getLocale();
        return $this->workspaceJobs()->latest('updated_at')->orderByDesc('id')->paginate(6, pageName: 'stemV2RendersPage')->through(function (MlJob $job) use ($locale): array {
            $status = (string) $job->status;
            $presentation = app(MetKurdV2JobStatusPresentation::class)->for($status);
            return [
                'id' => (string) $job->id,
                'name' => (string) data_get($job->input, 'audio_name', __('Uploaded audio')),
                'status' => $status,
                'presentation' => $presentation,
                'when' => ($when = $job->finished_at ?: $job->updated_at) ? Carbon::parse($when)->diffForHumans() : '',
                'download_url' => $status === 'done' ? route('app.v2.stem.zip', ['locale' => $locale, 'jobId' => $job->id]) : null,
            ];
        });
    }

    public function previousRecentRendersPage(): void { $this->previousPage('stemV2RendersPage'); }
    public function nextRecentRendersPage(): void { $this->nextPage('stemV2RendersPage'); }

    private function workspaceJobs()
    {
        return MlJob::query()
            ->where('customer_id', auth('app')->id())
            ->where('job_kind', 'stem')
            ->where('input->workspace', 'stem_v2')
            ->where('input->separation_mode', $this->stems)
            ->where('status', '!=', 'deleted')
            // The storage library soft-deletes CustomerFile records. A done
            // STEM job is only a render while it still has at least one active
            // render artifact, so deleted folders never linger in History.
            ->where(function ($jobs): void {
                $jobs->where('status', '!=', 'done')
                    ->orWhereExists(function ($files): void {
                        $files->selectRaw('1')
                            ->from('customer_files')
                            ->whereColumn('customer_files.customer_id', 'ml_jobs.customer_id')
                            ->whereColumn('customer_files.source_id', 'ml_jobs.id')
                            ->where('customer_files.tool_code', 'stem')
                            ->where('customer_files.purpose', 'render')
                            ->where('customer_files.status', 'active');
                    });
            });
    }

    private function hydrateWorkspace(): void
    {
        unset($this->currentJob, $this->currentPresentation, $this->selectedRender, $this->recentRenders);
        $active = $this->workspaceJobs()->whereIn('status', ['queued', 'running', 'saving'])->latest('updated_at')->first();
        $this->currentJobId = $active ? (string) $active->id : null;
        if (! $this->selectedRenderId) {
            $this->selectedRenderId = $this->workspaceJobs()->where('status', 'done')->latest('finished_at')->value('id');
        }
    }

    private function refreshCost(): void
    {
        $customer = auth('app')->user();
        $this->creditsCost = $customer && $this->audioDurationSec && $this->audioBillableMinutes
            ? max(0, (int) $customer->priceCreditsFor($this->stems === 2 ? 'stem.sep2' : 'stem.sep4', [
                'metric_code' => 'stem_output', 'outputs' => $this->stems, 'stem_outputs' => $this->stems,
                'separation_mode' => $this->stems, 'minutes' => $this->audioBillableMinutes, 'seconds' => $this->audioDurationSec,
            ]))
            : 0;
    }
}; ?>

@php
    $presentation = $this->currentPresentation;
    $render = $this->selectedRender;
    $labels = ['original' => __('Original'), 'vocals' => __('Vocals'), 'instrumental' => __('Instrumental'), 'drums' => __('Drums'), 'bass' => __('Bass'), 'other' => __('Other')];
    $icons = ['original' => 'ri-disc-line', 'vocals' => 'ri-mic-2-line', 'instrumental' => 'ri-guitar-line', 'drums' => 'ri-equalizer-line', 'bass' => 'ri-music-2-line', 'other' => 'ri-sound-module-line'];
@endphp

<section class="v2-tool-page v2-stem-page" data-stem-messages="{{ json_encode(['Loading audio preview…' => __('Loading audio preview…'), 'Unable to play this audio. Please reopen the result.' => __('Unable to play this audio. Please reopen the result.'), 'Audio uploaded' => __('Audio uploaded'), 'Upload failed' => __('Upload failed'), 'Pause All' => __('Pause All'), 'Play All' => __('Play All'), 'Uploaded' => __('Uploaded'), 'Uploading…' => __('Uploading…'), 'Upload audio' => __('Upload audio'), 'Pause' => __('Pause'), 'Play' => __('Play'), 'Pause source preview' => __('Pause source preview'), 'Play source preview' => __('Play source preview'), 'Ready to review' => __('Ready to review'), 'Ready to retry upload' => __('Ready to retry upload'), 'Uploaded and ready to separate' => __('Uploaded and ready to separate'), 'Uploading audio…' => __('Uploading audio…')]) }}">
    <nav class="v2-breadcrumb"><a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}">{{ __('MetKurd AI') }}</a><span>/</span><a wire:navigate href="{{ route('app.v2.service', ['locale' => app()->getLocale(), 'service' => 'stem']) }}">{{ __('STEM') }}</a><span>/</span><span>{{ $stems }} {{ __('Stem Separation') }}</span></nav>
    <header class="v2-tool-context"><div class="v2-tool-identity d-flex align-items-center gap-3"><img class="v2-service-icon" src="{{ asset('app/services_icons/STEM.png') }}" alt=""><div><span>{{ __('STEM') }}</span><h1>{{ $stems }}-{{ __('Stem Separation') }}</h1></div></div>@livewire('app::v2.components.shared.account-resources')</header>
    <div class="v2-workspace v2-stem-workspace">
        <aside class="v2-stem-left-column">
            <div class="v2-workspace-panel v2-create-panel v2-stem-create-panel">
                <div class="v2-panel-heading"><span>{{ __('Create separation') }}</span><small>{{ __('Upload a source track') }}</small></div>
                <div class="v2-stem-mode mb-3"><i class="ri-git-branch-line"></i><span><strong>{{ $stems }} {{ __('Stems') }}</strong><small>{{ $stems === 2 ? __('Vocals and instrumental') : __('Vocals, drums, bass, and other') }}</small></span></div>
                <div wire:ignore><input id="v2-stem-audio-pond" @include('app.v2.components.upload-size', ['maxKib' => \App\Services\MetKurd\V2\InputBoundary::AUDIO_MAX_KIB]) data-upload-error="{{ __('Upload failed') }}" type="file" accept=".wav,.mp3,.m4a,.aac,.ogg,.webm,.flac,audio/*"></div>
                <div class="v2-stem-source-preview" wire:ignore data-v2-stem-source-preview hidden><div class="v2-stem-source-preview-heading"><span class="v2-stem-source-preview-icon"><i class="ri-headphone-line"></i></span><span><strong>{{ __('Source preview') }}</strong><br><small>{{ __('Listen before starting separation') }}</small></span><span class="v2-stem-source-preview-state mx-3" data-stem-source-state>{{ __('Ready to review') }}</span></div><div class="v2-stem-source-wave" data-v2-stem-source-wave></div><audio preload="metadata" data-v2-stem-source-audio hidden></audio><div class="v2-stem-source-preview-footer"><div class="v2-stem-source-transport"><button type="button" class="v2-stem-source-play btn btn-success btn-sm mb-1" data-stem-source-toggle aria-pressed="false" aria-label="{{ __('Play source preview') }}"><i class="ri-play-fill"></i><span data-stem-source-toggle-label>{{ __('Play') }}</span></button><span class="mx-3" data-stem-source-time>00:00 / --:--</span></div><button type="button" class="btn btn-warning btn-sm" data-stem-upload-source><i class="ri-upload-cloud-2-line"></i> {{ __('Upload audio') }}</button></div><small>{{ __('Happy with this track? Upload it when you are ready.') }}</small></div>
                @if($audioName)<div class="v2-stem-file"><i class="ri-file-music-line"></i><span><strong>{{ $audioName }}</strong><small>{{ $audioMime }} · {{ number_format((float) $audioDurationSec, 1) }}s</small></span><button type="button" wire:click="removeAudio" class="btn btn-sm btn-outline-warning">{{ __('Remove') }}</button></div>@endif
                @error('audioFile')<small class="text-danger mt-2">{{ $message }}</small>@enderror
                @if($submissionError)<div class="alert alert-danger mt-3 mb-0">{{ \App\Support\CustomerFacingError::message($submissionError) }}</div>@endif
                <div class="v2-editor-footer mt-3"><span>{{ __('Estimated cost') }}</span><span>{{ number_format($creditsCost) }} {{ __('credits') }}</span></div>
                <div class="v2-create-actions"><button wire:click="submitStem" wire:loading.attr="disabled" wire:target="submitStem,audioFile" @disabled(!$audioFile || $presentation['is_active']) class="btn btn-warning px-4"><span wire:loading.remove wire:target="submitStem,audioFile">{{ __('Separate') }}</span><span wire:loading wire:target="submitStem,audioFile">{{ __('Preparing…') }}</span></button></div>
            </div>
            <div class="v2-workspace-panel v2-stem-history-panel">
                <div class="v2-panel-heading"><span>{{ __('History') }}</span><small>{{ $stems }}-{{ __('stem jobs') }}</small></div>
                <div class="v2-render-list">@forelse($this->recentRenders as $item)<article class="v2-render-item is-{{ $item['presentation']['semantic'] }} {{ $selectedRenderId === $item['id'] ? 'is-selected' : '' }}" wire:key="stem-v2-{{ $stems }}-{{ $item['id'] }}"><button type="button" wire:click="selectRender('{{ $item['id'] }}')"><span><strong>{{ $item['name'] }}</strong><small>{{ $item['when'] }}</small></span><span class="v2-render-status is-{{ $item['presentation']['semantic'] }}">{{ $item['presentation']['label'] }}</span></button><div class="v2-stem-history-actions">@if($item['download_url'])<a href="{{ $item['download_url'] }}" class="btn btn-sm btn-outline-warning">{{ __('Download') }}</a>@endif@if(in_array($item['status'], ['done', 'delete_failed'], true))<button type="button" wire:click="deleteRender('{{ $item['id'] }}')" data-v2-confirm="{{ __('Delete this render?') }}" class="btn btn-sm btn-outline-light">{{ __('Delete') }}</button>@endif</div></article>@empty<div class="v2-empty-state">{{ __('Your STEM renders will appear here.') }}</div>@endforelse</div>
                @if($this->recentRenders->hasPages())<nav class="v2-render-pagination"><button wire:click="previousRecentRendersPage" @disabled($this->recentRenders->onFirstPage())>{{ __('Previous') }}</button><span>{{ $this->recentRenders->currentPage() }} / {{ $this->recentRenders->lastPage() }}</span><button wire:click="nextRecentRendersPage" @disabled(! $this->recentRenders->hasMorePages())>{{ __('Next') }}</button></nav>@endif
            </div>
        </aside>
        <main class="v2-workspace-panel v2-stem-player-panel">
            <div class="v2-panel-heading"><span>{{ __('STEM Player') }}</span><small>{{ $presentation['is_active'] ? data_get($this->currentJob->input, 'audio_name', __('Untitled audio')) : ($render ? $render['name'] : __('Select a completed separation')) }}</small></div>
            @if($this->currentJob && $presentation['is_active'])<div class="v2-stem-processing" wire:key="stem-status-{{ $currentJobId }}" wire:poll.5s="pollStem" role="status" aria-live="polite"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><div class="d-grid gap-1"><strong>{{ __('Current job') }} · {{ $presentation['label'] }}</strong><span dir="auto">{{ data_get($this->currentJob->input, 'audio_name', __('Untitled audio')) }}</span><small>{{ __('Separating your audio…') }}</small></div></div>
            @elseif($render)<div wire:ignore wire:key="stem-player-{{ $render['id'] }}-{{ implode('-', $render['tracks']) }}" class="v2-stem-player" data-v2-stem-player data-job="{{ $render['id'] }}"><div class="v2-stem-transport"><button type="button" class="btn btn-warning" data-stem-play-all><i class="ri-play-fill"></i> <span>{{ __('Play All') }}</span></button><button type="button" class="btn btn-outline-light" data-stem-stop-all><i class="ri-stop-fill"></i> {{ __('Stop All') }}</button><a href="{{ $render['download_all_url'] }}" class="btn btn-outline-warning ms-auto"><i class="ri-download-2-line"></i> {{ __('Download all') }}</a></div><p data-stem-player-status role="status" class="v2-muted mt-2" hidden></p><div class="v2-stem-timeline"><span data-stem-current>00:00</span><input type="range" min="0" max="0" value="0" step="0.01" data-stem-timeline aria-label="{{ __('Shared timeline') }}"><span data-stem-duration>--:--</span></div><div class="v2-stem-track-list">@foreach($render['tracks'] as $track)<article class="v2-stem-track" data-stem-track="{{ $track }}"><div class="v2-stem-track-identity"><i class="{{ $icons[$track] ?? 'ri-music-line' }}"></i><strong>{{ $labels[$track] ?? Str::headline($track) }}</strong><small data-stem-track-time>00:00</small></div><div class="v2-stem-track-actions"><button type="button" data-stem-toggle aria-label="{{ __('Play or pause :track', ['track' => $labels[$track] ?? $track]) }}"><i class="ri-play-fill"></i></button><button type="button" data-stem-mute aria-pressed="false">{{ __('Mute') }}</button><button type="button" data-stem-solo aria-pressed="false">{{ __('Solo') }}</button><a href="{{ $render['download_urls'][$track] }}" aria-label="{{ __('Download :track', ['track' => $labels[$track] ?? $track]) }}"><i class="ri-download-2-line"></i></a></div><div class="v2-stem-wave" wire:ignore data-stem-wave data-url="{{ $render['stream_urls'][$track] }}"></div><audio preload="none" data-stem-audio data-src="{{ $render['stream_urls'][$track] }}"></audio><div class="v2-stem-track-meter"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div></article>@endforeach</div></div>
            @else<div class="v2-empty-state v2-stem-empty"><i class="ri-music-2-line"></i><span>{{ __('Your separated stems will be ready to mix and compare here.') }}</span></div>@endif
        </main>
    </div>
</section>

@push('styles')<link href="{{ asset('app/libs/filepond/filepond.min.css') }}" rel="stylesheet"><link href="{{ asset('app/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet"><style>
.metkurd-v2 .v2-stem-workspace{grid-template-columns:minmax(290px,.72fr) minmax(520px,1.55fr);align-items:start;border-color:rgba(var(--v2-accent-rgb),.42);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.12),rgba(20,14,3,.35))}.metkurd-v2 .v2-stem-left-column{display:grid;}.metkurd-v2 .v2-stem-create-panel{min-height:0}.metkurd-v2 .v2-stem-mode,.metkurd-v2 .v2-stem-file,.metkurd-v2 .v2-stem-processing{display:flex;align-items:center;gap:.7rem;margin-top:1rem;padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.26);border-radius:.8rem;background:rgba(var(--v2-accent-rgb),.08)}.metkurd-v2 .v2-stem-mode>i,.metkurd-v2 .v2-stem-file>i{font-size:1.25rem;color:var(--v2-accent-text)}.metkurd-v2 .v2-stem-mode span,.metkurd-v2 .v2-stem-file span{display:grid;min-width:0;flex:1}.metkurd-v2 .v2-stem-mode small,.metkurd-v2 .v2-stem-file small{color:rgba(226,232,240,.55);font-size:.69rem}.metkurd-v2 .v2-stem-history-panel{max-height:440px;overflow:auto}.metkurd-v2 .v2-stem-history-panel .v2-render-item{padding:.65rem}.metkurd-v2 .v2-stem-history-panel .v2-render-item>button{display:flex;justify-content:space-between;align-items:start;gap:.5rem;width:100%;padding:0;border:0;background:transparent;color:inherit;text-align:left}.metkurd-v2 .v2-stem-history-panel .v2-render-item>button span:first-child{display:grid;gap:.16rem;min-width:0}.metkurd-v2 .v2-stem-history-panel .v2-render-item>button strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.metkurd-v2 .v2-stem-history-panel .v2-render-item>button small{color:rgba(226,232,240,.52);font-size:.65rem}.metkurd-v2 .v2-stem-history-panel .is-selected{border-color:rgba(var(--v2-accent-rgb),.8);box-shadow:0 0 0 1px rgba(var(--v2-accent-rgb),.18)}.metkurd-v2 .v2-stem-history-actions{display:flex;gap:.4rem;margin-top:.55rem}.metkurd-v2 .v2-stem-player-panel{min-height:590px}.metkurd-v2 .v2-stem-processing{color:var(--v2-accent-text)}.metkurd-v2 .v2-stem-player{margin-top:1.1rem;padding:1rem;border:1px solid rgba(var(--v2-accent-rgb),.25);border-radius:1rem;background:rgba(2,6,23,.4)}.metkurd-v2 .v2-stem-transport{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap}.metkurd-v2 .v2-stem-timeline{display:grid;grid-template-columns:45px minmax(0,1fr) 45px;align-items:center;gap:.65rem;margin:1rem 0;color:rgba(226,232,240,.62);font-size:.7rem;font-variant-numeric:tabular-nums}.metkurd-v2 .v2-stem-timeline input{width:100%;accent-color:rgb(var(--v2-accent-rgb))}.metkurd-v2 .v2-stem-track-list{display:grid;gap:.65rem}.metkurd-v2 .v2-stem-track{display:grid;grid-template-columns:minmax(120px,1fr) auto;align-items:center;padding:.75rem;border:1px solid rgba(148,163,184,.18);border-radius:.8rem;background:linear-gradient(120deg,rgba(15,23,42,.8),rgba(var(--v2-accent-rgb),.06))}.metkurd-v2 .v2-stem-track.is-solo{border-color:rgba(var(--v2-accent-rgb),.85);box-shadow:0 0 0 1px rgba(var(--v2-accent-rgb),.15)}.metkurd-v2 .v2-stem-track.is-muted{opacity:.6}.metkurd-v2 .v2-stem-track-identity{display:flex;align-items:center;gap:.55rem;min-width:0}.metkurd-v2 .v2-stem-track-identity i{color:var(--v2-accent-text);font-size:1.15rem}.metkurd-v2 .v2-stem-track-identity strong{font-size:.78rem}.metkurd-v2 .v2-stem-track-actions{display:flex;align-items:center;gap:.35rem}.metkurd-v2 .v2-stem-track-actions button,.metkurd-v2 .v2-stem-track-actions a{display:grid;place-items:center;min-width:2rem;height:2rem;padding:0 .45rem;border:1px solid rgba(148,163,184,.26);border-radius:.5rem;background:rgba(2,6,23,.35);color:#e2e8f0;font-size:.68rem;text-decoration:none}.metkurd-v2 .v2-stem-track-actions button:first-child{color:var(--v2-accent-text);border-color:rgba(var(--v2-accent-rgb),.58)}.metkurd-v2 .v2-stem-track-actions button[aria-pressed="true"]{border-color:rgba(var(--v2-accent-rgb),.86);background:rgba(var(--v2-accent-rgb),.22);color:#fff}.metkurd-v2 .v2-stem-track-meter{grid-column:1/-1;height:15px;display:flex;align-items:center;gap:3px;overflow:hidden;color:rgba(var(--v2-accent-rgb),.8)}.metkurd-v2 .v2-stem-track-meter i{width:4px;height:5px;border-radius:99px;background:currentColor;opacity:.35}.metkurd-v2 .v2-stem-track.is-playing .v2-stem-track-meter i{opacity:.9;animation:v2StemMeter .8s ease-in-out infinite alternate}.metkurd-v2 .v2-stem-track.is-playing .v2-stem-track-meter i:nth-child(2n){height:13px;animation-delay:.12s}.metkurd-v2 .v2-stem-track.is-playing .v2-stem-track-meter i:nth-child(3n){height:9px;animation-delay:.26s}@keyframes v2StemMeter{to{transform:scaleY(.35);opacity:.45}}.metkurd-v2 .v2-stem-empty{min-height:420px;display:grid;place-items:center;align-content:center;gap:.7rem;text-align:center}.metkurd-v2 .v2-stem-empty i{font-size:3rem;color:rgba(var(--v2-accent-rgb),.6)}.metkurd-v2--stem .v2-stem-page .btn-warning{border-color:#f97316;background:#f97316;color:#fff}.metkurd-v2--stem .v2-stem-page .btn-warning:hover,.metkurd-v2--stem .v2-stem-page .btn-warning:focus{border-color:#ea580c;background:#ea580c;color:#fff}.metkurd-v2--stem .v2-stem-page .btn-outline-warning{border-color:rgba(var(--v2-accent-rgb),.72);color:var(--v2-accent-text)}.metkurd-v2--stem .v2-stem-page .btn-outline-warning:hover{border-color:#f97316;background:rgba(var(--v2-accent-rgb),.18);color:#fff}.metkurd-v2--stem .v2-stem-page .glass-load--warning{--glass-accent:rgba(var(--v2-accent-rgb),.2);border-color:rgba(var(--v2-accent-rgb),.34)}@media(max-width:991.98px){.metkurd-v2 .v2-stem-workspace{grid-template-columns:1fr}.metkurd-v2 .v2-stem-left-column{grid-template-columns:1fr 1fr}.metkurd-v2 .v2-stem-history-panel{max-height:none}}@media(max-width:575.98px){.metkurd-v2 .v2-stem-left-column{grid-template-columns:1fr}.metkurd-v2 .v2-stem-transport .ms-auto{margin-left:0!important}.metkurd-v2 .v2-stem-track{grid-template-columns:1fr}.metkurd-v2 .v2-stem-track-actions{justify-content:space-between}.metkurd-v2 .v2-stem-player-panel{min-height:0}}
</style>
<style>
.metkurd-v2 .v2-stem-track{grid-template-columns:minmax(130px,.72fr) auto;}
.metkurd-v2 .v2-stem-track-identity small{margin-left:auto;color:rgba(226,232,240,.5);font-size:.66rem;font-variant-numeric:tabular-nums}
.metkurd-v2 .v2-stem-wave{grid-column:1/-1;min-height:66px;padding:.35rem .15rem;border:1px solid rgba(var(--v2-accent-rgb),.16);border-radius:.55rem;background:rgba(2,6,23,.26);cursor:pointer}
.metkurd-v2 .v2-stem-wave wave{overflow:hidden;border-radius:.35rem}
.metkurd-v2 .v2-stem-wave ::part(cursor){opacity:.92}
.metkurd-v2 .v2-stem-source-preview{display:grid;gap:.8rem;margin-top:1rem;padding:.85rem;border:1px solid rgba(var(--v2-accent-rgb),.42);border-radius:.9rem;background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.13),rgba(2,6,23,.46));box-shadow:inset 0 1px 0 rgba(255,255,255,.04)}
.metkurd-v2 .v2-stem-source-preview-heading,.metkurd-v2 .v2-stem-source-preview-footer{display:flex;align-items:center;gap:.6rem}
.metkurd-v2 .v2-stem-source-preview-heading>span:nth-child(2){display:grid;gap:.1rem;min-width:0;color:#e2e8f0;font-size:.76rem}
.metkurd-v2 .v2-stem-source-preview-icon{display:grid;place-items:center;width:2rem;height:2rem;border-radius:.65rem;background:rgba(var(--v2-accent-rgb),.18);color:var(--v2-accent-text);font-size:1.05rem}
.metkurd-v2 .v2-stem-source-preview small{color:rgba(226,232,240,.58);font-size:.65rem}
.metkurd-v2 .v2-stem-source-preview-state{margin-left:auto;padding:.24rem .45rem;border-radius:999px;background:rgba(var(--v2-accent-rgb),.15);color:var(--v2-accent-text);font-size:.62rem;white-space:nowrap}
.metkurd-v2 .v2-stem-source-preview.is-uploading .v2-stem-source-preview-state{background:rgba(251,191,36,.16);color:#fde68a}.metkurd-v2 .v2-stem-source-preview.is-uploaded .v2-stem-source-preview-state{background:rgba(34,197,94,.18);color:#86efac}
.metkurd-v2 .v2-stem-source-wave{min-height:64px;padding:.3rem;border:1px solid rgba(var(--v2-accent-rgb),.2);border-radius:.62rem;background:rgba(2,6,23,.34);cursor:pointer}.metkurd-v2 .v2-stem-source-wave wave{overflow:hidden;border-radius:.35rem}
.metkurd-v2 .v2-stem-source-preview-footer{justify-content:space-between}.metkurd-v2 .v2-stem-source-transport{display:flex;align-items:center;gap:.55rem;color:rgba(226,232,240,.65);font-size:.68rem;font-variant-numeric:tabular-nums}.metkurd-v2 .v2-stem-source-play{display:inline-flex;align-items:center;gap:.42rem;min-height:2.25rem;padding:.2rem .7rem .2rem .25rem;border:1px solid rgba(var(--v2-accent-rgb),.64);border-radius:999px;background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.3),rgba(var(--v2-accent-rgb),.12));box-shadow:0 6px 16px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.12);color:#fff;font-size:.68rem;font-weight:700;line-height:1;transition:transform .16s ease,border-color .16s ease,background .16s ease,box-shadow .16s ease}.metkurd-v2 .v2-stem-source-play>i{display:grid;place-items:center;width:1.72rem;height:1.72rem;border-radius:999px;background:#f97316;color:#fff;font-size:1rem;box-shadow:0 3px 8px rgba(249,115,22,.35)}.metkurd-v2 .v2-stem-source-play:hover{transform:translateY(-1px);border-color:rgba(var(--v2-accent-rgb),.95);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.45),rgba(var(--v2-accent-rgb),.18));box-shadow:0 9px 20px rgba(0,0,0,.25),0 0 0 3px rgba(var(--v2-accent-rgb),.1)}.metkurd-v2 .v2-stem-source-play:focus-visible{outline:0;box-shadow:0 0 0 3px rgba(255,255,255,.85),0 0 0 5px rgba(var(--v2-accent-rgb),.65)}.metkurd-v2 .v2-stem-source-play[aria-pressed="true"]{border-color:#fb923c;background:linear-gradient(135deg,#ea580c,#f97316);box-shadow:0 8px 18px rgba(234,88,12,.34)}.metkurd-v2 .v2-stem-source-play[aria-pressed="true"]>i{background:rgba(255,255,255,.2);box-shadow:none}
.metkurd-v2 .v2-stem-workspace{gap:1px;overflow:hidden;border-color:rgba(var(--v2-accent-rgb),.4);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.14),rgba(38,21,4,.34));box-shadow:0 20px 52px rgba(0,0,0,.2),0 0 30px rgba(var(--v2-accent-rgb),.06)}
.metkurd-v2 .v2-stem-workspace>.v2-stem-left-column,.metkurd-v2 .v2-stem-workspace>.v2-stem-player-panel{background:linear-gradient(155deg,rgba(31,28,24,.94),rgba(13,18,26,.97))}
.metkurd-v2 .v2-stem-left-column{gap:1px;background:rgba(var(--v2-accent-rgb),.12)}
.metkurd-v2 .v2-stem-create-panel{background:linear-gradient(145deg,rgba(var(--v2-accent-rgb),.08),rgba(2,6,23,.72))}
.metkurd-v2 .v2-stem-history-panel{scrollbar-color:rgba(var(--v2-accent-rgb),.62) rgba(2,6,23,.44);scrollbar-width:thin;background:linear-gradient(155deg,rgba(20,20,23,.96),rgba(10,14,21,.98))}
.metkurd-v2 .v2-stem-history-panel::-webkit-scrollbar{width:8px}.metkurd-v2 .v2-stem-history-panel::-webkit-scrollbar-track{margin:.55rem 0;border-radius:999px;background:rgba(2,6,23,.46)}.metkurd-v2 .v2-stem-history-panel::-webkit-scrollbar-thumb{border:2px solid rgba(2,6,23,.46);border-radius:999px;background:rgba(var(--v2-accent-rgb),.62)}.metkurd-v2 .v2-stem-history-panel::-webkit-scrollbar-thumb:hover{background:rgba(var(--v2-accent-rgb),.9)}
.metkurd-v2 .v2-stem-history-panel .v2-render-item{margin:.2rem .65rem;padding:.72rem;border:1px solid transparent;border-radius:.72rem;background:linear-gradient(135deg,rgba(148,163,184,.04),rgba(2,6,23,.2));transition:border-color .16s ease,background .16s ease,transform .16s ease}.metkurd-v2 .v2-stem-history-panel .v2-render-item:hover{transform:translateY(-1px);border-color:rgba(var(--v2-accent-rgb),.34);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.11),rgba(2,6,23,.3))}.metkurd-v2 .v2-stem-history-panel .v2-render-item.is-selected{background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.18),rgba(2,6,23,.38));box-shadow:inset 3px 0 0 rgb(var(--v2-accent-rgb)),0 0 0 1px rgba(var(--v2-accent-rgb),.13)}
.metkurd-v2 .v2-stem-player-panel{position:relative;overflow:hidden;background:linear-gradient(145deg,rgba(30,26,21,.96),rgba(10,14,21,.98))}.metkurd-v2 .v2-stem-player-panel:before{position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 94% 0,rgba(var(--v2-accent-rgb),.12),transparent 32%);content:""}.metkurd-v2 .v2-stem-player-panel>*{position:relative}
.metkurd-v2 .v2-stem-player{border-color:rgba(var(--v2-accent-rgb),.3);background:linear-gradient(145deg,rgba(2,6,23,.72),rgba(var(--v2-accent-rgb),.055));box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 14px 30px rgba(0,0,0,.14)}
.metkurd-v2 .v2-stem-timeline{padding:.65rem .7rem;border:1px solid rgba(148,163,184,.13);border-radius:.72rem;background:rgba(2,6,23,.31)}.metkurd-v2 .v2-stem-timeline input{height:5px;border-radius:999px;background:rgba(148,163,184,.24)}
.metkurd-v2 .v2-stem-track{border-color:rgba(148,163,184,.15);background:linear-gradient(135deg,rgba(30,34,43,.78),rgba(var(--v2-accent-rgb),.055));box-shadow:inset 0 1px 0 rgba(255,255,255,.025);transition:transform .16s ease,border-color .16s ease,background .16s ease,box-shadow .16s ease}.metkurd-v2 .v2-stem-track:hover{transform:translateY(-1px);border-color:rgba(var(--v2-accent-rgb),.42);background:linear-gradient(135deg,rgba(30,34,43,.9),rgba(var(--v2-accent-rgb),.12));box-shadow:0 8px 18px rgba(0,0,0,.14),inset 0 1px 0 rgba(255,255,255,.04)}.metkurd-v2 .v2-stem-track.is-playing{border-color:rgba(var(--v2-accent-rgb),.7);box-shadow:inset 3px 0 0 rgb(var(--v2-accent-rgb)),0 0 0 1px rgba(var(--v2-accent-rgb),.1)}
.metkurd-v2 .v2-stem-wave{background:linear-gradient(135deg,rgba(2,6,23,.6),rgba(var(--v2-accent-rgb),.06))}.metkurd-v2 .v2-stem-track-actions button:hover,.metkurd-v2 .v2-stem-track-actions a:hover{border-color:rgba(var(--v2-accent-rgb),.7);background:rgba(var(--v2-accent-rgb),.15);color:#fff}
</style>@endpush

@push('scripts')<script data-navigate-once>
(window.MetKurdV2Pages ||= []).push({key: 'stem', selector: '.v2-stem-page',
    prepare: () => Promise.all([window.MetKurdV2Assets.filePond(), window.MetKurdV2Assets.load('WaveSurfer')]),
    boot(ctx) {
    let messagesRoot, messages = {};
    const t = key => { const root = ctx.root; if (root !== messagesRoot) { messagesRoot = root; messages = JSON.parse(root?.dataset.stemMessages || '{}'); } return messages[key] || key; };
    const format = seconds => { seconds = Math.max(0, Math.floor(seconds || 0)); return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`; };
    const mountedPlayers = new Set();
    const bootPlayer = root => {
        if (!root || mountedPlayers.has(root) || !window.WaveSurfer) return;
        mountedPlayers.add(root);
        root.__stemDestroy = window.MetKurdStemPlayer.mount(root, t, format);
    };
    const destroyPlayers = () => { [...mountedPlayers].forEach(root => root.__stemDestroy?.()); mountedPlayers.clear(); };
    const boot = () => { [...mountedPlayers].filter(root => !root.isConnected).forEach(root => { root.__stemDestroy?.(); mountedPlayers.delete(root); }); ctx.root.querySelectorAll('[data-v2-stem-player]').forEach(bootPlayer); };
    let pond, pondHost, uploading = false, clearing = false, sourcePreviewUrl = null, sourcePreviewWave = null;
    const componentRoot = () => ctx.root.closest('[wire\\:id]');
    const component = ctx.component;
    const setSourcePreviewState = (state, label) => { const preview = ctx.root.querySelector('[data-v2-stem-source-preview]'), status = ctx.root.querySelector('[data-stem-source-state]'), upload = ctx.root.querySelector('[data-stem-upload-source]'); if (!preview) return; preview.classList.toggle('is-uploading', state === 'uploading'); preview.classList.toggle('is-uploaded', state === 'uploaded'); if (status) status.textContent = label; if (upload) { upload.disabled = state === 'uploading' || state === 'uploaded'; upload.innerHTML = state === 'uploaded' ? '<i class="ri-checkbox-circle-line"></i> '+t('Uploaded') : state === 'uploading' ? '<span class="spinner-border spinner-border-sm"></span> '+t('Uploading…') : '<i class="ri-upload-cloud-2-line"></i> '+t('Upload audio'); } };
    const updateSourcePreviewTransport = () => { const toggle = ctx.root.querySelector('[data-stem-source-toggle]'), time = ctx.root.querySelector('[data-stem-source-time]'), audio = ctx.root.querySelector('[data-v2-stem-source-audio]'); const player = sourcePreviewWave || audio; if (!player) return; const current = sourcePreviewWave ? sourcePreviewWave.getCurrentTime() : audio.currentTime; const duration = sourcePreviewWave ? sourcePreviewWave.getDuration() : audio.duration; if (time) time.textContent = `${format(current)} / ${Number.isFinite(duration) ? format(duration) : '--:--'}`; if (toggle) { const playing = sourcePreviewWave ? sourcePreviewWave.isPlaying() : !audio.paused; toggle.querySelector('i').className = playing ? 'ri-pause-fill' : 'ri-play-fill'; toggle.querySelector('[data-stem-source-toggle-label]').textContent = playing ? t('Pause') : t('Play'); toggle.setAttribute('aria-pressed', playing ? 'true' : 'false'); toggle.setAttribute('aria-label', playing ? t('Pause source preview') : t('Play source preview')); } };
    const clearSourcePreview = () => { const preview = ctx.root.querySelector('[data-v2-stem-source-preview]'), audio = ctx.root.querySelector('[data-v2-stem-source-audio]'), wave = ctx.root.querySelector('[data-v2-stem-source-wave]'); try { sourcePreviewWave?.destroy(); } catch (_) {} sourcePreviewWave = null; if (wave) wave.replaceChildren(); if (audio) { audio.pause(); audio.removeAttribute('src'); audio.hidden = true; audio.load(); } if (sourcePreviewUrl) URL.revokeObjectURL(sourcePreviewUrl); sourcePreviewUrl = null; if (preview) { preview.hidden = true; preview.classList.remove('is-uploading', 'is-uploaded'); } };
    const previewSourceFile = file => { if (!file) return; clearSourcePreview(); const preview = ctx.root.querySelector('[data-v2-stem-source-preview]'), audio = ctx.root.querySelector('[data-v2-stem-source-audio]'), wave = ctx.root.querySelector('[data-v2-stem-source-wave]'); if (!preview || !audio || !wave) return; sourcePreviewUrl = URL.createObjectURL(file); preview.hidden = false; setSourcePreviewState('ready', t('Ready to review')); if (!window.WaveSurfer) { audio.src = sourcePreviewUrl; audio.hidden = false; audio.addEventListener('loadedmetadata', updateSourcePreviewTransport, { once: true }); audio.addEventListener('timeupdate', updateSourcePreviewTransport); audio.addEventListener('play', updateSourcePreviewTransport); audio.addEventListener('pause', updateSourcePreviewTransport); return; } try { sourcePreviewWave = window.WaveSurfer.create({ container: wave, url: sourcePreviewUrl, height: 58, waveColor: 'rgba(249,115,22,.36)', progressColor: '#f97316', cursorColor: '#fed7aa', cursorWidth: 2, barWidth: 2, barGap: 2, barRadius: 2, normalize: true, interact: true, autoScroll: false }); sourcePreviewWave.on('ready', updateSourcePreviewTransport); sourcePreviewWave.on('timeupdate', updateSourcePreviewTransport); sourcePreviewWave.on('play', updateSourcePreviewTransport); sourcePreviewWave.on('pause', updateSourcePreviewTransport); sourcePreviewWave.on('finish', updateSourcePreviewTransport); sourcePreviewWave.on('error', () => { if (!ctx.alive()) return; try { sourcePreviewWave?.destroy(); } catch (_) {} sourcePreviewWave = null; audio.src = sourcePreviewUrl; audio.hidden = false; audio.addEventListener('loadedmetadata', updateSourcePreviewTransport, { once: true }); }); } catch (_) { audio.src = sourcePreviewUrl; audio.hidden = false; audio.addEventListener('loadedmetadata', updateSourcePreviewTransport, { once: true }); } };
    const notifyUploadComplete = () => window.dispatchEvent(new CustomEvent('alert', { detail: { type: 'success', message: t('Audio uploaded') } }));
    const bootPond = () => {
        const input = ctx.root.querySelector('#v2-stem-audio-pond');
        if (!input || pond || !window.FilePond || !component()) return;
        pondHost = componentRoot();
        window.MetKurdV2Assets.disposePond(window.FilePond.find(input));
        pond = window.FilePond.create(input, {
            allowMultiple: false,
            allowReplace: true,
            allowProcess: false,
            instantUpload: false,
            credits: false,
            acceptedFileTypes: ['audio/wav','audio/x-wav','audio/mpeg','audio/mp3','audio/mp4','audio/x-m4a','audio/aac','audio/ogg','audio/webm','audio/flac','audio/x-flac'],
            ...window.MetKurdV2Assets.uploadSizeOptions(input),
            onaddfile: (_, fileItem) => previewSourceFile(fileItem?.file),
            onremovefile: () => clearSourcePreview(),
            onprocessfile: (error, fileItem) => { if (error) { setSourcePreviewState('ready', t('Ready to retry upload')); return; } setSourcePreviewState('uploaded', t('Uploaded and ready to separate')); notifyUploadComplete(fileItem?.filename); },
            onprocessfileprogress: () => setSourcePreviewState('uploading', t('Uploading audio…')),
            server: {
                process: (_, file, __, load, error, progress, abort) => {
                    const lw = component();
                    if (!lw) {
                        error(t('Upload failed'));
                        return { abort };
                    }

                    uploading = true;
                    lw.upload(
                        'audioFile',
                        file,
                        temporaryName => { uploading = false; if (ctx.alive()) load(temporaryName); },
                        () => { uploading = false; if (ctx.alive()) error(t('Upload failed')); },
                        event => { if (ctx.alive()) progress(event.lengthComputable, event.loaded, event.total); },
                    );

                    return {
                        abort: () => {
                            uploading = false; lw.cancelUpload('audioFile');
                            abort();
                        },
                    };
                },
                revert: (_, load, error) => {
                    if (clearing) { load(); return; }
                    const lw = component();
                    if (!lw) { error(t('Upload failed')); return; }
                    Promise.resolve(lw.call('removeAudio')).then(load, () => { uploading = false; if (ctx.alive()) error(t('Upload failed')); });
                },
            },
        });
    };
    const destroyPond = () => { if (!pond) return; clearing = true; try { window.MetKurdV2Assets.disposePond(pond); } finally { pond = undefined; pondHost = null; clearing = false; clearSourcePreview(); } };
    const reconcile = () => {
        if (!ctx.alive()) return;
        if (pond && (pondHost !== componentRoot() || pond.element?.isConnected === false)) destroyPond();
        boot(); bootPond();
    };
    ctx.on('stem-v2-audio-cleared', () => { if (pond) { clearing = true; try { pond.removeFiles({ revert: false }); } finally { clearing = false; } } clearSourcePreview(); });
    ctx.listen(ctx.root, 'click', event => { const sourceToggle = event.target.closest('[data-stem-source-toggle]'); if (sourceToggle) { if (sourcePreviewWave) sourcePreviewWave.playPause(); else { const audio = ctx.root.querySelector('[data-v2-stem-source-audio]'); if (audio) audio.paused ? audio.play().catch(() => {}) : audio.pause(); } return; } const upload = event.target.closest('[data-stem-upload-source]'); if (!upload || !pond) return; const files = pond.getFiles().filter(file => !file.archived); if (!files.length) return; setSourcePreviewState('uploading', t('Uploading audio…')); pond.processFiles().catch(() => setSourcePreviewState('ready', t('Ready to retry upload'))); });
    const owner = component();
    ctx.cleanup(() => { if (uploading) owner?.cancelUpload('audioFile'); uploading = false; });
    reconcile();
    return {update: reconcile, destroy: () => { destroyPlayers(); destroyPond(); clearSourcePreview(); }};
}});
</script>@endpush
