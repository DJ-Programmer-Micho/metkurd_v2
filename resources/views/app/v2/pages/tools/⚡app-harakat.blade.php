<?php

use App\Models\MlJob;
use App\Services\Harakat\HarakatJobSyncService;
use App\Services\Harakat\HarakatResults;
use App\Services\MetKurd\Jobs\HarakatSubmissionService;
use App\Services\MetKurd\V2\HarakatInput;
use App\Support\CustomerFacingError;
use App\Support\MetKurdV2JobStatusPresentation;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('app::v2.layouts.app')] class extends Component {
    use WithPagination;
    use \App\Support\OpensProcessQueueJob;

    protected function processQueueAction(): string { return 'harakat.diacritize'; }
    protected function selectProcessQueueJob(MlJob $job): void
    {
        $this->currentJobId = (string) $job->id;
        $this->text = (string) data_get($job->input, 'text');
        $this->submissionKey = (string) $job->submission_key;
        $this->forgetJobView();
        unset($this->characters, $this->creditsCost);
    }

    public string $text = '';
    #[Locked] public string $submissionKey;
    #[Locked] public ?string $currentJobId = null;
    public string $submissionError = '';

    public function mount(): void
    {
        $this->authorizeTool();
        $this->submissionKey = (string) Str::uuid();
        $this->currentJobId = $this->jobs()->active()->latest()->value('id');
        if ($this->currentJob) {
            $this->text = (string) data_get($this->currentJob->input, 'text');
            $this->submissionKey = (string) $this->currentJob->submission_key;
        }
        $this->openProcessQueueJob();
    }

    private function authorizeTool(): void
    {
        abort_unless(auth('app')->user()?->isAllowed('harakat.diacritize', 'app'), 403);
    }

    private function jobs()
    {
        return MlJob::query()->where('customer_id', auth('app')->id())->where('job_kind', 'harakat')
            ->whereHas('tool', fn ($query) => $query->where('code', 'harakat'))
            ->whereHas('toolAction', fn ($query) => $query->where('full_code', 'harakat.diacritize'))
            ->whereNull('input->api_job_id');
    }

    #[Computed] public function currentJob(): ?MlJob { return $this->currentJobId ? $this->jobs()->find($this->currentJobId) : null; }
    #[Computed] public function presentation(): array { return app(MetKurdV2JobStatusPresentation::class)->for($this->currentJob?->status ?? 'idle'); }
    #[Computed] public function characters(): int { return mb_strlen(app(HarakatInput::class)->trimmed($this->text)); }
    #[Computed] public function creditsCost(): int { return app(HarakatSubmissionService::class)->quote(auth('app')->user(), $this->characters); }
    #[Computed] public function resultFile() { return app(HarakatResults::class)->file($this->currentJob); }
    #[Computed] public function outputText(): string { return $this->resultFile ? (string) data_get($this->currentJob->output, 'text') : ''; }
    #[Computed] public function history() { return $this->jobs()->latest()->paginate(5, pageName: 'harakatHistory'); }

    private function forgetJobView(): void
    {
        unset($this->currentJob, $this->presentation, $this->resultFile, $this->outputText, $this->history);
    }

    public function updatedText(): void
    {
        if ($this->currentJob && ! $this->currentJob->isActive()) {
            $this->currentJobId = null;
            $this->submissionKey = (string) Str::uuid();
            $this->forgetJobView();
        }
        unset($this->characters, $this->creditsCost);
        $this->resetValidation();
        $this->submissionError = '';
    }

    public function clearText(): void
    {
        $this->authorizeTool();
        if ($this->currentJob?->isActive()) return;
        $this->text = '';
        $this->currentJobId = null;
        $this->submissionKey = (string) Str::uuid();
        $this->submissionError = '';
        $this->resetValidation();
        $this->forgetJobView();
        unset($this->characters, $this->creditsCost);
    }

    public function diacritize(): void
    {
        $this->authorizeTool();
        if ($this->currentJob?->isActive()) return;
        $this->submissionError = '';
        try {
            $job = app(HarakatSubmissionService::class)->submit(auth('app')->user(), $this->submissionKey, $this->text);
            $this->currentJobId = (string) $job->id;
            $this->dispatch('metkurd:job-submitted');
            $this->forgetJobView();
            $this->resetPage('harakatHistory');
            $this->dispatch('app-header-refresh');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->submissionError = CustomerFacingError::message($exception->getMessage());
        }
    }

    public function pollHarakat(): void
    {
        $this->authorizeTool();
        $before = $this->currentJob?->status;
        if ($this->currentJob?->isActive()) app(HarakatJobSyncService::class)->sync($this->currentJob);
        $this->forgetJobView();
        if ($before !== $this->currentJob?->status) $this->dispatch('app-header-refresh');
    }

    public function openResult(string $id): void
    {
        $this->authorizeTool();
        if ($this->currentJob?->isActive()) return;
        $job = $this->jobs()->findOrFail($id);
        $this->currentJobId = (string) $job->id;
        $this->text = (string) data_get($job->input, 'text');
        // History selection is not a new billable intent.
        $this->submissionKey = (string) $job->submission_key;
        $this->forgetJobView();
        unset($this->characters, $this->creditsCost);
    }
};
?>

<section class="v2-tool-page v2-harakat-page">
    <nav class="v2-breadcrumb"><a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}">{{ __('MetKurd AI') }}</a><span>/</span><a wire:navigate href="{{ route('app.v2.service', ['locale' => app()->getLocale(), 'service' => 'ocr']) }}">{{ __('OCR') }}</a><span>/</span><span>{{ __('Harakat 1.0') }}</span></nav>
    <header class="v2-tool-context">
        <div class="v2-tool-identity d-flex align-items-center gap-3"><img class="v2-service-icon" src="{{ asset('app/services_icons/OCR.png') }}" alt=""><div><span>{{ __('Arabic Diacritization') }}</span><h1>{{ __('Harakat 1.0') }}</h1></div></div>
        @livewire('app::v2.components.shared.account-resources')
    </header>
    @php($active = $this->presentation['is_active'])
    <div class="v2-workspace v2-harakat-workspace">
        <main class="v2-workspace-panel v2-create-panel">
            <div class="v2-panel-heading"><label for="harakat-input">{{ __('Input Text') }}</label><button type="button" class="btn btn-sm btn-outline-info" wire:click="clearText" @disabled($active)>{{ __('Clear') }}</button></div>
            <p class="v2-muted mt-3">{{ __('Add Arabic diacritics to your text. Mixed text is supported.') }}</p>
            <textarea id="harakat-input" dir="auto" class="v2-audio-editor v2-harakat-editor" rows="12" wire:model.live.debounce.400ms="text" @readonly($active) aria-describedby="harakat-count harakat-limit"></textarea>
            <div class="v2-editor-footer">
                <span id="harakat-count" x-data><span x-text="Array.from($wire.text.trim()).length">{{ $this->characters }}</span> {{ __('characters') }}</span>
                <span>{{ __('Estimated cost: :cost credits', ['cost' => number_format($this->creditsCost)]) }}</span>
            </div>
            <p class="v2-muted small mt-2" id="harakat-limit">{{ __('Up to :count characters.', ['count' => app(HarakatInput::class)->limit()]) }}</p>
            @error('text')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
            @if($submissionError)<div class="alert alert-danger" role="alert">{{ $submissionError }}</div>@endif
            <div class="v2-create-actions"><button type="button" class="btn btn-info" wire:click="diacritize" wire:loading.attr="disabled" wire:target="diacritize,text" @disabled($active || $this->characters === 0 || $this->characters > app(HarakatInput::class)->limit())>{{ __('Diacritize') }}</button></div>
        </main>
        <aside class="v2-workspace-panel v2-harakat-result-panel">
            <div class="v2-panel-heading"><span>{{ __('Diacritized Output') }}</span></div>
            @if($this->currentJob)
                <div class="v2-render-item is-{{ $this->presentation['semantic'] }} d-flex align-items-center justify-content-between gap-2 mt-3" role="status" aria-live="polite" wire:key="harakat-status-{{ $currentJobId }}" @if($active) wire:poll.5s="pollHarakat" @endif>
                    <strong>{{ __('Current job') }}</strong> <span class="v2-render-status is-{{ $this->presentation['semantic'] }}">{{ $this->presentation['label'] }}</span>
                </div>
                @if($this->currentJob->failure_stage === 'provider_submission_unknown')<p class="alert alert-warning mt-3">{{ __('Processing status is being reviewed. Please do not resubmit.') }}</p>@endif
                @if($this->currentJob->status === 'failed')<p class="alert alert-danger mt-3">{{ CustomerFacingError::message(data_get($this->currentJob->error, 'message')) }}</p>@endif
            @endif
            @if($this->resultFile)
                <div wire:key="harakat-result-{{ $currentJobId }}" x-data="{copyState: ''}">
                    <textarea readonly dir="auto" x-ref="output" class="v2-audio-editor v2-harakat-editor" rows="12" aria-label="{{ __('Diacritized Output') }}">{{ $this->outputText }}</textarea>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-info" @click="try { await navigator.clipboard.writeText($refs.output.value); copyState = 'ok'; } catch { copyState = 'error'; }">{{ __('Copy') }}</button>
                        <a class="btn btn-sm btn-outline-info" href="{{ route('app.v2.harakat.txt', ['locale' => app()->getLocale(), 'jobId' => $currentJobId]) }}">{{ __('Download TXT') }}</a>
                        <span x-cloak x-show="copyState === 'ok'" role="status">{{ __('Copied') }}</span><span x-cloak x-show="copyState === 'error'" role="alert">{{ __('Copy failed. Select the text and copy it manually.') }}</span>
                    </div>
                </div>
            @else
                <p class="v2-muted py-4">{{ __('Your diacritized text will appear here.') }}</p>
            @endif
            <div class="v2-harakat-history mt-4 pt-3 border-top">
                <h2 class="h6">{{ __('History') }}</h2>
                @forelse($this->history as $job)
                    @php($status = app(MetKurdV2JobStatusPresentation::class)->for($job->status))
                    <article class="v2-render-item is-{{ $status['semantic'] }} mb-2" wire:key="harakat-history-{{ $job->id }}">
                        <div class="d-flex align-items-center justify-content-between gap-2"><time datetime="{{ $job->created_at->toIso8601String() }}">{{ $job->created_at->format('Y-m-d H:i') }}</time><span class="v2-render-status is-{{ $status['semantic'] }}">{{ $status['label'] }}</span></div>
                        <p class="mb-1" dir="auto">{{ Str::limit((string) data_get($job->input, 'text'), 75) }}</p>
                        <div class="d-flex align-items-center justify-content-between gap-2"><span>{{ __(':count input characters', ['count' => data_get($job->input, 'characters', 0)]) }}</span><button type="button" class="btn btn-sm btn-outline-info" wire:click="openResult('{{ $job->id }}')" @disabled($active)>{{ __('View result') }}</button></div>
                    </article>
                @empty<p class="v2-muted">{{ __('No diacritization history yet.') }}</p>@endforelse
                {{ $this->history->links() }}
            </div>
        </aside>
    </div>
</section>

<style global>
.metkurd-v2 .v2-workspace.v2-harakat-workspace{grid-template-columns:repeat(2,minmax(0,1fr));border-color:rgba(var(--v2-accent-rgb),.4);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.14),rgba(3,18,13,.3))}
.metkurd-v2 .v2-harakat-workspace>.v2-workspace-panel{width:100%;min-width:0}
.metkurd-v2 .v2-harakat-workspace .v2-panel-heading{color:var(--v2-accent-text)}
.metkurd-v2 .v2-harakat-workspace .v2-harakat-editor{width:100%;min-height:20rem;margin-top:1rem;padding:1rem;border:1px solid rgba(var(--v2-accent-rgb),.72);border-radius:.9rem;background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.12),rgba(2,6,23,.45));color:#f8fafc;caret-color:var(--v2-accent-text);line-height:2;resize:vertical;text-align:start}
.metkurd-v2 .v2-harakat-workspace .v2-harakat-editor:focus{border-color:var(--v2-accent-text);box-shadow:0 0 0 3px rgba(var(--v2-accent-rgb),.16)}
.metkurd-v2 .v2-harakat-workspace .v2-render-status{flex-shrink:0;font-size:.72rem}
.metkurd-v2 .v2-harakat-history{font-size:.8rem}
@media(max-width:767.98px){.metkurd-v2 .v2-workspace.v2-harakat-workspace{grid-template-columns:1fr}.metkurd-v2 .v2-harakat-workspace .v2-create-panel{order:0}.metkurd-v2 .v2-harakat-workspace .v2-harakat-editor{min-height:15rem}}
</style>
