<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\OCR\OcrJobSyncService;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;

new
#[Layout('app::layouts.app')]
#[Title('OCR | METKURD')]
class extends Component
{
    use WithFileUploads;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    protected string $jobKind = 'ocr';
    public string $toolCode = 'ocr';

    #[Url(as: 'page', except: 1)]
    public int $page = 1;

    public $documentFile = null;
    public ?string $documentHash = null;
    public ?string $documentExt = null;
    public ?string $documentFileName = null;
    public ?string $documentFileMime = null;
    public ?int $documentFileBytes = null;

    public ?int $clientPdfPageCount = null;

    public string $lang = 'ckb';
    public string $pageRange = '';
    public int $dpi = 200;
    public int $psm = 6;
    public int $oem = 3;

    public bool $normalize = false;
    public bool $grayscale = true;
    public bool $autocontrast = true;
    public bool $sharpen = true;
    public bool $binarize = false;

    public ?string $currentJobId = null;
    public ?string $providerJobId = null;
    public ?string $currentStatus = null;
    public bool $showJobStatus = false;
    public bool $jobFinished = true;
    public int $currentProgress = 0;

    public string $search = '';
    public bool $showEliminateModal = false;
    public ?string $latestFinishedJobId = null;
    public ?array $loadedRender = null;
    public int $rendersRefreshKey = 0;
    public int $walletBalance = 0;
    public int $creditsCost = 0;

    public function mount(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
        $this->hydrateLatestFinishedRender();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('ocr-renders-refresh')]
    public function refreshUi(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
        $this->hydrateLatestFinishedRender();
        $this->rendersRefreshKey++;
    }

    protected function rules(): array
    {
        return [
            'documentFile' => 'required|file|mimes:pdf|max:204800',
            'lang' => 'required|string|max:50',
            'pageRange' => 'nullable|string|max:255',
            'dpi' => 'required|integer|min:72|max:600',
            'psm' => 'required|integer|min:0|max:13',
            'oem' => 'required|integer|min:0|max:3',
            'normalize' => 'boolean',
            'grayscale' => 'boolean',
            'autocontrast' => 'boolean',
            'sharpen' => 'boolean',
            'binarize' => 'boolean',
        ];
    }

    #[Computed]
    public function recentRenders()
    {
        $this->rendersRefreshKey;

        $customerId = auth('app')->id();
        if (!$customerId) {
            return MlJob::query()->whereRaw('1 = 0')->paginate(10);
        }

        $term = trim($this->search);

        $paginator = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($nested) use ($term) {
                    $nested->where('id', 'like', '%' . $term . '%')
                        ->orWhere('input->file_name', 'like', '%' . $term . '%');
                });
            })
            ->orderByDesc('finished_at')
            ->paginate(10);

        $paginator->setCollection(
            $paginator->getCollection()->values()->map(function (MlJob $job, int $index) {
                return array_merge(
                    $this->buildRenderPayload($job),
                    ['is_latest' => $index === 0]
                );
            })
        );

        return $paginator;
    }

    protected function currentFolderForCustomer($customer): string
    {
        return \App\Support\CustomerFolder::make(
            (int) $customer->id,
            $customer->profile?->first_name ?? $customer->first_name ?? null,
            $customer->profile?->last_name ?? $customer->last_name ?? null,
            $customer->username ?? null
        );
    }

    protected function resetDocumentState(bool $dispatchBrowserEvent = true): void
    {
        $this->documentFile = null;
        $this->documentHash = null;
        $this->documentExt = null;
        $this->documentFileName = null;
        $this->documentFileMime = null;
        $this->documentFileBytes = null;
        $this->clientPdfPageCount = null;
        $this->creditsCost = 0;
        $this->pageRange = '';

        if ($dispatchBrowserEvent) {
            $this->dispatch('ocr-document-cleared');
        }
    }

    protected function resetJobState(): void
    {
        $this->currentJobId = null;
        $this->providerJobId = null;
        $this->currentStatus = null;
        $this->showJobStatus = false;
        $this->jobFinished = true;
        $this->currentProgress = 0;
    }

    protected function resolveProgressForStatus(?string $status): int
    {
        return match ((string) $status) {
            'queued' => 10,
            'running' => 65,
            'saving' => 90,
            'done', 'failed', 'deleted', 'delete_failed' => 100,
            default => 0,
        };
    }

    protected function hydrateCurrentJobFromDb(): void
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            $this->resetJobState();
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->orderByDesc('updated_at')
            ->first();

        if (!$job) {
            $this->resetJobState();
            return;
        }

        $this->applyJobStateFromModel($job);
    }

    protected function hydrateLatestFinishedRender(): void
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            $this->latestFinishedJobId = null;
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();

        $this->latestFinishedJobId = $job ? (string) $job->id : null;
    }

    protected function applyJobStateFromModel(MlJob $job): void
    {
        $status = (string) $job->status;

        $this->currentJobId = (string) $job->id;
        $this->providerJobId = (string) ($job->provider_job_id ?? '');
        $this->currentStatus = $status;
        $this->jobFinished = in_array($status, ['done', 'failed', 'deleted', 'delete_failed'], true);
        $this->showJobStatus = !$this->jobFinished;
        $this->currentProgress = $this->resolveProgressForStatus($status);
    }

    public function updatedDocumentFile(): void
    {
        $this->validateOnly('documentFile');

        if (!$this->documentFile) {
            return;
        }

        try {
            $this->documentFileName = (string) $this->documentFile->getClientOriginalName();
            $this->documentFileBytes = (int) $this->documentFile->getSize();
            $this->documentFileMime = (string) ($this->documentFile->getMimeType() ?: 'application/pdf');
            $this->documentExt = strtolower((string) ($this->documentFile->getClientOriginalExtension() ?: 'pdf'));

            $realPath = $this->documentFile->getRealPath();
            $this->documentHash = $realPath && is_file($realPath)
                ? hash_file('sha256', $realPath)
                : sha1(($this->documentFileName ?? '') . '|' . ($this->documentFileBytes ?? 0));

            $this->syncCostPreview();
            $this->dispatch('alert', type: 'success', message: 'PDF uploaded successfully.');
        } catch (\Throwable $e) {
            Log::error('OCR_DOCUMENT_UPLOAD_FAIL', [
                'message' => $e->getMessage(),
            ]);

            $this->resetDocumentState();
            $this->dispatch('alert', type: 'error', message: 'Failed to process the uploaded PDF.');
        }
    }

    public function removeDocumentFile(): void
    {
        $this->resetDocumentState();
    }

    public function updatedClientPdfPageCount(): void
    {
        $this->syncCostPreview();
    }

    public function updatedPageRange(): void
    {
        $this->syncCostPreview();
    }

    public function updatedLang(): void
    {
        $this->syncCostPreview();
    }

    public function updatedDpi(): void
    {
        $this->syncCostPreview();
    }

    public function updatedPsm(): void
    {
        $this->syncCostPreview();
    }

    public function updatedOem(): void
    {
        $this->syncCostPreview();
    }

    protected function actionCode(): string
    {
        return 'standard';
    }

    protected function fullActionCode(): string
    {
        return $this->toolCode . '.' . $this->actionCode();
    }

    protected function estimatedPages(): int
    {
        $totalPages = max(1, (int) ($this->clientPdfPageCount ?: 1));
        $range = $this->parsePageRange($this->pageRange, $totalPages);

        return max(1, count($range ?: range(1, $totalPages)));
    }

    protected function parsePageRange(?string $value, int $maxPages = 0): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $pages = [];
        $parts = preg_split('/\s*,\s*/', $value) ?: [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $part, $m)) {
                $a = (int) $m[1];
                $b = (int) $m[2];

                if ($a > 0 && $b > 0 && $a <= $b) {
                    for ($i = $a; $i <= $b; $i++) {
                        $pages[] = $i;
                    }
                }
                continue;
            }

            if (preg_match('/^\d+$/', $part)) {
                $pages[] = (int) $part;
            }
        }

        $pages = array_values(array_unique(array_filter($pages, fn ($p) => $p > 0)));

        if ($maxPages > 0) {
            $pages = array_values(array_filter($pages, fn ($p) => $p <= $maxPages));
        }

        sort($pages);

        return $pages;
    }

    protected function requiredCredits(): int
    {
        $customer = auth('app')->user();
        $pages = $this->estimatedPages();

        if ($customer && method_exists($customer, 'priceCreditsFor')) {
            $credits = (int) $customer->priceCreditsFor($this->fullActionCode(), [
                'pages' => $pages,
                'lang' => $this->lang,
                'dpi' => $this->dpi,
            ]);

            if ($credits > 0) {
                return $credits;
            }
        }

        return max(100, $pages * 100);
    }

    protected function syncWallet(): void
    {
        $customer = auth('app')->user();
        $wallet = $customer?->wallet()->first();

        $subscription = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addon = (int) ($wallet?->addon_balance_credits ?? 0);

        $this->walletBalance = $subscription + $addon;
    }

    protected function syncCostPreview(): void
    {
        $customer = auth('app')->user();

        if (!$customer || !$this->documentFile) {
            $this->creditsCost = 0;
            return;
        }

        $this->creditsCost = max(0, $this->requiredCredits());
    }

    #[Computed]
    public function canProcess(): bool
    {
        return $this->processBlockedReason === null;
    }

    #[Computed]
    public function processBlockedReason(): ?string
    {
        $customer = auth('app')->user();

        if (!$customer) {
            return 'You must be logged in.';
        }

        if ($this->currentJobId && !$this->jobFinished) {
            return 'An OCR job is already in progress.';
        }

        if (method_exists($customer, 'isAllowed') && !$customer->isAllowed($this->fullActionCode())) {
            return 'Your plan does not allow OCR.';
        }

        if (!$this->documentFile) {
            return 'Please upload a PDF file.';
        }

        if ($this->creditsCost <= 0) {
            return 'Pricing could not be calculated.';
        }

        if ($this->walletBalance < $this->creditsCost) {
            return 'Not enough credits.';
        }

        return null;
    }

    protected function ocrBaseDir(string $jobId, $customer): string
    {
        $folder = $this->currentFolderForCustomer($customer);

        return "renders/{$folder}/ocr/{$jobId}";
    }

    protected function buildRenderPayload(MlJob $job): array
    {
        $locale = app()->getLocale();
        $textPath = (string) data_get($job->output, 'text.path', '');
        $jsonPath = (string) data_get($job->output, 'json.path', '');
        $inputPath = (string) data_get($job->input, 'file_path', '');
        $jobId = (string) $job->id;

        return [
            'id' => $jobId,
            'input_name' => (string) data_get($job->input, 'file_name', 'Untitled PDF'),
            'input_url' => $inputPath !== '' ? route('app.renders.ocr.input', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) . '?proxy=1' : null,
            'page_range' => (string) data_get($job->input, 'page_range', ''),
            'pages' => (int) data_get($job->input, 'pages_estimated', 0),
            'lang' => (string) data_get($job->input, 'lang', 'ckb'),
            'dpi' => (int) data_get($job->input, 'dpi', 200),
            'psm' => (int) data_get($job->input, 'psm', 6),
            'oem' => (int) data_get($job->input, 'oem', 3),
            'normalize' => (bool) data_get($job->input, 'normalize', false),
            'text_view_url' => $textPath !== '' ? route('app.renders.ocr.text.view', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) . '?proxy=1' : null,
            'text_download_url' => $textPath !== '' ? route('app.renders.ocr.text', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) : null,
            'json_view_url' => $jsonPath !== '' ? route('app.renders.ocr.json.view', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) . '?proxy=1' : null,
            'json_download_url' => $jsonPath !== '' ? route('app.renders.ocr.json', [
                'locale' => $locale,
                'jobId' => $jobId,
            ]) : null,
            'text_path' => $textPath,
            'json_path' => $jsonPath,
            'meta' => (array) ($job->meta ?? []),
            'created_at' => optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i'),
            'created_at_human' => optional($job->finished_at ?? $job->created_at)->diffForHumans(),
        ];
    }

    protected function setLoadedRenderFromJob(MlJob $job, bool $dispatchBrowserEvent = true): array
    {
        $render = $this->buildRenderPayload($job);
        $this->loadedRender = $render;
        $this->latestFinishedJobId = (string) $job->id;

        if ($dispatchBrowserEvent) {
            $this->dispatch('ocr-render-loaded', render: $render);
        }

        return $render;
    }

    public function submit(
        RunPodProvider $runpod,
        CreditService $credits,
        OcrJobSyncService $sync,
        JobExecutionLockService $locks,
        CustomerOutputStorage $storage
    ): void {
        $this->hydrateCurrentJobFromDb();

        if ($this->currentJobId && !$this->jobFinished) {
            $this->dispatch('alert', type: 'warning', message: 'An OCR job is already in progress.');
            return;
        }

        $customer = auth('app')->user();
        if (!$customer) {
            $this->dispatch('alert', type: 'error', message: 'You must be logged in.');
            return;
        }

        if (method_exists($customer, 'isAllowed') && !$customer->isAllowed($this->fullActionCode())) {
            $this->dispatch('alert', type: 'error', message: 'Your plan does not allow OCR.');
            return;
        }

        $this->validate();

        $active = MlJob::query()
            ->where('customer_id', (int) $customer->id)
            ->where('job_kind', $this->jobKind)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->first();

        if ($active) {
            $this->dispatch('alert', type: 'warning', message: 'You already have an OCR job in progress.');
            return;
        }

        $needed = $this->requiredCredits();
        if ($needed <= 0) {
            $this->dispatch('alert', type: 'error', message: 'Pricing is not configured for OCR.');
            return;
        }

        $jobId = (string) Str::uuid();
        $savedInput = null;
        $charged = false;
        $refundReason = 'provider_start_failed';

        try {
            try {
                $credits->charge((int) $customer->id, $needed, 'ocr_charge', [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $this->fullActionCode(),
                    'pages' => $this->estimatedPages(),
                    'lang' => $this->lang,
                    'dpi' => $this->dpi,
                ]);
                $charged = true;
            } catch (\Throwable) {
                $this->syncWallet();
                $this->dispatch('alert', type: 'error', message: 'Not enough credits.');
                return;
            }

            $baseDir = $this->ocrBaseDir($jobId, $customer->loadMissing('profile'));
            $fileExt = strtolower((string) ($this->documentExt ?: $this->documentFile?->getClientOriginalExtension() ?: 'pdf'));
            $inputPath = "{$baseDir}/input.{$fileExt}";

            $toolId = Tool::query()->where('code', $this->toolCode)->value('id');
            $actionId = ToolAction::query()
                ->where('tool_code', $this->toolCode)
                ->where('action_code', $this->actionCode())
                ->value('id');

            DB::transaction(function () use ($jobId, $customer, $needed, $toolId, $actionId) {
                MlJob::create([
                    'id' => $jobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => $toolId,
                    'tool_action_id' => $actionId,
                    'job_kind' => $this->jobKind,
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => (string) $this->documentHash,
                    'credits_charged' => (int) $needed,
                    'input' => [
                        'file_name' => (string) $this->documentFileName,
                        'file_mime' => (string) $this->documentFileMime,
                        'file_bytes' => (int) $this->documentFileBytes,
                        'file_ext' => (string) $this->documentExt,
                        'lang' => (string) $this->lang,
                        'page_range' => trim((string) $this->pageRange),
                        'pages_estimated' => (int) $this->estimatedPages(),
                        'client_pdf_page_count' => (int) ($this->clientPdfPageCount ?: 0),
                        'dpi' => (int) $this->dpi,
                        'psm' => (int) $this->psm,
                        'oem' => (int) $this->oem,
                        'normalize' => (bool) $this->normalize,
                        'grayscale' => (bool) $this->grayscale,
                        'autocontrast' => (bool) $this->autocontrast,
                        'sharpen' => (bool) $this->sharpen,
                        'binarize' => (bool) $this->binarize,
                    ],
                    'output' => null,
                    'error' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                    'meta' => [
                        'billing_metric' => 'ocr_page',
                        'billing_action' => $this->actionCode(),
                    ],
                ]);
            }, 3);

            $savedInput = $storage->saveUploadedFileToS3(
                (int) $customer->id,
                $this->documentFile,
                $inputPath,
                [
                    'job_id' => $jobId,
                    'tool' => 'ocr',
                    'purpose' => 'input_document',
                    'role' => 'source_pdf',
                    'checksum' => $this->documentHash,
                    'original_name' => $this->documentFileName,
                ]
            );

            $inputUrl = $storage->temporaryUrl($savedInput['path'], 120, [
                'ResponseContentType' => $savedInput['mime'] ?? 'application/pdf',
            ]);

            $job = MlJob::query()->findOrFail($jobId);
            $job->update([
                'input' => array_merge((array) ($job->input ?? []), [
                    'file_disk' => (string) $savedInput['disk'],
                    'file_path' => (string) $savedInput['path'],
                    'file_url' => (string) $inputUrl,
                ]),
                'storage_in_bytes' => (int) $savedInput['bytes'],
            ]);

            $lock = $locks->acquireOcrLock(
                customerId: (int) $customer->id,
                jobId: $jobId,
                inputHash: (string) $this->documentHash,
                session: request()->session(),
                agent: request()->userAgent(),
                ip: request()->ip()
            );

            if (!($lock['ok'] ?? false)) {
                $refundReason = 'ocr_lock_conflict';
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not lock the OCR job.'));
            }

            $payload = $sync->buildRunpodInput(
                job: $job->fresh(),
                inputDisk: (string) $savedInput['disk'],
                inputPath: (string) $savedInput['path'],
                fileName: (string) ($this->documentFileName ?: 'input.pdf'),
                lang: (string) $this->lang,
                pageRange: trim((string) $this->pageRange),
                dpi: (int) $this->dpi,
                psm: (int) $this->psm,
                oem: (int) $this->oem,
                normalize: (bool) $this->normalize,
                grayscale: (bool) $this->grayscale,
                autocontrast: (bool) $this->autocontrast,
                sharpen: (bool) $this->sharpen,
                binarize: (bool) $this->binarize
            );

            $endpointId = (string) (config('runpod.endpoints.kocr') ?: env('RUNPOD_ENDPOINT_ID_KOCR'));
            if ($endpointId === '') {
                throw new \RuntimeException('RUNPOD_ENDPOINT_ID_KOCR is missing.');
            }

            $response = $runpod->run($endpointId, $payload);

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new \RuntimeException('RunPod did not return a provider job ID.');
            }

            $job->update([
                'provider_job_id' => $providerJobId,
                'status' => 'running',
            ]);

            $this->currentJobId = $jobId;
            $this->providerJobId = $providerJobId;
            $this->currentStatus = 'running';
            $this->showJobStatus = true;
            $this->jobFinished = false;
            $this->currentProgress = 15;

            $this->dispatch('header:refresh');
            $this->dispatch('customerPlanUpdated');
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('ocr-renders-refresh');
            $this->dispatch('ocr-job-started', [
                'jobId' => $jobId,
                'providerJobId' => $providerJobId,
                'status' => 'running',
                'progress' => 15,
            ]);
            $this->dispatch('alert', type: 'success', message: 'OCR job submitted.');
            $this->syncWallet();
        } catch (\Throwable $e) {
            Log::error('OCR_SUBMIT_FAIL', [
                'job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            if ($savedInput && !empty($savedInput['path'])) {
                try {
                    $storage->deleteFromS3AndUncount(
                        (int) $customer->id,
                        (string) $savedInput['path'],
                        (int) ($savedInput['bytes'] ?? 0)
                    );
                } catch (\Throwable $cleanup) {
                    Log::warning('OCR_SUBMIT_CLEANUP_FAIL', [
                        'job_id' => $jobId,
                        'message' => $cleanup->getMessage(),
                    ]);
                }
            }

            if ($charged) {
                try {
                    $credits->refund((int) $customer->id, $needed, 'ocr_refund', [
                        'related_type' => 'ml_job',
                        'related_id' => $jobId,
                        'tool_action' => $this->fullActionCode(),
                        'reason' => $refundReason,
                    ]);
                } catch (\Throwable $refundError) {
                    Log::warning('OCR_REFUND_FAIL', [
                        'job_id' => $jobId,
                        'message' => $refundError->getMessage(),
                    ]);
                }
            }

            try {
                $locks->releaseLock($jobId);
            } catch (\Throwable) {
            }

            MlJob::query()
                ->where('id', $jobId)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => $e->getMessage()],
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->dispatch('ocr-job-state-clear');
            $this->dispatch('customerPlanUpdated');
            $this->dispatch('alert', type: 'error', message: $e->getMessage());
            $this->syncWallet();
            $this->resetJobState();
        }
    }

    public function pollJob(OcrJobSyncService $sync): void
    {
        if (!$this->currentJobId) {
            $this->hydrateCurrentJobFromDb();
            return;
        }

        $job = MlJob::query()
            ->where('id', $this->currentJobId)
            ->where('customer_id', auth('app')->id())
            ->first();

        if (!$job) {
            $this->hydrateCurrentJobFromDb();
            return;
        }

        $result = $sync->sync($job);

        $this->currentStatus = (string) ($result['status'] ?? $job->status);
        $this->currentProgress = (int) ($result['progress'] ?? $this->resolveProgressForStatus($this->currentStatus));
        $this->showJobStatus = true;
        $this->jobFinished = false;

        $this->dispatch('ocr-job-state-sync', [
            'jobId' => (string) $job->id,
            'status' => $this->currentStatus,
            'progress' => $this->currentProgress,
        ]);

        if (!empty($result['done'])) {
            $fresh = MlJob::query()
                ->where('id', $job->id)
                ->where('customer_id', auth('app')->id())
                ->first();

            if ($fresh) {
                $render = $this->setLoadedRenderFromJob($fresh);
                $this->dispatch('ocr-job-completed', render: $render);
            } else {
                $this->dispatch('ocr-job-completed');
            }

            $this->dispatch('ocr-job-state-clear');
            $this->dispatch('header:refresh');
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('ocr-renders-refresh');
            $this->dispatch('alert', type: 'success', message: (string) ($result['message'] ?? 'OCR completed.'));
            $this->resetJobState();
            $this->hydrateLatestFinishedRender();

            return;
        }

        if (!empty($result['failed'])) {
            $this->dispatch('ocr-job-state-clear');
            $this->dispatch('header:refresh');
            $this->dispatch('ocr-renders-refresh');
            $this->dispatch('alert', type: 'error', message: (string) ($result['message'] ?? 'OCR failed.'));
            $this->resetJobState();
        }
    }

    public function loadRender(string $jobId): void
    {
        $job = MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->first();

        if (!$job) {
            $this->dispatch('alert', type: 'error', message: 'Render not found.');
            return;
        }

        $this->setLoadedRenderFromJob($job);
    }

    public function deleteRender(string $jobId, CustomerOutputStorage $storage): void
    {
        $job = MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->first();

        if (!$job) {
            $this->dispatch('alert', type: 'error', message: 'Render not found.');
            return;
        }

        try {
            $storage->deleteOcrOutputs($job);

            if (($this->loadedRender['id'] ?? null) === (string) $job->id) {
                $this->loadedRender = null;
                $this->dispatch('ocr-render-cleared');
            }

            $this->resetPage();
            $this->hydrateLatestFinishedRender();
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('header:refresh');
            $this->dispatch('ocr-renders-refresh');
            $this->dispatch('alert', type: 'success', message: 'Render deleted.');
        } catch (\Throwable $e) {
            Log::error('OCR_DELETE_RENDER_FAIL', [
                'job_id' => (string) $job->id,
                'message' => $e->getMessage(),
            ]);

            $this->dispatch('alert', type: 'error', message: 'Failed to delete render.');
        }
    }

    public function resetForm(): void
    {
        $this->resetDocumentState();

        $this->lang = 'ckb';
        $this->pageRange = '';
        $this->dpi = 200;
        $this->psm = 6;
        $this->oem = 3;
        $this->normalize = false;
        $this->grayscale = true;
        $this->autocontrast = true;
        $this->sharpen = true;
        $this->binarize = false;
        $this->syncCostPreview();

        $this->dispatch('ocr-form-reset');
        $this->dispatch('ocr-form-state-clear');
    }

    public function openEliminateModal(): void
    {
        $this->showEliminateModal = true;
    }

    public function closeEliminateModal(): void
    {
        $this->showEliminateModal = false;
    }

    public function eliminateCurrentJob(JobExecutionLockService $locks, CustomerOutputStorage $storage): void
    {
        $this->closeEliminateModal();

        if (!$this->currentJobId) {
            return;
        }

        $customerId = auth('app')->id();
        $job = MlJob::query()
            ->where('id', $this->currentJobId)
            ->where('customer_id', $customerId)
            ->first();

        if ($job && in_array((string) $job->status, ['queued', 'running', 'saving'], true)) {
            $job->update([
                'status' => 'failed',
                'error' => [
                    'message' => 'Eliminated by customer. Credits are not refundable.',
                    'type' => 'eliminated_by_customer',
                ],
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            $filePath = (string) data_get($job->input, 'file_path', '');
            $fileBytes = (int) ((int) $job->storage_in_bytes ?: data_get($job->input, 'file_bytes', 0));
            $fileDisk = (string) data_get($job->input, 'file_disk', 's3');

            try {
                if ($filePath !== '') {
                    $storage->deleteFromDiskAndUncount((int) $customerId, $fileDisk, $filePath, $fileBytes);
                }
            } catch (\Throwable $e) {
                Log::warning('OCR_ELIMINATE_INPUT_DELETE_FAIL', [
                    'job_id' => (string) $job->id,
                    'path' => $filePath,
                    'message' => $e->getMessage(),
                ]);
            }

            $locks->releaseLock((string) $job->id);
        }

        $this->dispatch('ocr-job-state-clear');
        $this->dispatch('header:refresh');
        $this->dispatch('customerStorageUpdated');
        $this->dispatch('ocr-renders-refresh');
        $this->dispatch('alert', type: 'warning', message: 'Current OCR job eliminated. Credits were not refunded.');
        $this->resetJobState();
    }

    public function render()
    {
        return view('app.pages.ocr.⚡app-ocr');
    }
};
?>

<div id="ocr-page-root" x-data="ocrFormCache()" x-init="init()">
    @if($currentJobId && !$jobFinished)
        <div wire:poll.keep-alive.3000ms="pollJob"></div>
    @endif

    @php
        $latestTitle = $loadedRender['input_name'] ?? ($loadedRender['id'] ?? '—');

        $status = $currentStatus ?? 'queued';
        $badge = match($status) {
            'queued' => 'secondary',
            'running' => 'info',
            'saving' => 'warning',
            'done' => 'success',
            'failed' => 'danger',
            default => 'secondary'
        };

        $glassClass = match($status) {
            'running' => 'glass-load--info',
            'saving' => 'glass-load--warning',
            'done' => 'glass-load--success',
            'failed' => 'glass-load--danger',
            default => 'glass-load--secondary'
        };
    @endphp

    <div class="row">
        <div class="col-lg-8 mb-4">

            @if($showJobStatus && $currentJobId)
                <div class="card glass-load {{ $glassClass }} mb-3">
                    <div class="card-body py-2 px-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="d-flex align-items-center">
                                <span class="badge badge-{{ $badge }} mr-2 text-uppercase" style="letter-spacing:.5px;">
                                    {{ $currentStatus }}
                                </span>
                                <small class="tts-status-muted">
                                    Job ID: <span class="font-weight-bold">{{ $currentJobId }}</span>
                                </small>
                            </div>

                            <div>
                                @if(!$jobFinished)
                                    <small class="tts-status-muted">
                                        <span class="spinner-border spinner-border-sm mr-1" role="status"></span>
                                        Working...
                                    </small>
                                @else
                                    <small class="tts-status-muted">Finished</small>
                                @endif
                            </div>
                        </div>

                        <div class="progress ocr-job-progress">
                            <div
                                class="progress-bar progress-bar-striped progress-bar-animated"
                                role="progressbar"
                                style="width: {{ max(5, min(100, $currentProgress)) }}%;"
                                aria-valuenow="{{ $currentProgress }}"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                {{ $currentProgress }}%
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <strong class="d-block">Upload PDF</strong>
                                    <small class="text-muted">OCR-ready PDF viewer with range preview</small>
                                </div>
                                <span class="badge badge-primary">OCR</span>
                            </div>

                            <div class="d-flex flex-wrap gap-3 mb-3 ocr-top-mini-stats">
                                <div class="ocr-mini-stat">
                                    <div class="text-muted small">Wallet</div>
                                    <div class="fw-semibold">{{ number_format($walletBalance) }}</div>
                                </div>
                                <div class="ocr-mini-stat">
                                    <div class="text-muted small">Cost</div>
                                    <div class="fw-semibold">{{ number_format($creditsCost) }}</div>
                                </div>
                                <div class="ocr-mini-stat">
                                    <div class="text-muted small">Pages</div>
                                    <div class="fw-semibold">{{ $clientPdfPageCount ?: 0 }}</div>
                                </div>
                                <div class="ocr-mini-stat">
                                    <div class="text-muted small">Selected</div>
                                    <div class="fw-semibold">{{ $documentFile ? $this->estimatedPages() : 0 }}</div>
                                </div>
                            </div>

                            <section class="ocr-card dropzone mb-3">
                                <div id="dropbox" class="dropbox" role="button" tabindex="0" wire:ignore>
                                    <div style="min-width:0">
                                        <div class="pill" style="display:inline-block;margin-bottom:8px;">.pdf only</div>
                                        <div id="fileHint" class="hint">
                                            {{ $documentFileName ? $documentFileName : 'No file selected.' }}
                                        </div>
                                        <div id="errorBox" class="error"></div>
                                    </div>

                                    <div class="actions">
                                        <label class="btn btn-primary" for="fileInput">Choose PDF</label>
                                        <button id="clearBtn" class="btn btn-outline-danger" type="button" {{ $documentFile ? '' : 'disabled' }}>
                                            Clear
                                        </button>
                                    </div>

                                    <input id="fileInput" type="file" accept="application/pdf" />
                                </div>

                                <div wire:loading wire:target="documentFile" class="small text-primary mt-2">
                                    Uploading PDF...
                                </div>
                            </section>

                            <section class="ocr-card viewer" wire:ignore>
                                <div class="toolbar">
                                    <div class="group">
                                        <button id="prevBtn" type="button" class="btn btn-sm btn-outline-secondary" disabled>◀ Prev</button>
                                        <button id="nextBtn" type="button" class="btn btn-sm btn-outline-secondary" disabled>Next ▶</button>
                                        <span class="pill" id="pageInfo">Page 0 / 0</span>
                                    </div>

                                    <div class="group">
                                        <div class="zoom">
                                            <span>Zoom</span>
                                            <input id="zoomRange" type="range" min="50" max="200" value="110" disabled />
                                            <span id="zoomLabel">110%</span>
                                        </div>
                                    </div>

                                    <div class="group rangeWrap">
                                        <span class="pill">Range</span>
                                        <input id="rangeInput" type="text" placeholder="e.g. 1-3,5,8-10" value="{{ $pageRange }}" disabled />
                                        <button id="applyRangeBtn" class="btn btn-sm btn-primary" type="button" disabled>Preview Range</button>
                                        <button id="clearRangeBtn" class="btn btn-sm btn-outline-secondary" type="button" disabled>Clear Range</button>
                                        <button id="downloadRangeBtn" class="btn btn-sm btn-primary" type="button" disabled>Download Range</button>
                                        <button id="downloadAllBtn" class="btn btn-sm btn-outline-secondary" type="button" disabled>Download Full</button>
                                        <span class="rangeHelp" id="rangeHelp"></span>
                                    </div>
                                </div>

                                <div class="viewerGrid">
                                    <aside class="thumbs" id="thumbsPanel" aria-label="Thumbnails">
                                        <div class="thumbsHeader">
                                            <span class="pill">Thumbnails</span>
                                            <span class="muted" id="thumbsCount">0</span>
                                        </div>
                                        <div class="thumbsList" id="thumbsList">
                                            <div class="empty" id="thumbsEmpty">Upload a PDF to see thumbnails.</div>
                                        </div>
                                    </aside>

                                    <div class="mainStage" id="mainStage">
                                        <div class="empty" id="emptyState">Upload a PDF to preview it here.</div>
                                        <canvas id="pdfCanvas" style="display:none;"></canvas>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </div>

            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between flex-wrap mb-3">
                                <div>
                                    <strong class="d-block">Latest Output</strong>
                                    <small class="text-muted">Title: <b>{{ $latestTitle }}</b></small>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    @if($loadedRender && $loadedRender['text_download_url'])
                                        <small class="text-muted mt-2 mt-md-0 mr-2">
                                            Render: <b>#{{ $loadedRender['id'] }}</b>
                                        </small>
                                        <a href="{{ $loadedRender['text_download_url'] }}" class="btn btn-sm btn-primary" target="_blank" rel="noopener">
                                            <i class="mdi mdi-download"></i> Download TXT
                                        </a>
                                        @if($loadedRender['json_view_url'])
                                            <a href="{{ $loadedRender['json_view_url'] }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                                                <i class="mdi mdi-code-json"></i> JSON
                                            </a>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            <div id="ocr-output-empty" class="{{ $loadedRender ? 'd-none' : '' }}">
                                <div class="text-muted small text-center py-4">No OCR result selected yet.</div>
                            </div>

                            <div id="ocr-output-wrap" class="{{ $loadedRender ? '' : 'd-none' }}">
                                <div class="ocr-output-meta mb-3">
                                    <div class="row g-2">
                                        <div class="col-md-3 col-6">
                                            <div class="ocr-meta-chip">
                                                <span class="text-muted small d-block">Language</span>
                                                <strong>{{ $loadedRender['lang'] ?? 'ckb' }}</strong>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="ocr-meta-chip">
                                                <span class="text-muted small d-block">Range</span>
                                                <strong>{{ ($loadedRender['page_range'] ?? '') !== '' ? $loadedRender['page_range'] : 'All pages' }}</strong>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="ocr-meta-chip">
                                                <span class="text-muted small d-block">DPI</span>
                                                <strong>{{ $loadedRender['dpi'] ?? 200 }}</strong>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="ocr-meta-chip">
                                                <span class="text-muted small d-block">PSM / OEM</span>
                                                <strong>{{ ($loadedRender['psm'] ?? 6) . ' / ' . ($loadedRender['oem'] ?? 3) }}</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="ocr-text-result-wrap">
                                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                        <strong>Extracted Text</strong>
                                        <button id="ocr-copy-text-btn" type="button" class="btn btn-sm btn-outline-secondary">
                                            <i class="mdi mdi-content-copy"></i> Copy
                                        </button>
                                    </div>

                                    <textarea id="ocr-result-text" class="form-control ocr-result-text" rows="14" readonly placeholder="OCR text will appear here..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">

            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <strong>Parameters</strong>
                                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary" type="button">
                                    <i class="mdi mdi-refresh"></i> Reset
                                </button>
                            </div>

                            <hr>

                            <div class="mb-3">
                                <label class="mb-1"><b>Language</b></label>
                                <select wire:model.live="lang" class="form-control rounded-pill">
                                    <option value="ckb">ckb</option>
                                    <option value="ara">ara</option>
                                    <option value="eng">eng</option>
                                    <option value="ckb+ara">ckb+ara</option>
                                    <option value="ckb+eng">ckb+eng</option>
                                    <option value="ara+eng">ara+eng</option>
                                    <option value="ckb+ara+eng">ckb+ara+eng</option>
                                </select>
                            </div>

                            <hr>

                            <div class="mb-3">
                                <label class="mb-1"><b>Page Range</b></label>
                                <input type="text" wire:model.live.debounce.300ms="pageRange" class="form-control rounded-pill" placeholder="e.g. 1-3,5,8-10">
                                <small class="text-muted d-block mt-2">Leave empty to OCR the full PDF.</small>
                            </div>

                            <hr>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="mb-1"><b>DPI</b></label>
                                    <select wire:model.live="dpi" class="form-control rounded-pill">
                                        <option value="150">150</option>
                                        <option value="200">200</option>
                                        <option value="300">300</option>
                                        <option value="400">400</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="mb-1"><b>PSM</b></label>
                                    <select wire:model.live="psm" class="form-control rounded-pill">
                                        @for($i = 0; $i <= 13; $i++)
                                            <option value="{{ $i }}">{{ $i }}</option>
                                        @endfor
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="mb-1"><b>OEM</b></label>
                                    <select wire:model.live="oem" class="form-control rounded-pill">
                                        <option value="0">0</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                    </select>
                                </div>
                            </div>

                            <hr>

                            <div class="mb-2">
                                <label class="mb-2"><b>Processing Options</b></label>

                                <div class="ocr-check-grid">
                                    <label class="ocr-check-item">
                                        <input type="checkbox" wire:model.live="normalize">
                                        <span>Normalize to Sorani</span>
                                    </label>
                                    <label class="ocr-check-item">
                                        <input type="checkbox" wire:model.live="grayscale">
                                        <span>Grayscale</span>
                                    </label>
                                    <label class="ocr-check-item">
                                        <input type="checkbox" wire:model.live="autocontrast">
                                        <span>Auto Contrast</span>
                                    </label>
                                    <label class="ocr-check-item">
                                        <input type="checkbox" wire:model.live="sharpen">
                                        <span>Sharpen</span>
                                    </label>
                                    <label class="ocr-check-item">
                                        <input type="checkbox" wire:model.live="binarize">
                                        <span>Binarize</span>
                                    </label>
                                </div>
                            </div>

                            <div class="ocr-cost-preview rounded-3 p-3 mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold">Estimated Cost</div>
                                        <div class="small text-muted">
                                            {{ $documentFile ? $this->estimatedPages() . ' selected page(s)' : 'Upload a PDF first' }}
                                        </div>
                                    </div>

                                    <div class="badge ocr-badge-credits px-3 py-2">
                                        {{ number_format($creditsCost) }} credits
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 mt-3">
                                <button
                                    class="btn {{ $this->canProcess ? 'btn-success' : 'btn-danger' }}"
                                    wire:click="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="submit,documentFile"
                                    @disabled(!$this->canProcess)
                                    type="button"
                                >
                                    <span wire:loading.remove wire:target="submit,documentFile">
                                        <span class="mr-1" aria-hidden="true">▶</span>
                                        {{ $this->canProcess ? 'Run OCR' : ($this->processBlockedReason ?? 'Run OCR') }}
                                    </span>

                                    <span wire:loading wire:target="documentFile">
                                        <span class="spinner-border spinner-border-sm mr-1"></span>
                                        Uploading PDF...
                                    </span>

                                    <span wire:loading wire:target="submit">
                                        <span class="spinner-border spinner-border-sm mr-1"></span>
                                        Sending...
                                    </span>
                                </button>

                                @if($currentJobId && !$jobFinished)
                                    <button type="button" class="btn btn-outline-danger btn-sm" wire:click="openEliminateModal">
                                        Eliminate Current Job
                                    </button>
                                @endif

                                @if($walletBalance < $creditsCost && $creditsCost > 0)
                                    <span class="small text-danger">
                                        Not enough credits for this OCR job.
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-2 gap-2 flex-wrap">
                                <strong>Recent Renders</strong>
                                <span class="badge badge-secondary">{{ $this->recentRenders->total() }}</span>
                            </div>

                            <div class="mb-3">
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    placeholder="Search..."
                                    wire:model.live.debounce.300ms="search"
                                >
                            </div>

                            @if($this->recentRenders->count() === 0)
                                <div class="text-muted small text-center py-4">No renders yet.</div>
                            @else
                                <div class="list-group">
                                    @foreach($this->recentRenders as $render)
                                        <div class="list-group-item render-item ocr-render-item {{ $latestFinishedJobId === (string) $render['id'] ? 'ocr-render-item--latest' : '' }}">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div style="min-width:0; flex:1;">
                                                    <button
                                                        type="button"
                                                        class="btn btn-link p-0 text-left render-select-btn"
                                                        style="text-decoration:none; width:100%;"
                                                        wire:click="loadRender('{{ $render['id'] }}')"
                                                    >
                                                        <div class="d-flex align-items-center">
                                                            <i class="mdi mdi-file-document-outline mr-2 render-cache-icon"></i>
                                                            <div>
                                                                <b class="d-block text-truncate" style="max-width: 200px;">{{ $render['input_name'] ?: 'Untitled PDF' }}</b>
                                                                <small class="text-muted d-block">
                                                                    {{ $render['page_range'] ?: 'All pages' }} • {{ $render['lang'] }}
                                                                </small>
                                                                <small class="text-muted d-block">{{ $render['created_at'] }}</small>
                                                            </div>
                                                        </div>
                                                    </button>
                                                </div>

                                                <div class="d-flex align-items-center gap-1 ml-2">
                                                    @if($render['text_download_url'])
                                                        <a href="{{ $render['text_download_url'] }}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" title="TXT">
                                                            <i class="mdi mdi-download"></i>
                                                        </a>
                                                    @endif

                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-danger"
                                                        wire:click.stop="deleteRender('{{ $render['id'] }}')"
                                                        onclick="return confirm('Delete this render? This will remove files and deduct storage.');"
                                                    >
                                                        <i class="mdi mdi-delete"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if($this->recentRenders->hasPages())
                                    <div class="mt-3">
                                        {{ $this->recentRenders->links() }}
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($showEliminateModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h5 class="modal-title">Eliminate current OCR job?</h5>
                        <button type="button" class="btn-close" wire:click="closeEliminateModal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">
                            This will stop tracking the current OCR job and mark it as eliminated.
                        </p>
                        <p class="mb-0 text-danger small">
                            Credits are not refundable.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeEliminateModal">Cancel</button>
                        <button type="button" class="btn btn-danger" wire:click="eliminateCurrentJob">
                            Eliminate
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@once
    @push('styles')
        <style>
            :root{
                --ocr-bg: #0f1117;
                --ocr-card: #171b24;
                --ocr-line: rgba(255,255,255,.10);
                --ocr-text: #eef2ff;
                --ocr-muted: #9aa4bc;
                --ocr-accent: #6ea8fe;
                --ocr-danger: #ff5b5b;
            }

            .ocr-job-progress{
                height: .6rem;
                background: rgba(255,255,255,.08);
                border-radius: 999px;
                overflow: hidden;
            }

            .ocr-top-mini-stats{
                gap: .75rem;
            }

            .ocr-mini-stat{
                min-width: 90px;
                padding: .65rem .85rem;
                border-radius: 12px;
                background: rgba(255,255,255,.04);
                border: 1px solid rgba(255,255,255,.08);
            }

            .ocr-card{
                background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.02));
                border: 1px solid rgba(255,255,255,.08);
                border-radius: 18px;
                box-shadow: 0 10px 24px rgba(0,0,0,.18);
                color: var(--ocr-text);
            }

            .dropzone{
                padding: 14px;
            }

            .dropbox{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:14px;
                padding:16px 18px;
                min-height: 94px;
                border:1px dashed rgba(255,255,255,.16);
                border-radius:16px;
                background: rgba(255,255,255,.02);
                transition:.18s ease;
                cursor:pointer;
            }

            .dropbox.is-dragover{
                border-color: rgba(110,168,254,.75);
                background: rgba(110,168,254,.08);
                box-shadow: 0 0 0 3px rgba(110,168,254,.12);
            }

            .dropbox .hint{
                color: var(--ocr-text);
                font-weight: 600;
                font-size: 14px;
                word-break: break-word;
            }

            .dropbox .actions{
                display:flex;
                gap:10px;
                align-items:center;
                flex-wrap:wrap;
            }

            .dropbox input[type="file"]{
                display:none;
            }

            .pill{
                font-size:12px;
                background: rgba(255,255,255,.06);
                border:1px solid rgba(255,255,255,.12);
                padding:6px 10px;
                border-radius:999px;
                color: rgba(233,238,252,.9);
            }

            .viewer{
                padding:12px 12px 16px;
            }

            .toolbar{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:12px;
                padding:10px;
                border-bottom:1px solid rgba(255,255,255,.10);
                background: rgba(0,0,0,.10);
                flex-wrap:wrap;
                border-radius: 14px 14px 0 0;
            }

            .group{
                display:flex;
                gap:8px;
                align-items:center;
                flex-wrap:wrap;
            }

            .zoom{
                display:flex;
                align-items:center;
                gap:8px;
                color:var(--ocr-muted);
                font-size:13px;
            }

            .zoom input[type="range"]{
                width:160px;
            }

            .rangeWrap{
                display:flex;
                gap:8px;
                align-items:center;
                flex-wrap:wrap;
            }

            .rangeWrap input[type="text"]{
                width:260px;
                font-weight:500;
                font-size:13px;
                outline:none;
                background: rgba(255,255,255,.03);
                border: 1px solid rgba(255,255,255,.10);
                color: var(--ocr-text);
                border-radius: 12px;
                padding: 8px 10px;
            }

            .rangeHelp{
                color: var(--ocr-muted);
                font-size: 12px;
                font-weight: 500;
            }

            .viewerGrid{
                display:flex;
                gap:12px;
                padding:12px;
                align-items:stretch;
                height: 72vh;
                min-height: 520px;
            }

            .thumbs{
                width:220px;
                min-width:220px;
                border:1px solid rgba(255,255,255,.10);
                background: rgba(0,0,0,.10);
                border-radius:14px;
                overflow:hidden;
                display:flex;
                flex-direction:column;
                height:100%;
            }

            .thumbsList{
                overflow:auto;
                padding:10px;
                display:flex;
                flex-direction:column;
                gap:10px;
                flex:1;
                min-height:0;
            }

            .thumbsHeader{
                padding:10px 10px;
                border-bottom:1px solid rgba(255,255,255,.10);
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:8px;
            }

            .thumbsHeader .muted{
                color: var(--ocr-muted);
                font-size:12px;
                font-weight:600;
            }

            .thumbItem{
                border:1px solid rgba(255,255,255,.10);
                background: rgba(255,255,255,.03);
                border-radius:12px;
                padding:8px;
                cursor:pointer;
                transition:.12s ease;
                display:flex;
                flex-direction:column;
                gap:8px;
            }

            .thumbItem:hover,
            .thumbItem.active{
                border-color: rgba(110,168,254,.6);
                box-shadow: 0 0 0 2px rgba(110,168,254,.10);
                background: rgba(110,168,254,.08);
            }

            .thumbItem canvas{
                width:100%;
                height:auto;
                border-radius:8px;
                background:#fff;
            }

            .thumbLabel{
                font-size:12px;
                color: var(--ocr-muted);
                font-weight:600;
                text-align:center;
            }

            .mainStage{
                flex:1;
                min-width:0;
                border:1px solid rgba(255,255,255,.10);
                background: rgba(0,0,0,.10);
                border-radius:14px;
                overflow:auto;
                height:100%;
                display:flex;
                justify-content:center;
                align-items:flex-start;
                padding:14px;
                max-height:none;
            }

            #pdfCanvas{
                background:#fff;
                border-radius:10px;
                box-shadow: 0 10px 28px rgba(0,0,0,.35);
                margin-bottom:12px;
            }

            .empty{
                padding:26px;
                text-align:center;
                color:var(--ocr-muted);
                font-size:14px;
            }

            .error{
                margin-top:10px;
                color: #ffd0d0;
                background: rgba(255,91,91,.12);
                border: 1px solid rgba(255,91,91,.25);
                padding:10px 12px;
                border-radius:12px;
                font-size:13px;
                display:none;
            }

            .ocr-cost-preview{
                background: rgba(var(--bs-warning-rgb), .08);
                border: 1px solid rgba(var(--bs-warning-rgb), .22);
            }

            .ocr-badge-credits{
                background: rgba(var(--bs-warning-rgb), .18);
                color: var(--bs-warning-text-emphasis);
                border: 1px solid rgba(var(--bs-warning-rgb), .24);
            }

            .ocr-check-grid{
                display:grid;
                grid-template-columns: 1fr;
                gap:.55rem;
            }

            .ocr-check-item{
                display:flex;
                align-items:center;
                gap:.6rem;
                padding:.65rem .8rem;
                border-radius:12px;
                border:1px solid rgba(255,255,255,.08);
                background: rgba(255,255,255,.03);
                margin:0;
                cursor:pointer;
            }

            .ocr-output-meta{
                margin-bottom: 1rem;
            }

            .ocr-meta-chip{
                padding: .85rem .9rem;
                border-radius: 12px;
                background: rgba(255,255,255,.04);
                border: 1px solid rgba(255,255,255,.08);
                height: 100%;
            }

            .ocr-result-text{
                min-height: 320px;
                resize: vertical;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                font-size: 13px;
                line-height: 1.6;
            }

            .ocr-render-item{
                transition: all 0.2s ease;
            }

            .ocr-render-item:hover{
                background: rgba(0,123,255,.05);
                border-color: rgba(0,123,255,.2);
            }

            .ocr-render-item--latest{
                border-left: 3px solid var(--bs-primary);
            }

            .render-select-btn{
                cursor:pointer !important;
            }

            .render-cache-icon{
                font-size: 1.2rem;
                opacity: .65;
            }

            @media (max-width: 980px){
                .viewerGrid{ flex-direction:column; height: 74vh; }
                .thumbs{ width:100%; min-width:0; }
                .thumbsList{
                    flex-direction:row;
                    flex-wrap:nowrap;
                    overflow:auto;
                }
                .thumbItem{ width: 160px; flex: 0 0 auto; }
            }

            @media (max-width: 760px){
                .dropbox{ flex-direction:column; align-items:stretch; }
                .actions{ justify-content:flex-start; }
                .zoom input[type="range"]{ width:120px; }
                .rangeWrap input[type="text"]{ width: 100%; }
            }
        </style>
    @endpush

    @push('scripts')
        <script type="module">
            import * as pdfjsLib from "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.mjs";
            import { PDFDocument } from "https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/+esm";

            pdfjsLib.GlobalWorkerOptions.workerSrc =
                "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.mjs";

            (() => {
                const ROOT_ID = 'ocr-page-root';

                if (!window.__OCR_PAGE__) {
                    window.__OCR_PAGE__ = {
                        pdfDoc: null,
                        pageCount: 0,
                        scale: 1.1,
                        currentFile: null,
                        currentBlobUrl: null,
                        activePages: null,
                        activeIndex: 0,
                        currentRender: @js($loadedRender),
                        textUrl: null,
                        eventsBound: false,
                        commitHooked: false,
                        listenersBound: false,
                        windowDragBound: false,
                        bootTimer: null,
                    };
                }

                const S = window.__OCR_PAGE__;

                function getOcrComponent() {
                    if (!window.Livewire) return null;

                    const root = document.getElementById(ROOT_ID);
                    if (!root) return null;

                    const wireId = root.getAttribute('wire:id');
                    if (!wireId) return null;

                    try {
                        return window.Livewire.find(wireId);
                    } catch (_) {
                        return null;
                    }
                }

                function qs(id) {
                    return document.getElementById(id);
                }

                function showError(msg = '') {
                    const box = qs('errorBox');
                    if (!box) return;
                    if (!msg) {
                        box.style.display = 'none';
                        box.textContent = '';
                        return;
                    }
                    box.textContent = msg;
                    box.style.display = 'block';
                }

                function setControlsEnabled(enabled) {
                    const ids = [
                        'prevBtn', 'nextBtn', 'zoomRange', 'rangeInput',
                        'applyRangeBtn', 'clearRangeBtn', 'downloadRangeBtn', 'downloadAllBtn', 'clearBtn'
                    ];

                    ids.forEach(id => {
                        const el = qs(id);
                        if (el) el.disabled = !enabled;
                    });
                }

                function updateHint(name = 'No file selected.') {
                    const hint = qs('fileHint');
                    if (hint) hint.textContent = name;
                }

                function setEmptyState(msg) {
                    const empty = qs('emptyState');
                    const canvas = qs('pdfCanvas');

                    if (empty) {
                        empty.textContent = msg;
                        empty.style.display = '';
                    }
                    if (canvas) {
                        canvas.style.display = 'none';
                    }
                }

                function clearThumbs() {
                    const thumbsList = qs('thumbsList');
                    const thumbsEmpty = qs('thumbsEmpty');
                    const thumbsCount = qs('thumbsCount');

                    if (thumbsList) thumbsList.innerHTML = '';
                    if (thumbsEmpty && thumbsList) thumbsList.appendChild(thumbsEmpty);
                    if (thumbsEmpty) thumbsEmpty.style.display = '';
                    if (thumbsCount) thumbsCount.textContent = '0';
                }

                function revokeBlobUrl() {
                    if (S.currentBlobUrl) {
                        try { URL.revokeObjectURL(S.currentBlobUrl); } catch (_) {}
                        S.currentBlobUrl = null;
                    }
                }

                function parseRangeInput(value, maxPages = 0) {
                    const raw = String(value || '').trim();
                    if (!raw) return null;

                    const pages = [];
                    const parts = raw.split(',').map(p => p.trim()).filter(Boolean);

                    for (const part of parts) {
                        const m = part.match(/^(\d+)\s*-\s*(\d+)$/);
                        if (m) {
                            const a = parseInt(m[1], 10);
                            const b = parseInt(m[2], 10);
                            if (a > 0 && b > 0 && a <= b) {
                                for (let i = a; i <= b; i++) pages.push(i);
                            }
                            continue;
                        }

                        if (/^\d+$/.test(part)) {
                            pages.push(parseInt(part, 10));
                        }
                    }

                    const unique = [...new Set(pages)].filter(p => p > 0 && (!maxPages || p <= maxPages)).sort((a, b) => a - b);
                    return unique.length ? unique : [];
                }

                function visiblePages() {
                    if (Array.isArray(S.activePages) && S.activePages.length) {
                        return S.activePages;
                    }
                    return Array.from({ length: S.pageCount }, (_, i) => i + 1);
                }

                function pageAtActiveIndex() {
                    const pages = visiblePages();
                    if (!pages.length) return null;
                    const idx = Math.max(0, Math.min(S.activeIndex, pages.length - 1));
                    return pages[idx];
                }

                async function renderPage(pageNo) {
                    if (!S.pdfDoc || !pageNo) return;

                    const page = await S.pdfDoc.getPage(pageNo);
                    const viewport = page.getViewport({ scale: S.scale });

                    const canvas = qs('pdfCanvas');
                    const empty = qs('emptyState');

                    if (!canvas) return;

                    const ctx = canvas.getContext('2d');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.style.display = '';
                    if (empty) empty.style.display = 'none';

                    await page.render({
                        canvasContext: ctx,
                        viewport
                    }).promise;

                    const pages = visiblePages();
                    const info = qs('pageInfo');
                    if (info) {
                        info.textContent = `Page ${pageNo} / ${S.pageCount}`;
                        if (pages.length !== S.pageCount) {
                            info.textContent += ` • Preview ${S.activeIndex + 1}/${pages.length}`;
                        }
                    }

                    const prevBtn = qs('prevBtn');
                    const nextBtn = qs('nextBtn');

                    if (prevBtn) prevBtn.disabled = S.activeIndex <= 0;
                    if (nextBtn) nextBtn.disabled = S.activeIndex >= pages.length - 1;

                    document.querySelectorAll('.thumbItem').forEach(item => {
                        item.classList.toggle('active', Number(item.dataset.page) === Number(pageNo));
                    });
                }

                async function renderThumbs() {
                    const thumbsList = qs('thumbsList');
                    const thumbsEmpty = qs('thumbsEmpty');
                    const thumbsCount = qs('thumbsCount');

                    if (!thumbsList || !S.pdfDoc) return;

                    thumbsList.innerHTML = '';
                    if (thumbsEmpty) thumbsEmpty.style.display = 'none';

                    const pages = visiblePages();
                    if (thumbsCount) thumbsCount.textContent = String(pages.length);

                    for (const pageNo of pages) {
                        const page = await S.pdfDoc.getPage(pageNo);
                        const viewport = page.getViewport({ scale: 0.25 });

                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'thumbItem';
                        item.dataset.page = String(pageNo);

                        const c = document.createElement('canvas');
                        c.width = viewport.width;
                        c.height = viewport.height;

                        const ctx = c.getContext('2d');
                        await page.render({ canvasContext: ctx, viewport }).promise;

                        const label = document.createElement('div');
                        label.className = 'thumbLabel';
                        label.textContent = `Page ${pageNo}`;

                        item.appendChild(c);
                        item.appendChild(label);

                        item.addEventListener('click', async () => {
                            const pagesNow = visiblePages();
                            const idx = pagesNow.indexOf(pageNo);
                            S.activeIndex = idx >= 0 ? idx : 0;
                            await renderPage(pageNo);
                        });

                        thumbsList.appendChild(item);
                    }
                }

                async function loadPdfFromArrayBuffer(buffer, fileName = 'document.pdf') {
                    revokeBlobUrl();

                    const blob = new Blob([buffer], { type: 'application/pdf' });
                    S.currentBlobUrl = URL.createObjectURL(blob);

                    S.pdfDoc = await pdfjsLib.getDocument({ data: buffer }).promise;
                    S.pageCount = S.pdfDoc.numPages;
                    S.currentFile = new File([blob], fileName, { type: 'application/pdf' });

                    const rangeInput = qs('rangeInput');
                    const zoomRange = qs('zoomRange');
                    const zoomLabel = qs('zoomLabel');

                    if (rangeInput) rangeInput.disabled = false;
                    if (zoomRange) zoomRange.disabled = false;
                    if (zoomLabel) zoomLabel.textContent = `${Math.round(S.scale * 100)}%`;

                    setControlsEnabled(true);

                    const lw = getOcrComponent();
                    if (lw) {
                        try {
                            lw.set('clientPdfPageCount', S.pageCount);
                        } catch (_) {}
                    }

                    const activeFromInput = parseRangeInput(rangeInput?.value || '', S.pageCount);
                    S.activePages = activeFromInput && activeFromInput.length ? activeFromInput : null;
                    S.activeIndex = 0;

                    updateHint(fileName);
                    const clearBtn = qs('clearBtn');
                    if (clearBtn) clearBtn.disabled = false;
                    await renderThumbs();
                    await renderPage(pageAtActiveIndex());
                    showError('');
                }

                async function loadPdfFromUrl(url, fileName = 'document.pdf', rangeValue = '') {
                    try {
                        const res = await fetch(url, { credentials: 'same-origin' });
                        if (!res.ok) throw new Error(`Failed to load PDF (${res.status})`);
                        const buffer = await res.arrayBuffer();

                        const rangeInput = qs('rangeInput');
                        if (rangeInput) rangeInput.value = rangeValue || '';

                        await loadPdfFromArrayBuffer(buffer, fileName);
                    } catch (e) {
                        showError(e?.message || 'Failed to load PDF.');
                        setEmptyState('Unable to preview the selected PDF.');
                    }
                }

                async function applyRangePreview() {
                    if (!S.pdfDoc) return;

                    const rangeInput = qs('rangeInput');
                    const raw = rangeInput?.value || '';
                    const parsed = parseRangeInput(raw, S.pageCount);

                    const lw = getOcrComponent();
                    if (lw) {
                        try { lw.set('pageRange', raw); } catch (_) {}
                    }

                    const help = qs('rangeHelp');

                    if (raw.trim() !== '' && (!parsed || parsed.length === 0)) {
                        if (help) help.textContent = 'Invalid range';
                        showError('Invalid range. Example: 1-3,5,8-10');
                        return;
                    }

                    if (help) {
                        help.textContent = parsed && parsed.length
                            ? `${parsed.length} page(s) selected`
                            : '';
                    }

                    showError('');
                    S.activePages = parsed && parsed.length ? parsed : null;
                    S.activeIndex = 0;

                    await renderThumbs();
                    await renderPage(pageAtActiveIndex());
                }

                async function clearRangePreview() {
                    const rangeInput = qs('rangeInput');
                    const help = qs('rangeHelp');

                    if (rangeInput) rangeInput.value = '';
                    if (help) help.textContent = '';

                    const lw = getOcrComponent();
                    if (lw) {
                        try { lw.set('pageRange', ''); } catch (_) {}
                    }

                    S.activePages = null;
                    S.activeIndex = 0;

                    if (S.pdfDoc) {
                        await renderThumbs();
                        await renderPage(pageAtActiveIndex());
                    }
                }

                async function downloadCurrentRange() {
                    if (!S.currentFile || !S.pdfDoc) return;

                    const pages = visiblePages();
                    if (!pages.length) return;

                    const srcBytes = await S.currentFile.arrayBuffer();
                    const srcPdf = await PDFDocument.load(srcBytes);
                    const outPdf = await PDFDocument.create();

                    const zeroBased = pages.map(p => p - 1);
                    const copied = await outPdf.copyPages(srcPdf, zeroBased);

                    copied.forEach(p => outPdf.addPage(p));

                    const outBytes = await outPdf.save();
                    const blob = new Blob([outBytes], { type: 'application/pdf' });
                    const url = URL.createObjectURL(blob);

                    const a = document.createElement('a');
                    a.href = url;
                    a.download = pages.length === S.pageCount ? 'full.pdf' : 'range.pdf';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();

                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                }

                function downloadFullPdf() {
                    if (!S.currentBlobUrl) return;

                    const a = document.createElement('a');
                    a.href = S.currentBlobUrl;
                    a.download = S.currentFile?.name || 'document.pdf';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                }

                function clearViewerUi(message = 'Upload a PDF to preview it here.') {
                    S.pdfDoc = null;
                    S.pageCount = 0;
                    S.activePages = null;
                    S.activeIndex = 0;
                    S.currentFile = null;

                    revokeBlobUrl();
                    clearThumbs();
                    setControlsEnabled(false);
                    updateHint('No file selected.');
                    showError('');
                    const clearBtn = qs('clearBtn');
                    if (clearBtn) clearBtn.disabled = true;

                    const pageInfo = qs('pageInfo');
                    if (pageInfo) pageInfo.textContent = 'Page 0 / 0';

                    const zoomRange = qs('zoomRange');
                    const zoomLabel = qs('zoomLabel');
                    const rangeInput = qs('rangeInput');
                    const rangeHelp = qs('rangeHelp');

                    if (zoomRange) {
                        zoomRange.value = '110';
                        zoomRange.disabled = true;
                    }
                    if (zoomLabel) zoomLabel.textContent = '110%';
                    if (rangeInput) {
                        rangeInput.value = '';
                        rangeInput.disabled = true;
                    }
                    if (rangeHelp) rangeHelp.textContent = '';

                    setEmptyState(message);

                    const canvas = qs('pdfCanvas');
                    if (canvas) {
                        const ctx = canvas.getContext('2d');
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                    }

                    const lw = getOcrComponent();
                    if (lw) {
                        try { lw.set('clientPdfPageCount', null); } catch (_) {}
                    }
                }

                async function uploadPdfFile(file) {
                    if (!file) return;
                    if (file.type !== 'application/pdf' && !String(file.name || '').toLowerCase().endsWith('.pdf')) {
                        showError('Only PDF files are allowed.');
                        return;
                    }

                    const lw = getOcrComponent();
                    if (!lw) return;

                    showError('');
                    updateHint(file.name);

                    try {
                        const buffer = await file.arrayBuffer();
                        await loadPdfFromArrayBuffer(buffer, file.name);

                        lw.upload(
                            'documentFile',
                            file,
                            () => {},
                            (e) => {
                                showError(typeof e === 'string' ? e : 'Upload failed');
                            }
                        );
                    } catch (e) {
                        showError(e?.message || 'Failed to open the selected PDF.');
                    }
                }

                async function fetchAndShowText(textUrl) {
                    const textarea = qs('ocr-result-text');
                    if (!textarea) return;

                    if (!textUrl) {
                        textarea.value = '';
                        return;
                    }

                    textarea.value = 'Loading OCR text...';

                    try {
                        const res = await fetch(textUrl, { credentials: 'same-origin' });
                        if (!res.ok) throw new Error(`Failed to load OCR text (${res.status})`);
                        textarea.value = await res.text();
                    } catch (e) {
                        textarea.value = '';
                        showError(e?.message || 'Failed to load OCR text.');
                    }
                }

                async function applyRender(render, { persist = true } = {}) {
                    S.currentRender = render || null;

                    const emptyWrap = qs('ocr-output-empty');
                    const outputWrap = qs('ocr-output-wrap');

                    if (!render) {
                        if (emptyWrap) emptyWrap.classList.remove('d-none');
                        if (outputWrap) outputWrap.classList.add('d-none');
                        clearViewerUi();
                        await fetchAndShowText(null);
                        return;
                    }

                    if (emptyWrap) emptyWrap.classList.add('d-none');
                    if (outputWrap) outputWrap.classList.remove('d-none');

                    await fetchAndShowText(render.text_view_url || null);

                    if (render.input_url) {
                        await loadPdfFromUrl(render.input_url, render.input_name || 'document.pdf', render.page_range || '');
                    }
                }

                function bindStaticEvents() {
                    const dropbox = qs('dropbox');
                    const fileInput = qs('fileInput');
                    const clearBtn = qs('clearBtn');
                    const prevBtn = qs('prevBtn');
                    const nextBtn = qs('nextBtn');
                    const zoomRange = qs('zoomRange');
                    const rangeInput = qs('rangeInput');
                    const applyRangeBtn = qs('applyRangeBtn');
                    const clearRangeBtn = qs('clearRangeBtn');
                    const downloadRangeBtn = qs('downloadRangeBtn');
                    const downloadAllBtn = qs('downloadAllBtn');
                    const copyBtn = qs('ocr-copy-text-btn');

                    if (!S.windowDragBound) {
                        S.windowDragBound = true;
                        window.addEventListener('dragover', (e) => e.preventDefault(), { passive: false });
                        window.addEventListener('drop', (e) => e.preventDefault(), { passive: false });
                    }

                    if (dropbox && !dropbox.dataset.bound) {
                        dropbox.dataset.bound = '1';

                        dropbox.addEventListener('dragenter', () => dropbox.classList.add('is-dragover'));
                        dropbox.addEventListener('dragover', () => dropbox.classList.add('is-dragover'));
                        dropbox.addEventListener('dragleave', () => dropbox.classList.remove('is-dragover'));

                        dropbox.addEventListener('drop', async (e) => {
                            e.preventDefault();
                            dropbox.classList.remove('is-dragover');
                            const file = e.dataTransfer?.files?.[0] || null;
                            await uploadPdfFile(file);
                        });

                        dropbox.addEventListener('click', (e) => {
                            if (e.target?.closest('button, label')) return;
                            fileInput?.click();
                        });

                        dropbox.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                fileInput?.click();
                            }
                        });
                    }

                    if (fileInput && !fileInput.dataset.bound) {
                        fileInput.dataset.bound = '1';
                        fileInput.addEventListener('change', async (e) => {
                            const file = e.target.files?.[0] || null;
                            await uploadPdfFile(file);
                        });
                    }

                    if (clearBtn && !clearBtn.dataset.bound) {
                        clearBtn.dataset.bound = '1';
                        clearBtn.addEventListener('click', () => {
                            const lw = getOcrComponent();
                            if (lw) {
                                lw.call('removeDocumentFile');
                            }
                            if (fileInput) fileInput.value = '';
                            clearViewerUi();
                        });
                    }

                    if (prevBtn && !prevBtn.dataset.bound) {
                        prevBtn.dataset.bound = '1';
                        prevBtn.addEventListener('click', async () => {
                            S.activeIndex = Math.max(0, S.activeIndex - 1);
                            await renderPage(pageAtActiveIndex());
                        });
                    }

                    if (nextBtn && !nextBtn.dataset.bound) {
                        nextBtn.dataset.bound = '1';
                        nextBtn.addEventListener('click', async () => {
                            const pages = visiblePages();
                            S.activeIndex = Math.min(pages.length - 1, S.activeIndex + 1);
                            await renderPage(pageAtActiveIndex());
                        });
                    }

                    if (zoomRange && !zoomRange.dataset.bound) {
                        zoomRange.dataset.bound = '1';
                        zoomRange.addEventListener('input', async () => {
                            const value = parseInt(zoomRange.value || '110', 10);
                            S.scale = value / 100;
                            const zoomLabel = qs('zoomLabel');
                            if (zoomLabel) zoomLabel.textContent = `${value}%`;
                            if (S.pdfDoc) {
                                await renderPage(pageAtActiveIndex());
                            }
                        });
                    }

                    if (applyRangeBtn && !applyRangeBtn.dataset.bound) {
                        applyRangeBtn.dataset.bound = '1';
                        applyRangeBtn.addEventListener('click', applyRangePreview);
                    }

                    if (clearRangeBtn && !clearRangeBtn.dataset.bound) {
                        clearRangeBtn.dataset.bound = '1';
                        clearRangeBtn.addEventListener('click', clearRangePreview);
                    }

                    if (rangeInput && !rangeInput.dataset.bound) {
                        rangeInput.dataset.bound = '1';
                        rangeInput.addEventListener('keydown', async (e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                await applyRangePreview();
                            }
                        });

                        rangeInput.addEventListener('input', () => {
                            const lw = getOcrComponent();
                            if (lw) {
                                try { lw.set('pageRange', rangeInput.value || ''); } catch (_) {}
                            }
                        });
                    }

                    if (downloadRangeBtn && !downloadRangeBtn.dataset.bound) {
                        downloadRangeBtn.dataset.bound = '1';
                        downloadRangeBtn.addEventListener('click', downloadCurrentRange);
                    }

                    if (downloadAllBtn && !downloadAllBtn.dataset.bound) {
                        downloadAllBtn.dataset.bound = '1';
                        downloadAllBtn.addEventListener('click', downloadFullPdf);
                    }

                    if (copyBtn && !copyBtn.dataset.bound) {
                        copyBtn.dataset.bound = '1';
                        copyBtn.addEventListener('click', async () => {
                            const textarea = qs('ocr-result-text');
                            if (!textarea || !textarea.value) return;

                            try {
                                await navigator.clipboard.writeText(textarea.value);
                                window.dispatchEvent(new CustomEvent('alert', {
                                    detail: { type: 'success', message: 'OCR text copied.' }
                                }));
                            } catch (_) {}
                        });
                    }
                }

                function registerLivewireEvents() {
                    if (!window.Livewire || S.eventsBound) return;
                    S.eventsBound = true;

                    Livewire.on('ocr-document-cleared', () => {
                        const input = qs('fileInput');
                        if (input) input.value = '';
                        clearViewerUi();
                    });

                    Livewire.on('ocr-form-reset', () => {
                        const input = qs('fileInput');
                        if (input) input.value = '';
                        clearViewerUi();
                        const textarea = qs('ocr-result-text');
                        if (textarea) textarea.value = '';
                    });

                    Livewire.on('ocr-render-loaded', async (event) => {
                        const render = event?.render || null;
                        await applyRender(render, { persist: false });
                    });

                    Livewire.on('ocr-render-cleared', async () => {
                        await applyRender(null, { persist: false });
                    });

                    Livewire.on('ocr-job-started', (data) => {
                        try {
                            localStorage.setItem('ocr_spa_job_v1', JSON.stringify({ ...data, ts: Date.now() }));
                        } catch (_) {}
                    });

                    Livewire.on('ocr-job-state-sync', (data) => {
                        try {
                            const raw = localStorage.getItem('ocr_spa_job_v1');
                            const old = raw ? JSON.parse(raw) : {};
                            localStorage.setItem('ocr_spa_job_v1', JSON.stringify({ ...old, ...data, ts: Date.now() }));
                        } catch (_) {}
                    });

                    Livewire.on('ocr-job-state-clear', () => {
                        try { localStorage.removeItem('ocr_spa_job_v1'); } catch (_) {}
                    });

                    Livewire.on('ocr-job-completed', async (event) => {
                        try { localStorage.removeItem('ocr_spa_job_v1'); } catch (_) {}
                        const render = event?.render || null;
                        if (render) {
                            await applyRender(render, { persist: false });
                        }
                    });

                    if (!S.commitHooked && typeof Livewire.hook === 'function') {
                        S.commitHooked = true;

                        Livewire.hook('commit', ({ succeed }) => {
                            succeed(() => {
                                requestAnimationFrame(() => {
                                    bindStaticEvents();
                                });
                            });
                        });
                    }
                }

                async function boot() {
                    if (S.bootTimer) {
                        clearTimeout(S.bootTimer);
                    }

                    S.bootTimer = setTimeout(async () => {
                        S.bootTimer = null;

                        bindStaticEvents();
                        registerLivewireEvents();

                        const initial = S.currentRender;
                        if (initial && initial.id) {
                            await applyRender(initial, { persist: false });
                        } else {
                            clearViewerUi();
                        }
                    }, 0);
                }

                if (!S.listenersBound) {
                    S.listenersBound = true;

                    document.addEventListener('livewire:initialized', boot);
                    document.addEventListener('livewire:navigated', boot);
                    document.addEventListener('livewire:navigating', revokeBlobUrl);
                }

                boot();
            })();
        </script>
    @endpush

    @push('scripts')
        <script>
            function ocrFormCache() {
                return {
                    cacheKey: 'ocr_form_state_v1',

                    lang: @entangle('lang').live,
                    pageRange: @entangle('pageRange').live,
                    dpi: @entangle('dpi').live,
                    psm: @entangle('psm').live,
                    oem: @entangle('oem').live,
                    normalize: @entangle('normalize').live,
                    grayscale: @entangle('grayscale').live,
                    autocontrast: @entangle('autocontrast').live,
                    sharpen: @entangle('sharpen').live,
                    binarize: @entangle('binarize').live,

                    init() {
                        this.restore();

                        this.$watch('lang', () => this.save());
                        this.$watch('pageRange', () => this.save());
                        this.$watch('dpi', () => this.save());
                        this.$watch('psm', () => this.save());
                        this.$watch('oem', () => this.save());
                        this.$watch('normalize', () => this.save());
                        this.$watch('grayscale', () => this.save());
                        this.$watch('autocontrast', () => this.save());
                        this.$watch('sharpen', () => this.save());
                        this.$watch('binarize', () => this.save());

                        window.addEventListener('beforeunload', () => this.save());
                        window.addEventListener('ocr-form-state-clear', () => this.clear());
                    },

                    save() {
                        try {
                            localStorage.setItem(this.cacheKey, JSON.stringify({
                                lang: this.lang ?? 'ckb',
                                pageRange: this.pageRange ?? '',
                                dpi: this.dpi ?? 200,
                                psm: this.psm ?? 6,
                                oem: this.oem ?? 3,
                                normalize: !!this.normalize,
                                grayscale: !!this.grayscale,
                                autocontrast: !!this.autocontrast,
                                sharpen: !!this.sharpen,
                                binarize: !!this.binarize,
                                ts: Date.now(),
                            }));
                        } catch (_) {}
                    },

                    restore() {
                        try {
                            const raw = localStorage.getItem(this.cacheKey);
                            if (!raw) return;

                            const data = JSON.parse(raw);
                            if (!data) return;

                            if (Date.now() - (data.ts || 0) > 7 * 24 * 60 * 60 * 1000) {
                                localStorage.removeItem(this.cacheKey);
                                return;
                            }

                            if (data.lang !== undefined) this.lang = data.lang;
                            if (data.pageRange !== undefined) this.pageRange = data.pageRange;
                            if (data.dpi !== undefined) this.dpi = data.dpi;
                            if (data.psm !== undefined) this.psm = data.psm;
                            if (data.oem !== undefined) this.oem = data.oem;
                            if (data.normalize !== undefined) this.normalize = !!data.normalize;
                            if (data.grayscale !== undefined) this.grayscale = !!data.grayscale;
                            if (data.autocontrast !== undefined) this.autocontrast = !!data.autocontrast;
                            if (data.sharpen !== undefined) this.sharpen = !!data.sharpen;
                            if (data.binarize !== undefined) this.binarize = !!data.binarize;
                        } catch (_) {}
                    },

                    clear() {
                        try {
                            localStorage.removeItem(this.cacheKey);
                        } catch (_) {}
                    }
                };
            }
        </script>
    @endpush
@endonce
