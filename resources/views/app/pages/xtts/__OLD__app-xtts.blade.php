<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\MlJob;
use App\Models\Voice;
use App\Models\CustomerUsage;

use App\Services\Providers\RunPodProvider;
use App\Services\Billing\CreditService;
use App\Services\Storage\CustomerOutputStorage;

new
#[Layout('app::layouts.app')]
#[Title('XTTS | METKURD')]
class extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    protected string $toolCode = 'tts';
    protected string $actionCode = 'standard';
    protected string $fullActionCode = 'tts.standard';

    #[Url(as: 'page', except: 1)]
    public int $page = 1;

    // =========================================================
    // UI State
    // =========================================================
    public ?string $currentJobId = null;
    public ?string $providerJobId = null;
    public ?string $currentStatus = null;
    public bool $jobFinished = false;
    public bool $showJobStatus = false;
    public int $currentProgress = 0;


    // =========================================================
    // Inputs
    // =========================================================
    public ?string $text = '';

    public string $speaker_id = 'liza';
    public string $language = 'ar';

    public bool $split = true;
    public int $max_words = 25;
    public int $fade_ms = 80;

    public float $temperature = 0.65;
    public int $top_k = 50;
    public float $top_p = 0.8;
    public float $repetition_penalty = 2.0;
    public float $length_penalty = 1.0;
    public float $speed = 1.0;

    // =========================================================
    // Credits UI
    // =========================================================
    public int $maxPerSubmit = 400;
    public int $walletBalance = 0;
    public int $creditsCost = 0;

    // =========================================================
    // Internal
    // =========================================================
    public int $completedNoAudioTicks = 0;
    public int $rendersRefreshKey = 0;

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('xtts-renders-refresh')]
    public function refreshUi(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->rendersRefreshKey++;
    }

    public function mount(): void
    {
        $this->syncWallet();

        if (!array_key_exists($this->speaker_id, $this->availableSpeakers)) {
            $this->speaker_id = array_key_first($this->availableSpeakers) ?? '';
        }

        $this->syncCostPreview();
        // $this->loadLatestFinishedRender();
    }

    public function updatedText(): void
    {
        $this->syncCostPreview();
    }

    public function updatedSpeakerId(): void
    {
        $this->syncCostPreview();
    }

    public function updatedLanguage(): void
    {
        $this->syncCostPreview();
    }

    #[Computed]
    public function currentChars(): int
    {
        return mb_strlen(trim((string) $this->text));
    }

    #[Computed]
    public function currentWords(): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $this->text));
        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? count($parts) : 0;
    }

    #[Computed]
    public function availableSpeakers(): array
    {
        $customer = auth('app')->user();
        if (!$customer) {
            return [];
        }

        $customer->loadMissing([
            'servicePlan',
            'activeServiceSubscription.servicePlan',
        ]);

        $planId =
            $customer->service_plan_id
            ?: $customer->servicePlan?->id
            ?: $customer->activeServiceSubscription?->service_plan_id
            ?: $customer->activeServiceSubscription?->servicePlan?->id;

        if (!$planId) {
            return [];
        }

        return Voice::query()
            ->select('voices.code', 'voices.name')
            ->join('plan_voice_access as pva', 'pva.voice_id', '=', 'voices.id')
            ->where('voices.is_active', true)
            ->where('pva.is_active', true)
            ->where('pva.service_plan_id', $planId)
            ->orderBy('voices.sort_order')
            ->pluck('voices.name', 'voices.code')
            ->toArray();
    }

    #[Computed]
    public function sliders(): array
    {
        return [
            ['key'=>'temperature','label'=>'Temperature','min'=>0,'max'=>2.5,'step'=>0.01,'val'=>$this->temperature],
            ['key'=>'top_p','label'=>'Top P','min'=>0,'max'=>1,'step'=>0.01,'val'=>$this->top_p],
            ['key'=>'top_k','label'=>'Top K','min'=>0,'max'=>100,'step'=>1,'val'=>$this->top_k],
            ['key'=>'repetition_penalty','label'=>'Repetition Penalty','min'=>1,'max'=>8,'step'=>0.01,'val'=>$this->repetition_penalty],
            ['key'=>'length_penalty','label'=>'Length Penalty','min'=>-5,'max'=>6,'step'=>0.01,'val'=>$this->length_penalty],
            ['key'=>'speed','label'=>'Speed','min'=>0.5,'max'=>2,'step'=>0.01,'val'=>$this->speed],
        ];
    }

    #[Computed]
    public function canGenerate(): bool
    {
        return $this->generateBlockedReason === null;
    }

    #[Computed]
    public function generateBlockedReason(): ?string
    {
        if ($this->isGenerating()) {
            return 'A generation is already in progress.';
        }

        if ($this->currentChars <= 0) {
            return 'Please enter some text.';
        }

        if ($this->currentChars > $this->maxPerSubmit) {
            return 'Text exceeds the max characters per submit.';
        }

        if (empty($this->availableSpeakers)) {
            return 'No voices are available for your current plan.';
        }

        if (!array_key_exists($this->speaker_id, $this->availableSpeakers)) {
            return 'Selected voice is not available for your current plan.';
        }

        if ($this->creditsCost <= 0) {
            return 'Pricing could not be calculated.';
        }

        if ($this->walletBalance < $this->creditsCost) {
            return 'Not enough credits.';
        }

        return null;
    }

    #[Computed]
    public function renders()
    {
        $this->rendersRefreshKey;

        $customerId = auth('app')->id();
        $locale = app()->getLocale();

        $toolId = Tool::where('code', $this->toolCode)->value('id');

        $paginator = MlJob::query()
            ->where('customer_id', $customerId)
            ->when($toolId, fn ($q) => $q->where('tool_id', $toolId))
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->paginate(10);

        $paginator->setCollection(
            $paginator->getCollection()->values()->map(function ($j, $index) use ($locale) {
                $jobId = (string) $j->id;
                $path = (string) data_get($j->output, 'path', '');
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mime = $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav';

                $fullText = trim((string) data_get($j->input, 'text', ''));
                $snippet = mb_strlen($fullText) > 240
                    ? mb_substr($fullText, 0, 160) . '...'
                    : $fullText;

                return [
                    'id' => $jobId,
                    'speaker' => data_get($j->input, 'speaker_id', '—'),
                    'model' => 'XTTS (RunPod)',
                    'created_at' => optional($j->finished_at ?? $j->created_at)->format('Y-m-d H:i'),
                    'full_url' => route('app.renders.xtts.stream', [
                        'locale' => $locale,
                        'jobId' => $jobId,
                    ]) . '?proxy=1',
                    'mime' => $mime,
                    'bytes' => (int) data_get($j->output, 'bytes', 0),
                    'download_url' => route('app.renders.xtts.download', [
                        'locale' => $locale,
                        'jobId' => $jobId,
                    ]),
                    'text' => $fullText,
                    'text_snippet' => $snippet,
                    'words' => $this->wordsCount($fullText),
                    'is_latest' => $index === 0,
                ];
            })
        );

        return $paginator;
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
    
    protected function syncWallet(): void
    {
        $c = auth('app')->user();
        $wallet = $c?->wallet()->first();

        $subscription = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addon = (int) ($wallet?->addon_balance_credits ?? 0);

        $this->walletBalance = $subscription + $addon;

        $this->maxPerSubmit = 400;

        if (method_exists($c, 'entitlementLimitFor')) {
            $this->maxPerSubmit = (int) ($c->entitlementLimitFor($this->fullActionCode, 'max_chars_per_submit') ?? 400);
        }
    }

    protected function syncCostPreview(): void
    {
        $c = auth('app')->user();
        $chars = $this->currentChars;

        if (!$c || $chars <= 0) {
            $this->creditsCost = 0;
            return;
        }

        if (method_exists($c, 'priceCreditsFor')) {
            $this->creditsCost = (int) $c->priceCreditsFor($this->fullActionCode, [
                'chars' => $chars,
                'metric_code' => 'character',
                'language' => $this->language,
                'speaker_id' => $this->speaker_id,
            ]);
            return;
        }

        $this->creditsCost = (int) ceil($chars * 1.0);
    }

    protected function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxPerSubmit],
            'speaker_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!array_key_exists((string) $value, $this->availableSpeakers)) {
                    $fail('The selected speaker is not available for your plan.');
                }
            }],
            'language' => 'required|string|min:1|max:8',
            'split' => 'boolean',
            'max_words' => 'required|integer|min:5|max:80',
            'fade_ms' => 'required|integer|min:0|max:1000',
            'temperature' => 'required|numeric|min:0|max:2.5',
            'top_k' => 'required|integer|min:0|max:100',
            'top_p' => 'required|numeric|min:0|max:1',
            'repetition_penalty' => 'required|numeric|min:1|max:8',
            'length_penalty' => 'required|numeric|min:-5|max:6',
            'speed' => 'required|numeric|min:0.5|max:2.0',
        ];
    }

    protected function isGenerating(): bool
    {
        return (bool) $this->currentJobId
            && !$this->jobFinished
            && in_array($this->currentStatus, ['queued', 'running', 'saving'], true);
    }

    public function hideJobStatus(): void
    {
        if ($this->jobFinished) {
            $this->showJobStatus = false;
        }
    }

    protected function findToolAndAction(): array
    {
        $tool = Tool::where('code', $this->toolCode)->first();
        $action = ToolAction::where('full_code', $this->fullActionCode)->first();

        if (!$tool || !$action) {
            throw new \RuntimeException("Tool or ToolAction missing ({$this->toolCode} / {$this->fullActionCode}).");
        }

        return [$tool, $action];
    }

    public function postXtts(RunPodProvider $runpod, CreditService $credits): void
    {
        $this->showJobStatus = true;
        $c = auth('app')->user();
        $actionCode = $this->fullActionCode;

        if (method_exists($c, 'isAllowed') && !$c->isAllowed($actionCode)) {
            $this->dispatch('alert', type: 'error', message: __('Your plan does not allow XTTS.'));
            return;
        }

        if ($this->isGenerating()) {
            $this->dispatch('alert', type: 'warning', message: 'A generation is already in progress.');
            return;
        }

        $this->validate();

        $text = trim((string) $this->text);
        $chars = $this->currentChars;

        $cost = method_exists($c, 'priceCreditsFor')
            ? (int) $c->priceCreditsFor($actionCode, [
                'chars' => $chars,
                'metric_code' => 'character',
                'language' => $this->language,
                'speaker_id' => $this->speaker_id,
            ])
            : (int) ceil($chars * 1.0);

        if ($cost <= 0) {
            $this->dispatch('alert', type: 'error', message: 'Pricing is not configured.');
            return;
        }

        try {
            $credits->charge((int) $c->id, $cost, 'tts_charge', [
                'related_type' => 'ml_job',
                'related_id'   => null,
                'tool_action'  => $actionCode,
                'chars'        => $chars,
            ]);
        } catch (\Throwable $e) {
            Log::info('XTTS postXtts fired222' . $e);
            $this->syncWallet();
            $this->dispatch('alert', type: 'error', message: 'Not enough credits.');
            return;
        }

        [$tool, $action] = $this->findToolAndAction();
        $jobId = (string) Str::uuid();

        MlJob::create([
            'id'             => $jobId,
            'customer_id'    => $c->id,
            'tool_id'        => $tool->id,
            'tool_action_id' => $action->id,
            'status'         => 'queued',
            'provider'       => 'runpod',
            'input' => [
                'text' => $text,
                'speaker_id' => $this->speaker_id,
                'language' => $this->language,
                'split' => (bool) $this->split,
                'max_words' => (int) $this->max_words,
                'fade_ms' => (int) $this->fade_ms,
                'temperature' => (float) $this->temperature,
                'top_k' => (int) $this->top_k,
                'top_p' => (float) $this->top_p,
                'repetition_penalty' => (float) $this->repetition_penalty,
                'length_penalty' => (float) $this->length_penalty,
                'speed' => (float) $this->speed,
            ],
            'credits_charged' => $cost,
            'started_at' => now(),
        ]);

        $this->currentJobId = $jobId;
        $this->providerJobId = null;
        $this->currentStatus = 'queued';
        $this->jobFinished = false;
        $this->currentProgress = 10;
        $this->completedNoAudioTicks = 0;

        try {
            $endpointId = data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.xtts');
            if (!$endpointId) {
                throw new \RuntimeException('XTTS endpoint id is missing.');
            }

            $timeout = (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60));

            $resp = $runpod->run($endpointId, [
                'text' => $text,
                'language' => $this->language,
                'speaker' => $this->speaker_id,
                'enable_text_splitting' => (bool) $this->split,
                'max_words' => (int) $this->max_words,
                'temperature' => (float) $this->temperature,
                'length_penalty' => (float) $this->length_penalty,
                'repetition_penalty' => (float) $this->repetition_penalty,
                'top_k' => (int) $this->top_k,
                'top_p' => (float) $this->top_p,
                'speed' => (float) $this->speed,
            ], $timeout);

            $rpId = (string) data_get($resp, 'id', '');
            if ($rpId === '') {
                throw new \RuntimeException('RunPod did not return job id.');
            }

            MlJob::where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $rpId,
            ]);

            $this->providerJobId = $rpId;
            $this->currentStatus = 'running';
            $this->currentProgress = 20;

            $this->syncWallet();

            $this->dispatch('customerPlanUpdated');
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('xtts-renders-refresh');

            $this->dispatch('alert', type: 'success', message: 'RunPod job started.');
        } catch (\Throwable $e) {
            Log::error('XTTS ERROR fired', $e);
            Log::error('XTTS postXtts fired', [
                'customer_id' => auth('app')->id(),
                'speaker_id' => $this->speaker_id,
                'available_speakers' => $this->availableSpeakers,
                'chars' => $this->currentChars,
                'creditsCost' => $this->creditsCost,
                'walletBalance' => $this->walletBalance,
                'canGenerate' => $this->canGenerate,
            ]);
            $credits->refund((int) $c->id, $cost, 'tts_refund', [
                'related_type' => 'ml_job',
                'related_id'   => $jobId,
                'tool_action'  => $actionCode,
                'reason'       => 'provider_start_failed',
            ]);

            MlJob::where('id', $jobId)->update([
                'status' => 'failed',
                'error' => ['message' => $e->getMessage()],
                'finished_at' => now(),
            ]);

            $this->currentStatus = 'failed';
            $this->jobFinished = true;
            $this->currentProgress = 100;
            $this->syncWallet();

            $this->dispatch('alert', type: 'error', message: 'RunPod failed: ' . $e->getMessage());
        }
    }

    public function pollJob(RunPodProvider $runpod, CustomerOutputStorage $storage): void
    {
        $this->showJobStatus = true;
        if (!$this->currentJobId || $this->jobFinished) {
            return;
        }

        $job = MlJob::find($this->currentJobId);
        if (!$job) {
            return;
        }

        $tool = Tool::find($job->tool_id);
        $endpointId = data_get($tool?->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.xtts');
        if (!$endpointId) {
            return;
        }

        $rpId = $this->providerJobId ?: (string) $job->provider_job_id;
        if (!$rpId) {
            return;
        }

        try {
            $st = $runpod->status($endpointId, $rpId);

            $rawStatus = strtoupper((string) data_get($st, 'status', ''));
            $out = (array) data_get($st, 'output', []);

            $wavB64 =
                (string) (data_get($out, 'wav_b64', '')
                ?: data_get($out, 'wav_base64', '')
                ?: data_get($out, 'audio_b64', '')
                ?: data_get($out, 'audio_base64', '')
                ?: data_get($st, 'output.wav_b64', '')
                ?: data_get($st, 'output.audio_b64', ''));

            $outProgress = (int) (data_get($out, 'progress', 0) ?: data_get($st, 'output.progress', 0));

            $mapped = match ($rawStatus) {
                'IN_QUEUE', 'QUEUED' => 'queued',
                'IN_PROGRESS', 'RUNNING' => 'running',
                'COMPLETED' => 'saving',
                'FAILED', 'CANCELLED', 'TIMED_OUT' => 'failed',
                default => 'running',
            };

            $this->currentStatus = $mapped;

            if ($mapped === 'queued') {
                $this->currentProgress = max($this->currentProgress, 10);
            }

            if ($mapped === 'running') {
                $p = ($outProgress > 0 && $outProgress < 90) ? $outProgress : 40;
                $this->currentProgress = max($this->currentProgress, $p);
                $this->completedNoAudioTicks = 0;
            }

            if ($mapped === 'saving') {
                $this->currentProgress = max($this->currentProgress, 85);
            }

            if ($rawStatus === 'COMPLETED' && $wavB64 === '') {
                $this->completedNoAudioTicks++;

                if ($this->completedNoAudioTicks >= 8) {
                    MlJob::where('id', $this->currentJobId)->update([
                        'status' => 'failed',
                        'error' => ['message' => 'Completed but audio base64 missing.'],
                        'finished_at' => now(),
                    ]);

                    $this->currentStatus = 'failed';
                    $this->jobFinished = true;
                    $this->currentProgress = 100;

                    $this->dispatch('alert', type: 'error', message: 'Completed but output audio missing.');
                    return;
                }
            }

            if ($mapped === 'failed') {
                $err = (string) (data_get($st, 'error', '') ?: data_get($out, 'error', '') ?: 'RunPod failed');

                MlJob::where('id', $this->currentJobId)->update([
                    'status' => 'failed',
                    'error'  => ['message' => $err],
                    'finished_at' => now(),
                ]);

                $this->currentStatus = 'failed';
                $this->jobFinished = true;
                $this->currentProgress = 100;

                $this->dispatch('alert', type: 'error', message: $err);
                return;
            }

            MlJob::where('id', $this->currentJobId)->update([
                'status' => $mapped,
            ]);

            if ($wavB64 !== '') {
                $this->currentStatus = 'saving';
                $this->currentProgress = max($this->currentProgress, 90);

                $c = auth('app')->user();

                $folder = \App\Support\CustomerFolder::make(
                    (int) $c->id,
                    $c->profile?->first_name ?? $c->first_name ?? null,
                    $c->profile?->last_name ?? $c->last_name ?? null,
                    $c->username ?? null
                );

                $fileKey = "renders/{$folder}/tts/{$this->currentJobId}/out.wav";

                $saved = $storage->saveWavB64ToS3((int) $c->id, $fileKey, $wavB64, [
                    'job_id' => $this->currentJobId,
                    'tool'   => 'tts',
                ]);

                MlJob::where('id', $this->currentJobId)->update([
                    'status' => 'done',
                    'output' => [
                        'disk' => $saved['disk'],
                        'path' => $saved['path'],
                        'bytes' => $saved['bytes'],
                        'mime' => 'audio/wav',
                    ],
                    'storage_out_bytes' => (int) $saved['bytes'],
                    'finished_at' => now(),
                    'error' => null,
                ]);

                $this->addStorageUsage((int) $c->id, (int) $saved['bytes']);

                $this->currentStatus = 'done';
                $this->jobFinished = true;
                $this->currentProgress = 100;

                $this->syncWallet();

                $this->dispatch('customerPlanUpdated');
                $this->dispatch('customerStorageUpdated');
                $this->dispatch('xtts-renders-refresh');
                $this->dispatch('xtts-job-completed');

                $this->dispatch('alert', type: 'success', message: 'Done');
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('RUNPOD_TTS_STATUS_FAIL', [
                'job_id' => $this->currentJobId,
                'provider_job_id' => $rpId,
                'err' => $e->getMessage(),
            ]);

            MlJob::where('id', $this->currentJobId)->update([
                'status' => 'failed',
                'error'  => ['message' => 'Polling failed: ' . $e->getMessage()],
                'finished_at' => now(),
            ]);

            $this->currentStatus = 'failed';
            $this->jobFinished = true;
            $this->currentProgress = 100;

            $this->dispatch('alert', type: 'error', message: 'Polling failed: ' . $e->getMessage());
        }
    }

    protected function addStorageUsage(int $customerId, int $bytes): void
    {
        if ($bytes <= 0) return;

        DB::transaction(function () use ($customerId, $bytes) {
            $usage = CustomerUsage::query()->lockForUpdate()->firstOrCreate(
                ['customer_id' => $customerId],
                ['storage_used_bytes' => 0, 'jobs_total' => 0, 'jobs_succeeded' => 0, 'jobs_failed' => 0]
            );

            $usage->storage_used_bytes = (int) $usage->storage_used_bytes + $bytes;
            $usage->jobs_total = (int) $usage->jobs_total + 1;
            $usage->jobs_succeeded = (int) $usage->jobs_succeeded + 1;
            $usage->save();
        }, 3);
    }

    protected function subtractStorageUsage(int $customerId, int $bytes): void
    {
        if ($bytes <= 0) return;

        DB::transaction(function () use ($customerId, $bytes) {
            $usage = CustomerUsage::query()->lockForUpdate()->firstOrCreate(
                ['customer_id' => $customerId],
                ['storage_used_bytes' => 0, 'jobs_total' => 0, 'jobs_succeeded' => 0, 'jobs_failed' => 0]
            );

            $usage->storage_used_bytes = max(0, (int) $usage->storage_used_bytes - $bytes);
            $usage->save();
        }, 3);
    }

    public function deleteRender(string $jobId): void
    {
        $customerId = auth('app')->id();
        $toolId = Tool::where('code', $this->toolCode)->value('id');

        $job = MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', $customerId)
            ->when($toolId, fn ($q) => $q->where('tool_id', $toolId))
            ->where('status', 'done')
            ->first();

        if (!$job) {
            $this->dispatch('alert', type: 'error', message: 'Render not found.');
            return;
        }

        $disk = (string) data_get($job->output, 'disk', 's3');
        $key  = (string) data_get($job->output, 'path', '');
        $bytes = (int) data_get($job->output, 'bytes', 0);

        if ($key === '') {
            $this->dispatch('alert', type: 'error', message: 'Missing file key.');
            return;
        }

        try {
            DB::transaction(function () use ($jobId) {
                $fresh = MlJob::query()->lockForUpdate()->find($jobId);
                if (!$fresh || $fresh->status !== 'done') {
                    return;
                }

                $fresh->status = 'deleting';
                $fresh->save();
            }, 3);

            if (Storage::disk($disk)->exists($key)) {
                Storage::disk($disk)->delete($key);
            }

            DB::transaction(function () use ($jobId, $customerId, $bytes) {
                $fresh = MlJob::query()->lockForUpdate()->find($jobId);
                if (!$fresh) {
                    return;
                }

                $fresh->status = 'deleted';
                $fresh->save();

                if ($bytes > 0) {
                    $this->subtractStorageUsage((int) $customerId, (int) $bytes);
                }
            }, 3);

            $this->resetPage();
            $this->rendersRefreshKey++;

            $this->dispatch('customerStorageUpdated');
            $this->dispatch('xtts-renders-refresh');
            $this->dispatch('alert', type: 'success', message: 'Deleted.');
        } catch (\Throwable $e) {
            MlJob::where('id', $jobId)->where('status', 'deleting')->update(['status' => 'done']);
            $this->dispatch('alert', type: 'error', message: 'Delete failed: ' . $e->getMessage());
        }
    }

    public function clearText(): void
    {
        $this->text = '';
        $this->syncCostPreview();
    }

    public function resetToDefaults(): void
    {
        $this->speaker_id = array_key_first($this->availableSpeakers) ?? 'liza';
        $this->language = 'ar';
        $this->split = true;
        $this->max_words = 25;
        $this->fade_ms = 80;
        $this->temperature = 0.65;
        $this->top_k = 50;
        $this->top_p = 0.8;
        $this->repetition_penalty = 2.0;
        $this->length_penalty = 1.0;
        $this->speed = 1.0;

        $this->syncCostPreview();
    }

    // protected function loadLatestFinishedRender(): void
    // {
    //     $customerId = auth('app')->id();
    //     $toolId = Tool::where('code', $this->toolCode)->value('id');

    //     $latest = MlJob::query()
    //         ->where('customer_id', $customerId)
    //         ->when($toolId, fn ($q) => $q->where('tool_id', $toolId))
    //         ->where('status', 'done')
    //         ->orderByDesc('finished_at')
    //         ->first();

    //     if (!$latest) {
    //         return;
    //     }

    //     $this->currentJobId = (string) $latest->id;
    //     $this->audioUrl = route('app.renders.xtts.stream', [
    //         'locale' => app()->getLocale(),
    //         'jobId'  => $latest->id,
    //     ]);

    //     $this->currentStatus = 'done';
    //     $this->jobFinished = true;
    //     $this->currentProgress = 100;
    // }

    public function render()
    {
        return view('app.pages.xtts.⚡app-xtts');
    }
};
?>

<div
    x-data="{
        text: $wire.entangle('text').live,
        chars: 0,
        words: 0,
        init() {
            this.$watch('text', value => {
                const v = (value || '').trim();
                this.chars = v.length;
                this.words = v ? v.split(/\s+/u).filter(Boolean).length : 0;
            });

            const v = (this.text || '').trim();
            this.chars = v.length;
            this.words = v ? v.split(/\s+/u).filter(Boolean).length : 0;
        }
    }"
>
    @if($currentJobId && !$jobFinished)
        <div wire:poll.keep-alive.2000ms="pollJob"></div>
    @endif

    @php
        $status = strtoupper($currentStatus ?? 'IDLE');

        $badge = match($currentStatus) {
            'queued' => 'warning',
            'running' => 'info',
            'saving' => 'primary',
            'done' => 'success',
            'failed' => 'danger',
            default => 'secondary',
        };

        $glassClass = match($currentStatus) {
            'queued' => 'glass-load--warning',
            'running' => 'glass-load--info',
            'saving' => 'glass-load--info',
            'done' => 'glass-load--success',
            'failed' => 'glass-load--danger',
            default => 'glass-load--secondary',
        };

        $progress = (int) ($currentProgress ?? 0);
    @endphp

    @if($currentJobId && $showJobStatus)
        <div
            class="card glass-load {{ $glassClass }} mb-3"
            wire:key="xtts-job-status-{{ $currentJobId }}"
            @if($jobFinished) wire:poll.3s="hideJobStatus" @endif
        >
            <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between flex-wrap">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-{{ $badge }} text-uppercase">{{ $status }}</span>

                    @if(!$jobFinished)
                        <small class="tts-status-muted">
                            <span class="spinner-border spinner-border-sm me-1"></span>
                            Working...
                        </small>
                    @else
                        <small class="tts-status-muted">Finished</small>
                    @endif
                </div>

                <div class="small text-muted">
                    Job: <span class="fw-semibold">{{ Str::limit($currentJobId, 12, '...') }}</span>
                </div>
            </div>

            <div class="px-3 pb-3">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Progress</span>
                    <span><strong>{{ $progress }}%</strong></span>
                </div>

                <div class="progress" style="height:10px; border-radius:999px; overflow:hidden;">
                    <div class="progress-bar bg-{{ $badge }}"
                        style="width: {{ $progress }}%;"
                        aria-valuenow="{{ $progress }}"
                        aria-valuemin="0"
                        aria-valuemax="100"></div>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <strong>XTTS (RunPod)</strong>
                                <div class="text-muted small">Dynamic voice access based on customer plan</div>
                            </div>

                            <div class="d-flex gap-2 flex-wrap text-end small">
                                <div class="mini-stat">
                                    <div class="text-muted">Wallet</div>
                                    <div class="fw-semibold">{{ number_format($walletBalance) }}</div>
                                </div>
                                <div class="mini-stat">
                                    <div class="text-muted">Cost</div>
                                    <div class="fw-semibold">{{ number_format($creditsCost) }}</div>
                                </div>
                                <div class="mini-stat">
                                    <div class="text-muted">Max/Submit</div>
                                    <div class="fw-semibold">{{ number_format($maxPerSubmit) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="mt-3">
                                <label class="form-label">Text</label>

                                <textarea
                                    class="form-control"
                                    rows="6"
                                    wire:model.live.debounce.250ms="text"
                                    placeholder="Write a text"
                                    dir="rtl"
                                ></textarea>

                                <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                                    <div class="d-flex gap-3 small">
                                        <span class="text-muted">Chars: <strong x-text="chars">{{ $this->currentChars }}</strong></span>
                                        <span class="text-muted">Words: <strong x-text="words">{{ $this->currentWords }}</strong></span>
                                        <span class="text-muted">Credits: <strong>{{ $creditsCost }}</strong></span>
                                    </div>

                                    <button class="btn btn-sm btn-link p-0" wire:click="clearText" type="button">
                                        Clear
                                    </button>
                                </div>

                                @error('text')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <hr>

                            <div class="row g-3 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label">Speaker</label>
                                    <select class="form-select" wire:model.live="speaker_id">
                                        @forelse($this->availableSpeakers as $k => $v)
                                            <option value="{{ $k }}">{{ $v }}</option>
                                        @empty
                                            <option value="">No voices available</option>
                                        @endforelse
                                    </select>

                                    @error('speaker_id')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Language</label>
                                    <input type="text"
                                           class="form-control"
                                           wire:model.live.debounce.300ms="language"
                                           maxlength="8"
                                           placeholder="ar">
                                    @error('language')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Max Words</label>
                                    <input type="number" class="form-control" wire:model.live="max_words" min="5" max="80">
                                    @error('max_words')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Fade (ms)</label>
                                    <input type="number" class="form-control" wire:model.live="fade_ms" min="0" max="1000">
                                    @error('fade_ms')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-12">
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" id="splitSwitchXTTS" wire:model.live="split">
                                        <label class="form-check-label" for="splitSwitchXTTS">Split long text automatically</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">
                                @foreach($this->sliders as $s)
                                    <div class="col-md-6" wire:key="slider-{{ $s['key'] }}">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label class="form-label mb-1">{{ $s['label'] }}</label>
                                            <span class="badge text-bg-light tts-badge"
                                                  id="xtts-badge-{{ $s['key'] }}"
                                                  data-key="{{ $s['key'] }}">
                                                {{ $s['val'] }}
                                            </span>
                                        </div>

                                        <div class="d-flex justify-content-between small text-muted" style="margin-top:-2px;">
                                            <span>{{ $s['min'] }}</span>
                                            <span>{{ $s['max'] }}</span>
                                        </div>

                                        <div class="position-relative">
                                            <input type="range"
                                                   class="form-range tts-range"
                                                   min="{{ $s['min'] }}"
                                                   max="{{ $s['max'] }}"
                                                   step="{{ $s['step'] }}"
                                                   value="{{ $s['val'] }}"
                                                   wire:ignore
                                                   data-xtts-range="{{ $s['key'] }}"
                                                   data-min="{{ $s['min'] }}"
                                                   data-max="{{ $s['max'] }}"
                                                   data-step="{{ $s['step'] }}" />
                                            <div class="tts-bubble" id="xtts-bubble-{{ $s['key'] }}" wire:ignore></div>
                                        </div>

                                        @error($s['key'])
                                            <div class="text-danger small">{{ $message }}</div>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>

                            <div class="d-flex gap-2 mt-4 flex-wrap">
                                <button
                                    class="btn {{ $this->canGenerate ? 'btn-primary' : 'btn-danger' }}"
                                    wire:click="postXtts"
                                    wire:loading.attr="disabled"
                                    wire:target="postXtts"
                                    @disabled(!$this->canGenerate)
                                    type="button"
                                    id="btn-xtts-generate"
                                >
                                    <span wire:loading.remove wire:target="postXtts">
                                        {{ $this->canGenerate ? 'Generate' : ($this->generateBlockedReason ?? 'Generate') }}
                                    </span>
                                    <span wire:loading wire:target="postXtts">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                        Starting...
                                    </span>
                                </button>

                                <button class="btn btn-outline-secondary" wire:click="resetToDefaults" type="button">
                                    Reset
                                </button>
                                {{-- <div class="mt-2 small">
                                    <div>canGenerate: <strong>{{ $this->canGenerate ? 'true' : 'false' }}</strong></div>
                                    <div>blockedReason: <strong>{{ $this->generateBlockedReason ?? 'none' }}</strong></div>
                                    <div>chars: <strong>{{ $this->currentChars }}</strong></div>
                                    <div>maxPerSubmit: <strong>{{ $this->maxPerSubmit }}</strong></div>
                                    <div>creditsCost: <strong>{{ $this->creditsCost }}</strong></div>
                                    <div>walletBalance: <strong>{{ $this->walletBalance }}</strong></div>
                                    <div>speaker_id: <strong>{{ $this->speaker_id }}</strong></div>
                                    <div>availableSpeakers: <strong>{{ count($this->availableSpeakers) }}</strong></div>
                                </div> --}}
                                @if($walletBalance < $creditsCost && $creditsCost > 0)
                                    <span class="small text-danger align-self-center">
                                        Not enough credits for this generation.
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <strong>Recent Renders</strong>
                            <button class="btn btn-sm btn-outline-secondary" wire:click="$refresh" type="button">
                                Refresh
                            </button>
                        </div>

                        <div class="card-body">
                            @if($this->renders->count() === 0)
                                <div class="text-muted">No renders yet.</div>
                            @else
                                @foreach($this->renders as $r)
                                    <div class="border rounded p-2 mb-2 render-card" wire:key="xtts-render-{{ $r['id'] }}">
                                        <div class="d-flex justify-content-between gap-2">
                                            <div>
                                                <div class="small text-muted">
                                                    {{ $r['created_at'] }} • {{ $r['model'] }} • {{ $r['speaker'] }}
                                                </div>
                                                <div class="small text-muted">
                                                    Words: {{ $r['words'] }} • Bytes: {{ number_format($r['bytes']) }}
                                                </div>
                                            </div>

                                            <div class="text-end">
                                                <button class="btn btn-sm btn-outline-danger"
                                                        wire:click="deleteRender('{{ $r['id'] }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="deleteRender('{{ $r['id'] }}')"
                                                        type="button">
                                                    Delete
                                                </button>
                                            </div>
                                        </div>

                                        <div class="mt-2 small">{{ $r['text_snippet'] }}</div>

                                        <div class="mt-2">
                                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                                <span class="small text-muted" id="xtts-time-{{ $r['id'] }}">--:-- / --:--</span>

                                                <div class="btn-group btn-group-sm">
                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-xtts-preview"
                                                            data-job="{{ $r['id'] }}"
                                                            data-url="{{ $r['full_url'] }}"
                                                            data-latest="{{ $r['is_latest'] ? '1' : '0' }}"
                                                            data-preload-rank="{{ $loop->index }}">
                                                        <i class="fa fa-play me-1"></i> Play/Pause
                                                    </button>

                                                    <button type="button"
                                                            class="btn btn-outline-secondary btn-xtts-stop"
                                                            data-job="{{ $r['id'] }}">
                                                        <i class="fa fa-stop me-1"></i> Stop
                                                    </button>
                                                </div>
                                            </div>

                                            <div id="xtts-wrap-{{ $r['id'] }}" class="mt-1" wire:ignore>
                                                <div id="xtts-ph-{{ $r['id'] }}" class="border rounded bg-dark" style="height:90px; opacity:.25;"></div>
                                                <div id="xtts-wave-{{ $r['id'] }}" class="border rounded" style="height:90px; display:none;"></div>
                                            </div>
                                        </div>

                                        <div class="mt-2" wire:ignore>
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="{{ $r['download_url'] }}"
                                               target="_blank"
                                               rel="noopener">
                                                Download
                                            </a>
                                        </div>
                                    </div>
                                @endforeach

                                <div class="mt-3">
                                    {{ $this->renders->links() }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"></script>
<script>
(function () {
    if (!window.__XTTS_WAVE__) window.__XTTS_WAVE__ = {};
    const S = window.__XTTS_WAVE__;

    S.previewWS = S.previewWS || new Map();
    S.previewMeta = S.previewMeta || new Map();
    S.previewInit = S.previewInit || new Set();

    const AUDIO_CACHE_NAME = "xtts-audio-v3";
    const PRELOAD_LIMIT = 10;

    function formatTime(sec) {
        sec = Math.max(0, sec || 0);
        const m = String(Math.floor(sec / 60)).padStart(2, "0");
        const s = String(Math.floor(sec % 60)).padStart(2, "0");
        return `${m}:${s}`;
    }

    function buildWaveOptions(container, isLatest = false) {
        const primary = isLatest
            ? "#dc3545"
            : (getComputedStyle(document.documentElement).getPropertyValue("--bs-primary") || "#0d6efd").trim();

        return {
            container,
            height: 90,
            normalize: true,
            responsive: true,
            backend: "MediaElement",
            waveColor: primary,
            progressColor: primary,
            cursorColor: primary,
        };
    }

    function stopWS(ws) {
        if (!ws) return;
        try { ws.pause(); ws.setTime(0); } catch (e) {}
    }

    function stopAll(exceptKey = null) {
        S.previewWS.forEach((ws, jobId) => {
            if (jobId !== exceptKey) stopWS(ws);
        });
    }

    async function cacheMatch(url) {
        try {
            const cache = await caches.open(AUDIO_CACHE_NAME);
            return await cache.match(url);
        } catch (e) {
            return null;
        }
    }

    async function cachePut(url, response) {
        const cache = await caches.open(AUDIO_CACHE_NAME);
        await cache.put(url, response);
    }

    async function fetchAndCache(url) {
        const hit = await cacheMatch(url);
        if (hit) return hit;

        const res = await fetch(url, {
            method: "GET",
            cache: "no-cache",
            credentials: "same-origin",
        });

        if (!res.ok) throw new Error("Fetch failed " + res.status);
        await cachePut(url, res.clone());
        return res;
    }

    async function getBlobFromCacheOrFetch(url) {
        const res = await fetchAndCache(url);
        return await res.blob();
    }

    function destroyPreview(jobId) {
        const ws = S.previewWS.get(jobId);
        if (ws) {
            try { ws.destroy(); } catch (e) {}
            S.previewWS.delete(jobId);
        }

        const meta = S.previewMeta.get(jobId);
        if (meta?.blobUrl) {
            try { URL.revokeObjectURL(meta.blobUrl); } catch (e) {}
        }

        S.previewMeta.delete(jobId);

        const wave = document.getElementById("xtts-wave-" + jobId);
        if (wave) wave.innerHTML = "";
    }

    async function ensurePreviewBlobUrl(jobId, url) {
        const meta = S.previewMeta.get(jobId);
        if (meta && meta.url === url && meta.blobUrl) return meta.blobUrl;

        if (meta?.blobUrl) {
            try { URL.revokeObjectURL(meta.blobUrl); } catch (e) {}
        }

        const blob = await getBlobFromCacheOrFetch(url);
        const blobUrl = URL.createObjectURL(blob);
        S.previewMeta.set(jobId, { url, blobUrl });
        return blobUrl;
    }

    function initPreview(jobId, url, isLatest = false) {
        const ph = document.getElementById("xtts-ph-" + jobId);
        const wave = document.getElementById("xtts-wave-" + jobId);
        const time = document.getElementById("xtts-time-" + jobId);

        if (!wave || !url) return null;

        const existing = S.previewWS.get(jobId);
        if (existing) return existing;

        destroyPreview(jobId);

        if (ph) ph.style.display = "";
        wave.style.display = "none";

        const ws = WaveSurfer.create(buildWaveOptions(wave, isLatest));

        ws.on("ready", () => {
            if (ph) ph.style.display = "none";
            wave.style.display = "";
            if (time) time.textContent = `00:00 / ${formatTime(ws.getDuration())}`;
        });

        ws.on("timeupdate", () => {
            if (time) time.textContent = `${formatTime(ws.getCurrentTime())} / ${formatTime(ws.getDuration())}`;
        });

        ws.on("finish", () => {
            try { ws.setTime(0); } catch (e) {}
        });

        ws.on("error", (e) => {
            console.error("XTTS preview load failed:", jobId, e);
        });

        (async () => {
            try {
                const blobUrl = await ensurePreviewBlobUrl(jobId, url);
                ws.load(blobUrl);
            } catch (e) {
                console.error("XTTS preview fetch failed:", jobId, e);
                ws.load(url);
            }
        })();

        S.previewWS.set(jobId, ws);
        return ws;
    }

    function bindPreviewButtons() {
        document.querySelectorAll(".btn-xtts-preview[data-job][data-url]").forEach((btn) => {
            if (btn.dataset.bound === "1") return;
            btn.dataset.bound = "1";

            btn.addEventListener("click", () => {
                const jobId = btn.getAttribute("data-job");
                const url = btn.getAttribute("data-url");
                const isLatest = btn.getAttribute("data-latest") === "1";

                const ws = S.previewWS.get(jobId) || initPreview(jobId, url, isLatest);
                if (!ws) return;

                stopAll(jobId);
                ws.playPause();
            });
        });

        document.querySelectorAll(".btn-xtts-stop[data-job]").forEach((btn) => {
            if (btn.dataset.bound === "1") return;
            btn.dataset.bound = "1";

            btn.addEventListener("click", () => {
                const jobId = btn.getAttribute("data-job");
                stopWS(S.previewWS.get(jobId));
            });
        });
    }

    async function preloadAndRenderRecentAudio() {
        const buttons = Array.from(document.querySelectorAll(".btn-xtts-preview[data-job][data-url]"))
            .sort((a, b) => {
                const ra = Number(a.getAttribute("data-preload-rank") ?? 9999);
                const rb = Number(b.getAttribute("data-preload-rank") ?? 9999);
                return ra - rb;
            })
            .slice(0, PRELOAD_LIMIT);

        for (const btn of buttons) {
            const jobId = btn.getAttribute("data-job");
            const url = btn.getAttribute("data-url");
            const isLatest = btn.getAttribute("data-latest") === "1";

            if (!jobId || !url) continue;
            if (S.previewInit.has(jobId)) continue;

            try {
                await fetchAndCache(url);
                initPreview(jobId, url, isLatest);
                S.previewInit.add(jobId);
            } catch (e) {
                console.error("XTTS preload/render failed:", jobId, e);
            }
        }
    }

    function bootXttsPage() {
        bindPreviewButtons();

        if ("requestIdleCallback" in window) {
            requestIdleCallback(() => preloadAndRenderRecentAudio(), { timeout: 2000 });
        } else {
            setTimeout(() => preloadAndRenderRecentAudio(), 500);
        }
    }

    document.addEventListener("livewire:initialized", bootXttsPage);
    document.addEventListener("livewire:navigated", bootXttsPage);

    window.addEventListener("beforeunload", () => {
        S.previewWS.forEach((ws) => {
            try { ws.destroy(); } catch (e) {}
        });
        S.previewWS.clear();
    });
})();
</script>
@endpush