<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Enums\PaymentPurposeType;
use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\Billing\ScheduleStoragePlanCancellation;
use App\Services\Payments\PaymentMethodCatalog;
use App\Support\StorageBrowser;
use Illuminate\Support\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new
#[Layout('app::layouts.app')]
class extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'path', keep: true)]
    public string $path = '';

    public int $perPage = 12;

    public int $folderBatchSize = 20;

    public int $folderVisibleCount = 20;

    public ?string $selectedFile = null;

    public ?string $pendingDeleteType = null; // file|folder
    public ?string $pendingDeletePath = null;
    public ?string $pendingDeleteLabel = null;

    public array $toolRoots = [];

    public array $storagePlans = [];

    public array $storagePaymentMethods = [];

    public ?int $currentStoragePlanId = null;

    public ?int $selectedStoragePlanId = null;

    public ?string $selectedStoragePaymentMethod = null;

    public string $storageBillingCycle = 'monthly';

    public string $storageDisplayCurrencyCode = 'IQD';

    public string $storageDisplayCurrencySource = 'default';

    public string $currentStoragePlanCode = 'free-512';

    public string $currentStoragePlanName = 'Free (512MB)';

    public bool $storageHourlyTestingEnabled = false;

    public bool $showStoragePlanConfirm = false;

    public bool $showStorageCancelConfirm = false;

    public bool $processingStoragePlan = false;

    public bool $canCancelCurrentStoragePlan = false;

    public bool $currentStoragePlanCancellationScheduled = false;

    public bool $storageOverQuota = false;

    public bool $projectedStorageOverQuotaAfterDowngrade = false;

    public int $storageFutureQuotaMb = 512;

    public ?string $currentStoragePlanEndsAtLabel = null;

    public string $storagePlanMessage = '';

    public string $storagePlanMessageType = 'info';

    public function mount(): void
    {
        $this->toolRoots = [
            'tts'       => ['label' => __('Text to Speech'), 'icon' => 'ri-volume-up-line', 'color' => 'primary'],
            'xomni'     => ['label' => __('Apollo 1.5v'), 'icon' => 'ri-volume-up-line', 'color' => 'primary'],
            'ftts'      => ['label' => __('F5 Text to Speech'), 'icon' => 'ri-volume-up-line', 'color' => 'secondary'],
            'clone-tts' => ['label' => __('Clone Speech'), 'icon' => 'ri-mic-line', 'color' => 'success'],
            'clone_xomni' => ['label' => __('Vector 1.5v'), 'icon' => 'ri-mic-line', 'color' => 'success'],
            'stem'      => ['label' => __('Stem Separation'), 'icon' => 'ri-equalizer-line', 'color' => 'info'],
            'wasr'      => ['label' => __('Speech to Text'), 'icon' => 'ri-file-text-line', 'color' => 'warning'],
            'qasr'      => ['label' => __('QASR Speech to Text'), 'icon' => 'ri-file-text-line', 'color' => 'danger'],
            'caption'   => ['label' => __('Caption'), 'icon' => 'ri-file-list-3-line', 'color' => 'info'],
            'tran'      => ['label' => __('MET Translation'), 'icon' => 'ri-translate-2', 'color' => 'primary'],
            'ocr'       => ['label' => __('Optical Character Recognition'), 'icon' => 'ri-scan-2-line', 'color' => 'secondary'],
        ];

        $this->path = $this->sanitizePath($this->path);

        if ($this->path !== '' && !$this->pathExists($this->path)) {
            $this->path = '';
        }

        $this->loadStorageBillingData();
        $this->syncSelectedFile();
    }

    protected function loadStorageBillingData(): void
    {
        $customer = auth('app')->user();
        $customer = $customer?->fresh(['profile', 'usage']);

        if (! $customer) {
            return;
        }

        $currency = app(BillingCurrencyService::class);
        $billingState = app(CustomerBillingStateService::class);
        $methodCatalog = app(PaymentMethodCatalog::class);
        $fibSubscriptions = app(FibSubscriptionService::class);
        $state = $billingState->storageQuotaState($customer);
        $displayContext = $currency->resolveDisplayContext($customer);

        $this->storageDisplayCurrencyCode = (string) ($displayContext['currency_code'] ?? 'IQD');
        $this->storageDisplayCurrencySource = (string) ($displayContext['source'] ?? 'default');
        $this->storageHourlyTestingEnabled = $fibSubscriptions->hourlyTestingEnabled();
        $this->storageBillingCycle = $this->normalizeStorageBillingCycle($this->storageBillingCycle);
        $this->syncCurrentStoragePlanState($state);

        $this->storagePlans = StoragePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (StoragePlan $plan) use ($currency, $customer) {
                $priceIqd = $plan->priceIqdAmount();
                $isCurrent = (int) $plan->id === (int) $this->currentStoragePlanId;

                return [
                    'id' => (int) $plan->id,
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'payment_mode' => $plan->checkoutPaymentModeValue(),
                    'quota_mb' => (int) ($plan->quota_mb ?? 0),
                    'price_iqd' => $priceIqd,
                    'price_display' => $currency->priceDataForBaseAmountIqd($priceIqd, $customer),
                    'is_current' => $isCurrent,
                    'can_cancel' => $isCurrent && $this->canCancelCurrentStoragePlan,
                    'cancellation_scheduled' => $isCurrent && $this->currentStoragePlanCancellationScheduled,
                    'access_until_label' => $isCurrent ? $this->currentStoragePlanEndsAtLabel : null,
                ];
            })
            ->values()
            ->all();

        $this->storagePaymentMethods = $methodCatalog
            ->availableForPurpose(PaymentPurposeType::STORAGE_PLAN, 'IQD')
            ->map(fn ($method) => [
                'code' => (string) $method->code,
                'driver' => (string) $method->driver,
                'name' => (string) $method->name,
                'description' => filled($method->description) ? (string) $method->description : null,
                'supports_recurring' => (bool) ($method->supports_recurring ?? false),
            ])
            ->values()
            ->all();

        $selectedMethod = collect($this->storagePaymentMethods)
            ->firstWhere('code', strtolower(trim((string) $this->selectedStoragePaymentMethod)));

        $this->selectedStoragePaymentMethod = (string) (
            data_get($selectedMethod, 'code')
            ?: data_get($this->storagePaymentMethods, '0.code')
            ?: 'fib'
        );
    }

    protected function syncCurrentStoragePlanState(array $state): void
    {
        $currentPlan = $state['current_plan'] ?? null;

        $this->currentStoragePlanId = (int) ($state['current_plan_id'] ?? 0) ?: null;
        $this->currentStoragePlanCode = (string) ($currentPlan?->code ?? 'free-512');
        $this->currentStoragePlanName = (string) ($currentPlan?->name ?? __('Free (512MB)'));
        $this->canCancelCurrentStoragePlan = (bool) ($state['cancelable'] ?? false);
        $this->currentStoragePlanCancellationScheduled = (bool) ($state['cancellation_scheduled'] ?? false);
        $this->storageOverQuota = (bool) ($state['over_quota'] ?? false);
        $this->projectedStorageOverQuotaAfterDowngrade = (bool) ($state['projected_over_quota_after_downgrade'] ?? false);
        $this->storageFutureQuotaMb = (int) ($state['future_limit_mb'] ?? 512);
        $this->currentStoragePlanEndsAtLabel = $this->formatStorageDateLabel($state['period_ends_at'] ?? null);
    }

    public function updatingSearch(): void
    {
        $this->resetFolderListWindow();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->syncSelectedFile();
    }

    public function updatedPath(): void
    {
        $this->path = $this->sanitizePath($this->path);
        $this->resetFolderListWindow();
        $this->resetPage();
        $this->syncSelectedFile();
    }

    public function navigateTo(string $path = ''): void
    {
        $path = $this->sanitizePath($path);

        if ($path !== '' && !$this->pathExists($path)) {
            return;
        }

        $this->path = $path;
        $this->resetFolderListWindow();
        $this->resetPage();
        $this->syncSelectedFile();
    }

    public function loadMoreFolders(): void
    {
        $this->folderVisibleCount += max(1, $this->folderBatchSize);
    }

    public function openStorageConfirm(int $planId): void
    {
        if ($this->processingStoragePlan) {
            return;
        }

        $this->selectedStoragePlanId = $planId;
        $this->storagePlanMessage = '';
        $this->storagePlanMessageType = 'info';
        $this->showStoragePlanConfirm = true;
    }

    public function closeStorageConfirm(): void
    {
        if ($this->processingStoragePlan) {
            return;
        }

        $this->showStoragePlanConfirm = false;
        $this->selectedStoragePlanId = null;
    }

    public function openStorageCancelConfirm(): void
    {
        if ($this->processingStoragePlan || ! $this->canCancelCurrentStoragePlan) {
            return;
        }

        $this->storagePlanMessage = '';
        $this->storagePlanMessageType = 'info';
        $this->showStorageCancelConfirm = true;
    }

    public function closeStorageCancelConfirm(): void
    {
        if ($this->processingStoragePlan) {
            return;
        }

        $this->showStorageCancelConfirm = false;
    }

    public function confirmStoragePlanChange()
    {
        if ($this->processingStoragePlan) {
            return null;
        }

        $customer = auth('app')->user();
        $selectedPlan = collect($this->storagePlans)->firstWhere('id', $this->selectedStoragePlanId);

        if (! $customer) {
            return null;
        }

        if (! $this->selectedStoragePlanId) {
            return null;
        }

        if (! $selectedPlan) {
            $this->storagePlanMessageType = 'danger';
            $this->storagePlanMessage = __('Selected storage plan was not found.');

            return null;
        }

        if ((int) $this->selectedStoragePlanId === (int) $this->currentStoragePlanId) {
            $this->storagePlanMessageType = 'info';
            $this->storagePlanMessage = __('This is already your current storage plan.');

            return null;
        }

        $selectedMethod = collect($this->storagePaymentMethods)
            ->firstWhere('code', strtolower(trim((string) $this->selectedStoragePaymentMethod)));
        $selectedDriver = strtolower(trim((string) data_get($selectedMethod, 'driver')));
        $selectedSupportsRecurring = (bool) data_get($selectedMethod, 'supports_recurring', false);
        $selectedPlanMode = strtolower((string) data_get($selectedPlan, 'payment_mode', 'recurring'));
        $selectedUsesRecurring = $selectedPlanMode === 'recurring';

        if ($selectedDriver === '') {
            $this->storagePlanMessageType = 'danger';
            $this->storagePlanMessage = $selectedUsesRecurring
                ? __('No recurring payment method is currently available for storage plans.')
                : __('No manual payment method is currently available for storage plans.');

            return null;
        }

        if ($selectedUsesRecurring && ! $selectedSupportsRecurring) {
            $this->storagePlanMessageType = 'warning';
            $this->storagePlanMessage = __('The selected payment method does not support recurring storage subscriptions.');

            return null;
        }

        if ($selectedDriver !== 'fib') {
            $this->storagePlanMessageType = 'warning';
            $this->storagePlanMessage = $selectedUsesRecurring
                ? __('The selected payment method is not enabled yet for recurring storage subscriptions. Please choose FIB for now.')
                : __('The selected payment method is not enabled yet for manual storage payment. Please choose FIB for now.');

            return null;
        }

        $this->processingStoragePlan = true;
        $this->storagePlanMessage = '';
        $this->storagePlanMessageType = 'info';

        try {
            $payment = app(CreateStorageSubscriptionPayment::class)->handle(
                $customer,
                (int) $this->selectedStoragePlanId,
                $this->storageBillingCycle,
                null,
                $this->selectedStoragePaymentMethod,
            );

            $this->showStoragePlanConfirm = false;

            return $this->redirectRoute('payments.fib.show', [
                'locale' => app()->getLocale(),
                'payment' => $payment,
            ], navigate: true);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->storagePlanMessageType = 'danger';
            $this->storagePlanMessage = collect($exception->errors())->flatten()->first() ?: __('Could not start the storage subscription checkout.');
        } catch (\Throwable $exception) {
            Log::error('Failed to start storage checkout from my-storage page.', [
                'customer_id' => (int) ($customer?->id ?? 0),
                'storage_plan_id' => (int) $this->selectedStoragePlanId,
                'payment_method' => (string) $this->selectedStoragePaymentMethod,
                'billing_cycle' => (string) $this->storageBillingCycle,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $this->storagePlanMessageType = 'danger';
            $this->storagePlanMessage = __('Failed to start the storage checkout right now. Please try again shortly.');
        } finally {
            $this->processingStoragePlan = false;
        }

        return null;
    }

    public function confirmStoragePlanCancel(): void
    {
        if ($this->processingStoragePlan) {
            return;
        }

        $customer = auth('app')->user();

        if (! $customer) {
            return;
        }

        $this->processingStoragePlan = true;
        $this->storagePlanMessage = '';

        try {
            app(ScheduleStoragePlanCancellation::class)->handle($customer);

            $this->closeStorageCancelConfirm();
            $this->loadStorageBillingData();

            $this->storagePlanMessageType = 'success';
            $this->storagePlanMessage = __('Cancellation scheduled. Your storage plan remains active until :date.', [
                'date' => $this->currentStoragePlanEndsAtLabel ?: __('the end of the current billing period'),
            ]);

            if ($this->projectedStorageOverQuotaAfterDowngrade) {
                $this->storagePlanMessage .= ' ' . __('After the downgrade, uploads and storage-growing actions will stay blocked until you delete files or upgrade again.');
            }
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->storagePlanMessageType = 'danger';
            $this->storagePlanMessage = collect($exception->errors())->flatten()->first() ?: __('Could not schedule the storage cancellation.');
        } catch (\Throwable $exception) {
            $this->storagePlanMessageType = 'danger';
            $this->storagePlanMessage = __('Failed to schedule the storage cancellation: :message', ['message' => $exception->getMessage()]);
        } finally {
            $this->processingStoragePlan = false;
        }
    }

    public function selectFile(string $relativePath): void
    {
        $file = $this->findFileByRelativePath($relativePath);

        if (!$file || !$this->isPreviewable($file['mime'], $file['extension'])) {
            return;
        }

        $this->selectedFile = $file['relative_path'];
    }

    public function confirmDeleteFile(string $relativePath): void
    {
        $file = $this->findFileByRelativePath($relativePath);

        if (!$file) {
            return;
        }

        $deletePrefix = $this->resolveDeletePrefixForFile($relativePath);
        $isCoupled = $deletePrefix !== $relativePath;

        $this->pendingDeleteType = 'file';
        $this->pendingDeletePath = $relativePath;
        $this->pendingDeleteLabel = $isCoupled
            ? basename($deletePrefix) . ' (linked assets)'
            : basename($relativePath);

        $this->dispatch('storage-delete-modal-open');
    }

    public function confirmDeleteFolder(string $folderPath): void
    {
        $folderPath = $this->sanitizePath($folderPath);

        if ($folderPath === '' || !$this->pathExists($folderPath)) {
            return;
        }

        $this->pendingDeleteType = 'folder';
        $this->pendingDeletePath = $folderPath;
        $this->pendingDeleteLabel = basename($folderPath);

        $this->dispatch('storage-delete-modal-open');
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteType = null;
        $this->pendingDeletePath = null;
        $this->pendingDeleteLabel = null;

        $this->dispatch('storage-delete-modal-close');
    }

    public function deleteConfirmed(): void
    {
        $customer = auth('app')->user();

        abort_unless($customer, 403);

        if (!$this->pendingDeleteType || !$this->pendingDeletePath) {
            return;
        }

        try {
            $browser = app(StorageBrowser::class);

            DB::transaction(function () use ($customer, $browser) {
                $prefix = $this->pendingDeleteType === 'folder'
                    ? $this->pendingDeletePath
                    : $this->resolveDeletePrefixForFile($this->pendingDeletePath);

                $targets = $browser->deleteTargetsByPrefix($customer, $prefix);

                if ($targets->isEmpty()) {
                    return;
                }

                foreach ($targets as $file) {
                    $disk = (string) ($file['disk'] ?: 's3');
                    $path = (string) $file['path'];

                    if ($path !== '' && Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                    }

                    CustomerFile::query()
                        ->where('id', $file['id'])
                        ->update([
                            'status'     => 'deleted',
                            'deleted_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                $this->recalculateUsage((int) $customer->id);

                $jobId = $this->extractJobIdFromRelativePrefix($prefix);
                if ($jobId) {
                    MlJob::query()
                        ->where('customer_id', (int) $customer->id)
                        ->where('id', $jobId)
                        ->update([
                            'status'            => 'deleted',
                            'output'            => null,
                            'storage_in_bytes'  => 0,
                            'storage_out_bytes' => 0,
                            'error'             => null,
                            'updated_at'        => now(),
                        ]);
                }
            }, 3);

            if ($this->path !== '' && !$this->pathExists($this->path)) {
                $this->path = $this->parentPath($this->path);
            }

            $this->syncSelectedFile();

            $this->dispatch('alert', type: 'success', message: __('Storage item deleted successfully.'));
        } catch (\Throwable $e) {
            Log::error('APP_STORAGE_DELETE_FAILED', [
                'customer_id' => auth('app')->id(),
                'type'        => $this->pendingDeleteType,
                'path'        => $this->pendingDeletePath,
                'message'     => $e->getMessage(),
            ]);

            $this->dispatch('alert', type: 'error', message: __('Delete failed. Please try again.'));
        } finally {
            $this->cancelDelete();
            $this->resetFolderListWindow();
            $this->resetPage();
        }
    }

    #[Computed]
    public function usage(): array
    {
        $customer = auth('app')->user();
        $state = method_exists($customer, 'storageQuotaState')
            ? $customer->storageQuotaState()
            : [];

        $usage = CustomerUsage::query()
            ->firstOrCreate(
                ['customer_id' => (int) $customer->id],
                [
                    'storage_used_bytes' => 0,
                    'jobs_total'         => 0,
                    'jobs_succeeded'     => 0,
                    'jobs_failed'        => 0,
                ]
            );

        $used = (int) $usage->storage_used_bytes;
        $limitBytes = (int) ($state['current_limit_bytes'] ?? (512 * 1024 * 1024));

        $percent = $limitBytes > 0
            ? min(100, (int) round(($used / $limitBytes) * 100))
            : 0;

        return [
            'used_bytes'  => $used,
            'limit_bytes' => $limitBytes,
            'percent'     => $percent,
        ];
    }

    #[Computed]
    public function toolStats(): array
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->toolStats($customer, $this->toolRoots);
    }

    #[Computed]
    public function storageSegments(): array
    {
        $toolStats = $this->toolStats();
        $usedBytes = max(0, (int) $this->usage()['used_bytes']);

        $segments = collect($this->toolRoots)
            ->map(function (array $cfg, string $tool) use ($toolStats, $usedBytes) {
                $stat = $toolStats[$tool] ?? ['folder_count' => 0, 'file_count' => 0, 'size' => 0];
                $size = (int) $stat['size'];

                if ($size <= 0) {
                    return null;
                }

                return [
                    'tool'            => $tool,
                    'label'           => $cfg['label'],
                    'color'           => $cfg['color'],
                    'folder_count'    => (int) $stat['folder_count'],
                    'file_count'      => (int) $stat['file_count'],
                    'size'            => $size,
                    'percent_of_used' => $usedBytes > 0 ? round(($size / $usedBytes) * 100, 2) : 0,
                ];
            })
            ->filter()
            ->sortByDesc('size')
            ->values();

        return $segments->all();
    }

    #[Computed]
    public function folderCards(): Collection
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->folderCards(
            $customer,
            $this->toolRoots,
            $this->path,
            $this->search,
            $this->toolStats()
        );
    }

    #[Computed]
    public function visibleFolderCards(): Collection
    {
        $limit = max(1, max($this->folderBatchSize, $this->folderVisibleCount));

        return $this->folderCards()
            ->take($limit)
            ->values();
    }

    #[Computed]
    public function hasMoreFolderCards(): bool
    {
        return $this->folderCards()->count() > $this->visibleFolderCards()->count();
    }

    #[Computed]
    public function currentFolderFiles(): Collection
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->currentFolderFiles($customer, $this->path, $this->search);
    }

    #[Computed]
    public function pagedFiles(): Collection
    {
        return $this->filesPaginator()->getCollection();
    }

    #[Computed]
    public function filesPaginator(): LengthAwarePaginator
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->filesPaginator(
            $customer,
            $this->path,
            $this->search,
            $this->getPage(),
            $this->perPage
        );
    }

    #[Computed]
    public function breadcrumbs(): array
    {
        $crumbs = [
            ['label' => __('My Storage'), 'path' => ''],
        ];

        if ($this->path === '') {
            return $crumbs;
        }

        $parts = array_values(array_filter(explode('/', $this->path)));
        $built = '';

        foreach ($parts as $part) {
            $built = trim($built . '/' . $part, '/');
            $crumbs[] = [
                'label' => $this->toolRoots[$part]['label'] ?? $part,
                'path'  => $built,
            ];
        }

        return $crumbs;
    }

    #[Computed]
    public function selectedFileData(): ?array
    {
        if (!$this->selectedFile) {
            return null;
        }

        $file = $this->findFileByRelativePath($this->selectedFile);

        if (!$file || !$this->isPreviewable($file['mime'], $file['extension'])) {
            return null;
        }

        $file['download_url'] = $this->temporaryUrlFor($file, 'attachment');
        $file['stream_url'] = $this->temporaryUrlFor($file, 'inline');
        $file['is_audio'] = $this->isAudio($file['mime'], $file['extension']);
        $file['icon'] = $this->fileIcon($file['mime'], $file['extension']);
        $file['type_label'] = $this->typeLabel($file['mime'], $file['extension']);

        return $file;
    }

    #[Computed]
    public function overviewStats(): array
    {
        $customer = auth('app')->user();

        return app(StorageBrowser::class)->overviewStats($customer);
    }

    protected function sanitizePath(?string $path): string
    {
        $path = trim((string) $path);
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = trim((string) $path, '/');

        $parts = array_values(array_filter(explode('/', $path), fn ($part) => $part !== '' && $part !== '.' && $part !== '..'));

        return implode('/', $parts);
    }

    protected function syncSelectedFile(): void
    {
        $visibleFiles = $this->currentFolderFiles();

        if ($visibleFiles->isEmpty()) {
            $this->selectedFile = null;

            return;
        }

        $selectedVisible = $this->selectedFile
            ? $visibleFiles->first(fn (array $file) => $file['relative_path'] === $this->selectedFile)
            : null;

        if ($selectedVisible && $this->isPreviewable($selectedVisible['mime'], $selectedVisible['extension'])) {
            return;
        }

        $fallback = $visibleFiles->first(
            fn (array $file) => $this->isPreviewable($file['mime'], $file['extension'])
        );

        $this->selectedFile = $fallback['relative_path'] ?? null;
    }

    protected function resetFolderListWindow(): void
    {
        $this->folderVisibleCount = max(1, $this->folderBatchSize);
    }

    protected function pathExists(string $path): bool
    {
        $path = $this->sanitizePath($path);

        return app(StorageBrowser::class)->pathExists(auth('app')->user(), $path);
    }

    protected function parentPath(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $this->sanitizePath($path))));
        array_pop($parts);

        return implode('/', $parts);
    }

    protected function findFileByRelativePath(string $relativePath): ?array
    {
        return app(StorageBrowser::class)->findFileByRelativePath(
            auth('app')->user(),
            $this->sanitizePath($relativePath)
        );
    }

    protected function resolveDeletePrefixForFile(string $relativePath): string
    {
        $relativePath = $this->sanitizePath($relativePath);
        $parts = explode('/', $relativePath);

        if (count($parts) >= 3 && array_key_exists($parts[0], $this->toolRoots)) {
            // Delete the whole job directory:
            // tts/{jobId}/...
            // clone-tts/{jobId}/...
            // xomni/{jobId}/...
            // clone_xomni/{jobId}/...
            // stem/{jobId}/...
            // wasr/{jobId}/...
            // ocr/{jobId}/...
            return $parts[0] . '/' . $parts[1];
        }

        return $relativePath;
    }

    protected function extractJobIdFromRelativePrefix(string $prefix): ?string
    {
        $parts = explode('/', $this->sanitizePath($prefix));

        if (count($parts) >= 2 && array_key_exists($parts[0], $this->toolRoots)) {
            return $parts[1];
        }

        return null;
    }

    protected function recalculateUsage(int $customerId): void
    {
        $bytes = (int) CustomerFile::query()
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->sum('size_bytes');

        CustomerUsage::query()->updateOrCreate(
            ['customer_id' => $customerId],
            ['storage_used_bytes' => $bytes]
        );
    }

    protected function temporaryUrlFor(array $file, string $disposition = 'attachment'): string
    {
        $disk = (string) ($file['disk'] ?: 's3');
        $path = (string) $file['path'];
        $filename = basename($path) ?: $file['basename'];

        if ($path === '') {
            return '#';
        }

        if (method_exists(Storage::disk($disk), 'temporaryUrl')) {
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(30), [
                'ResponseContentDisposition' => ($disposition === 'inline' ? 'inline' : 'attachment') . '; filename="' . $filename . '"',
                'ResponseContentType' => $file['mime'] ?: 'application/octet-stream',
            ]);
        }

        return Storage::disk($disk)->url($path);
    }

    protected function isAudio(?string $mime, ?string $ext): bool
    {
        $mime = (string) $mime;
        $ext = strtolower((string) $ext);

        return Str::startsWith($mime, 'audio/')
            || in_array($ext, ['mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'opus'], true);
    }

    protected function isJsonFile(?string $mime, ?string $ext): bool
    {
        return strtolower((string) $ext) === 'json'
            || Str::contains((string) $mime, 'json');
    }

    protected function isPreviewable(?string $mime, ?string $ext): bool
    {
        return !$this->isJsonFile($mime, $ext);
    }

    protected function fileIcon(?string $mime, ?string $ext): string
    {
        $ext = strtolower((string) $ext);

        if ($this->isAudio($mime, $ext)) {
            return 'ri-volume-up-fill text-success';
        }

        return match ($ext) {
            'txt'   => 'ri-file-text-fill text-secondary',
            'json'  => 'ri-code-s-slash-fill text-info',
            'pdf'   => 'ri-file-pdf-fill text-danger',
            default => 'ri-file-fill text-primary',
        };
    }

    protected function typeLabel(?string $mime, ?string $ext): string
    {
        $ext = strtolower((string) $ext);

        if ($this->isAudio($mime, $ext)) {
            return __('Audio');
        }

        return match ($ext) {
            'txt', 'pdf', 'doc', 'docx' => __('Document'),
            'json' => __('JSON'),
            default => __('File'),
        };
    }

    public function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return number_format($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }

    protected function normalizeStorageBillingCycle(?string $cycle): string
    {
        $cycle = strtolower(trim((string) $cycle));
        $allowed = ['monthly'];

        if ($this->storageHourlyTestingEnabled) {
            $allowed[] = 'hourly';
        }

        return in_array($cycle, $allowed, true) ? $cycle : 'monthly';
    }

    protected function formatStorageDateLabel(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date)->timezone(config('app.timezone'))->format('Y-m-d H:i');
        }

        if (is_scalar($date) && trim((string) $date) !== '') {
            try {
                return Carbon::parse((string) $date)
                    ->timezone(config('app.timezone'))
                    ->format('Y-m-d H:i');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public function render()
    {
        return view('app.pages.my-storage.⚡app-storage');
    }
};
?>

<div>
    <style>
        .storage-shell {
            --storage-radius: 1rem;
        }

        .storage-shell .chat-wrapper {
            align-items: stretch;
        }

        .storage-shell .card,
        .storage-shell .modal-content {
            border-radius: var(--storage-radius);
        }

        .storage-shell .file-manager-sidebar,
        .storage-shell .file-manager-content,
        .storage-shell .file-manager-detail-content {
            background: var(--vz-secondary-bg, var(--bs-body-bg));
            border-radius: var(--storage-radius);
            min-height: calc(100vh - 20px);
        }



        .storage-shell .file-manager-content {
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
        }

        .storage-shell .file-manager-detail-content {
            width: 360px;
            flex: 0 0 360px;
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
        }

        .storage-shell .folder-tile {
            cursor: pointer;
            transition: .2s ease;
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
        }

        .storage-shell .folder-tile .folder-tile-trigger:focus-visible {
            outline: 2px solid rgba(var(--bs-primary-rgb), .45);
            outline-offset: 2px;
            border-radius: .75rem;
        }

        .storage-shell .folder-tile:hover,
        .storage-shell .folder-tile.active {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, .08);
            border-color: rgba(var(--bs-primary-rgb), .35);
        }

        .storage-shell .file-row {
            cursor: default;
        }

        .storage-shell .file-row.table-active {
            --bs-table-bg: rgba(var(--bs-primary-rgb), .08);
        }

        .storage-shell .sticky-pane {
            position: sticky;
            top: 1rem;
        }

        .storage-shell .audio-preview {
            border: 1px dashed var(--vz-border-color, var(--bs-border-color));
            border-radius: 1rem;
            padding: 1rem;
            background: rgba(var(--bs-secondary-rgb), .05);
        }

        .storage-shell .storage-status-chart {
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
            border-radius: 1rem;
            padding: .875rem;
            background: rgba(var(--bs-secondary-rgb), .04);
        }

        .storage-shell .storage-status-bar {
            height: .85rem;
            border-radius: 999px;
            overflow: hidden;
            background: rgba(var(--bs-secondary-rgb), .12);
            display: flex;
        }

        .storage-shell .storage-status-used {
            height: 100%;
            display: flex;
            overflow: hidden;
            border-radius: inherit;
        }

        .storage-shell .storage-status-segment {
            height: 100%;
        }

        .storage-shell .storage-status-free {
            flex: 1 1 auto;
            background: rgba(var(--bs-secondary-rgb), .08);
        }

        .storage-shell .storage-status-legend {
            display: grid;
            gap: .5rem;
        }

        .storage-shell .storage-status-item {
            display: flex;
            align-items: flex-start;
            gap: .625rem;
        }

        .storage-shell .storage-status-swatch {
            width: .75rem;
            height: .75rem;
            border-radius: 999px;
            margin-top: .2rem;
            flex: 0 0 auto;
        }

        .storage-shell .file-row-selectable {
            cursor: pointer;
        }

        @media (max-width: 1199.98px) {
            .storage-shell .file-manager-detail-content {
                width: 100%;
                flex: 1 1 100%;
            }
        }

        @media (max-width: 991.98px) {
            .storage-shell .chat-wrapper {
                flex-direction: column;
            }

            .storage-shell .file-manager-sidebar,
            .storage-shell .file-manager-detail-content {
                width: 100%;
                flex: 1 1 100%;
            }

            .storage-shell .file-manager-sidebar {
                height: auto;
                max-height: none;
                overflow: visible;
            }

            .storage-shell .file-manager-sidebar > .p-3 {
                overflow: visible;
            }
        }
    </style>

    @php
        $usage = $this->usage();
        $toolStats = $this->toolStats();
        $storageSegments = $this->storageSegments();
        $folderCards = $this->visibleFolderCards();
        $folderCardsTotal = $this->folderCards()->count();
        $hasMoreFolderCards = $this->hasMoreFolderCards();
        $filesPaginator = $this->filesPaginator();
        $pagedFiles = $filesPaginator->getCollection();
        $selectedPreview = $this->selectedFileData();
        $overviewStats = $this->overviewStats();
    @endphp

    <div class="storage-shell">
        {{-- <div class="page-content"> --}}
            <x-slot:title>{{ __('My Storage') }} | {{ __('MET KURD') }}</x-slot:title>

            <div class="container-fluid">

                <div class="chat-wrapper d-lg-flex gap-3 mx-n4 mt-n4 p-3">
                    <!-- Sidebar -->
                    
                    <div class="file-manager-sidebar">
                        <div class="p-3 d-flex flex-column h-100">
                            <div class="mb-3">
                                <h5 class="mb-0 fw-bold">{{ __('My Storage') }}</h5>
                                <div class="text-muted small mt-1">{{ __('Manage your generated AI assets') }}</div>
                            </div>

                            <div class="search-box mb-3">
                                <div class="position-relative">
                                    <input
                                        type="text"
                                        class="form-control bg-light border-light ps-5"
                                        placeholder="{{ __('Search files or folders...') }}"
                                        wire:model.live.debounce.500ms="search"
                                    >
                                    <i class="ri-search-2-line search-icon position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                                </div>
                            </div>

                            <div class="mt-2 flex-grow-1">
                                <h6 class="fs-11 text-muted text-uppercase mb-3">{{ __('Tools') }}</h6>
                                <ul class="list-unstyled vstack gap-2">
                                    <li>
                                        <button type="button" class="btn btn-sm {{ $path === '' ? 'btn-primary' : 'btn-ghost-dark' }} w-100 text-start" wire:click="navigateTo('')">
                                            <i class="ri-hard-drive-2-line align-bottom me-2"></i> {{ __('All Tools') }}
                                        </button>
                                    </li>

                                    @foreach($toolRoots as $toolKey => $cfg)
                                        @php
                                            $stat = $toolStats[$toolKey] ?? ['folder_count' => 0, 'file_count' => 0, 'size' => 0];
                                        @endphp
                                        <li>
                                            <button
                                                type="button"
                                                class="btn btn-sm {{ $path === $toolKey || str_starts_with($path, $toolKey . '/') ? 'btn-primary' : 'btn-ghost-dark' }} w-100 text-start d-flex align-items-center justify-content-between"
                                                wire:click="navigateTo('{{ $toolKey }}')"
                                            >
                                                <span>
                                                    <i class="{{ $cfg['icon'] }} align-bottom me-2"></i> {{ __($cfg['label']) }}
                                                </span>
                                                <span class="badge bg-secondary-subtle text-secondary">{{ $stat['folder_count'] }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            <div class="mt-auto pt-3 border-top">
                                <h6 class="fs-11 text-muted text-uppercase mb-3">{{ __('Storage Status') }}</h6>
                                <div class="storage-status-chart">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="ri-database-2-line fs-17"></i>
                                            <span class="small text-muted">{{ __('Used by tool') }}</span>
                                        </div>
                                        <span class="badge {{ $usage['percent'] >= 85 ? 'bg-danger-subtle text-danger' : ($usage['percent'] >= 60 ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') }}">
                                            {{ $usage['percent'] }}%
                                        </span>
                                    </div>

                                    <div class="storage-status-bar mb-2" role="img" aria-label="{{ __('Storage usage by tool') }}">
                                        @if($usage['percent'] > 0 && count($storageSegments))
                                            <div class="storage-status-used" style="width: {{ $usage['percent'] }}%">
                                                @foreach($storageSegments as $segment)
                                                    <div
                                                        class="storage-status-segment bg-{{ $segment['color'] }}"
                                                        style="width: {{ $segment['percent_of_used'] }}%"
                                                        title="{{ __($segment['label']) }}: {{ $this->formatBytes($segment['size']) }}"
                                                    ></div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if($usage['percent'] < 100)
                                            <div class="storage-status-free"></div>
                                        @endif
                                    </div>

                                    <div class="text-muted fs-12 d-flex justify-content-between gap-2 mb-3">
                                        <span><b>{{ $this->formatBytes($usage['used_bytes']) }}</b> {{ __('used') }}</span>
                                        <span><b>{{ $this->formatBytes($usage['limit_bytes']) }}</b> {{ __('total') }}</span>
                                    </div>

                                    @if(count($storageSegments))
                                        <div class="storage-status-legend">
                                            @foreach($storageSegments as $segment)
                                                <div class="storage-status-item">
                                                    <span class="storage-status-swatch bg-{{ $segment['color'] }}"></span>
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div class="d-flex justify-content-between gap-2">
                                                            <span class="text-truncate">{{ __($segment['label']) }}</span>
                                                            <span class="fw-semibold">{{ $this->formatBytes($segment['size']) }}</span>
                                                        </div>
                                                        <div class="text-muted fs-12">
                                                            {{ number_format($segment['folder_count']) }} {{ __('folder(s)') }}
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-muted fs-12">{{ __('No generated files are stored yet.') }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="file-manager-content w-100 p-3 py-0">
                        <div class="mx-n3 pt-4 px-4">
                            <!-- Breadcrumb -->
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb breadcrumb-separated mb-0">
                                        @foreach($this->breadcrumbs() as $index => $crumb)
                                            @if($loop->last)
                                                <li class="breadcrumb-item active" aria-current="page">{{ __($crumb['label']) }}</li>
                                            @else
                                                <li class="breadcrumb-item">
                                                    <a href="javascript:void(0)" wire:click="navigateTo('{{ $crumb['path'] }}')">{{ __($crumb['label']) }}</a>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ol>
                                </nav>

                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-info-subtle text-info">{{ __('Read Only') }}</span>
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('S3-backed') }}</span>
                                </div>
                            </div>

                            <!-- Folders -->
                            <div id="folder-list" class="mb-4">
                                <div class="row justify-content-between g-2 mb-3">
                                    <div class="col">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-grow-1">
                                                <h5 class="fs-16 mb-0">{{ __('Folders') }}</h5>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <div class="text-muted small">
                                            {{ $folderCardsTotal }} {{ __('folder(s)') }}
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row g-3">
                                    @forelse($folderCards as $folder)
                                        <div class="col-xxl-3 col-md-4 col-sm-6">
                                            <div class="card shadow-none folder-tile {{ $path === $folder['path'] ? 'active' : '' }}">
                                                <div class="card-body folder-tile-trigger"
                                                     role="button"
                                                     tabindex="0"
                                                     aria-label="{{ __('Open folder :name', ['name' => $folder['label']]) }}"
                                                     wire:click="navigateTo('{{ $folder['path'] }}')"
                                                     wire:keydown.enter.prevent="navigateTo('{{ $folder['path'] }}')"
                                                     wire:keydown.space.prevent="navigateTo('{{ $folder['path'] }}')">
                                                    <div class="d-flex mb-3">
                                                        <div class="flex-grow-1">
                                                            <button type="button" class="btn btn-sm btn-ghost-primary" wire:click.stop="navigateTo('{{ $folder['path'] }}')">
                                                                {{ __('Open') }}
                                                            </button>
                                                        </div>
                                                        <div class="dropdown" wire:ignore.self>
                                                            <button class="btn btn-ghost-primary btn-icon btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" onclick="event.stopPropagation()">
                                                                <i class="ri-more-2-fill fs-16 align-bottom"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end" onclick="event.stopPropagation()">
                                                                <li>
                                                                    <button class="dropdown-item" type="button" wire:click="navigateTo('{{ $folder['path'] }}')">{{ __('Open') }}</button>
                                                                </li>
                                                                <li>
                                                                    <button class="dropdown-item text-danger" type="button" wire:click="confirmDeleteFolder('{{ $folder['path'] }}')">{{ __('Delete') }}</button>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>

                                                    <div class="text-center">
                                                        <div class="mb-2">
                                                            <i class="{{ $folder['icon'] }} align-bottom text-warning display-5"></i>
                                                        </div>
                                                        <h6 class="fs-15 folder-name mb-1">{{ __($folder['label']) }}</h6>
                                                        <div class="small text-muted text-truncate">{{ $folder['path'] }}</div>
                                                    </div>

                                                    <div class="hstack mt-4 text-muted">
                                                        <span class="me-auto"><b>{{ number_format($folder['item_count']) }}</b> {{ $folder['item_label'] }}</span>
                                                        <span><b>{{ $this->formatBytes($folder['size_bytes']) }}</b></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="col-12">
                                            <div class="alert alert-info mb-0">
                                                {{ __('No folders found in this location.') }}
                                            </div>
                                        </div>
                                    @endforelse
                                </div>

                                @if($hasMoreFolderCards)
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
                                        <div class="text-muted small">
                                            {{ __('Showing :shown of :total folders', ['shown' => $folderCards->count(), 'total' => $folderCardsTotal]) }}
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                wire:click="loadMoreFolders"
                                                wire:loading.attr="disabled"
                                                wire:target="loadMoreFolders">
                                            <span wire:loading.remove wire:target="loadMoreFolders">
                                                {{ __('Load Next :count', ['count' => $folderBatchSize]) }}
                                            </span>
                                            <span wire:loading wire:target="loadMoreFolders">
                                                {{ __('Loading...') }}
                                            </span>
                                        </button>
                                    </div>
                                @endif
                                
                            </div>
                            @if (count($pagedFiles))
                            <!-- Files -->
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <h5 class="flex-grow-1 fs-16 mb-0">{{ __('Files') }}</h5>
                                    <div class="text-muted small">
                                        {{ __('Showing') }} {{ $filesPaginator->firstItem() ?? 0 }} - {{ $filesPaginator->lastItem() ?? 0 }}
                                        {{ __('of') }} {{ $filesPaginator->total() }}
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle table-nowrap mb-0">
                                        <thead class="table-active">
                                            <tr>
                                                <th scope="col">{{ __('Name') }}</th>
                                                <th scope="col">{{ __('Type') }}</th>
                                                <th scope="col">{{ __('Size') }}</th>
                                                <th scope="col">{{ __('Updated') }}</th>
                                                <th scope="col" class="text-center">{{ __('Actions') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($pagedFiles as $file)
                                                @php
                                                    $isAudio = $this->isAudio($file['mime'], $file['extension']);
                                                    $isPreviewable = $this->isPreviewable($file['mime'], $file['extension']);
                                                    $streamUrl = $isAudio ? $this->temporaryUrlFor($file, 'inline') : null;
                                                    $downloadUrl = $this->temporaryUrlFor($file, 'attachment');
                                                @endphp
                                                <tr
                                                    class="file-row {{ $isPreviewable ? 'file-row-selectable' : '' }} {{ $selectedFile === $file['relative_path'] ? 'table-active' : '' }}"
                                                    wire:key="storage-file-{{ $file['id'] }}"
                                                    @if($isPreviewable)
                                                        wire:click="selectFile('{{ $file['relative_path'] }}')"
                                                    @endif
                                                >
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-xs flex-shrink-0 me-2">
                                                                @if($isAudio && $streamUrl)
                                                                    <a class="avatar-title bg-light text-muted rounded fs-16"
                                                                       href="{{ $streamUrl }}"
                                                                       target="_blank"
                                                                       rel="noopener noreferrer"
                                                                       onclick="event.stopPropagation()">
                                                                        <i class="{{ $this->fileIcon($file['mime'], $file['extension']) }}"></i>
                                                                    </a>
                                                                @else
                                                                    <div class="avatar-title bg-light text-muted rounded fs-16">
                                                                        <i class="{{ $this->fileIcon($file['mime'], $file['extension']) }}"></i>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                            <div>
                                                                <div class="fw-semibold text-truncate" style="max-width: 260px;">
                                                                    @if($isAudio && $streamUrl)
                                                                        <a href="{{ $streamUrl }}"
                                                                           class="text-body"
                                                                           target="_blank"
                                                                           rel="noopener noreferrer"
                                                                           onclick="event.stopPropagation()">
                                                                            {{ $file['basename'] }}
                                                                        </a>
                                                                    @else
                                                                        {{ $file['basename'] }}
                                                                    @endif
                                                                </div>
                                                                <div class="small text-muted text-truncate" style="max-width: 260px;">
                                                                    {{ $file['relative_path'] }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>{{ $this->typeLabel($file['mime'], $file['extension']) }}</td>
                                                    <td>{{ $this->formatBytes($file['size_bytes']) }}</td>
                                                    <td>{{ optional($file['updated_at'])->format('d M Y, h:i A') }}</td>
                                                    <td class="text-center" onclick="event.stopPropagation()">
                                                        <div class="dropdown" wire:ignore.self>
                                                            <button class="btn btn-ghost-primary btn-icon btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" onclick="event.stopPropagation()">
                                                                <i class="ri-more-2-fill fs-16 align-bottom"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end" onclick="event.stopPropagation()">
                                                                @if($isAudio && $streamUrl)
                                                                    <li>
                                                                        <a class="dropdown-item" href="{{ $streamUrl }}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">
                                                                            {{ __('Stream') }}
                                                                        </a>
                                                                    </li>
                                                                @endif
                                                                <li>
                                                                    <a class="dropdown-item" href="{{ $downloadUrl }}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">
                                                                        {{ __('Download') }}
                                                                    </a>
                                                                </li>
                                                                <li>
                                                                    <button class="dropdown-item text-danger" type="button" wire:click.stop="confirmDeleteFile('{{ $file['relative_path'] }}')">
                                                                        {{ __('Delete') }}
                                                                    </button>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted py-5">
                                                        {{ __('No files found in this folder.') }}
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                @if($filesPaginator->hasPages())
                                    <div class="align-items-center mt-3 row g-3 text-center text-sm-start">
                                        <div class="col-sm">
                                            <div class="text-muted">
                                                {{ __('Showing') }} <span class="fw-semibold">{{ $filesPaginator->firstItem() }}</span>
                                                {{ __('to') }} <span class="fw-semibold">{{ $filesPaginator->lastItem() }}</span>
                                                {{ __('of') }} <span class="fw-semibold">{{ $filesPaginator->total() }}</span> {{ __('results') }}
                                            </div>
                                        </div>
                                        <div class="col-sm-auto">
                                            {{ $filesPaginator->links() }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                            @endif

                                                        <div class="card border mb-4" id="storage-subscription-panel">
                                <div class="card-body">
                                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                                        <div>
                                            <h5 class="mb-1">{{ __('Storage Subscription') }}</h5>
                                            <div class="text-muted small">
                                                {{ __('Current plan: :code - :name', ['code' => strtoupper($currentStoragePlanCode), 'name' => $currentStoragePlanName]) }}
                                            </div>
                                            @if($currentStoragePlanCancellationScheduled && $currentStoragePlanEndsAtLabel)
                                                <div class="small text-warning mt-1">
                                                    {{ __('Cancellation scheduled. Access remains until :date.', ['date' => $currentStoragePlanEndsAtLabel]) }}
                                                </div>
                                            @endif
                                        </div>

                                        <div class="text-md-end">
                                            <div class="fw-semibold">{{ $this->formatBytes($usage['used_bytes']) }} / {{ $this->formatBytes($usage['limit_bytes']) }}</div>
                                            <div class="text-muted small">{{ __('Storage usage') }}: {{ $usage['percent'] }}%</div>
                                            @if($storageDisplayCurrencyCode !== 'IQD')
                                                <div class="text-muted small mt-1">
                                                    {{ __('Pricing display currency: :currency', ['currency' => $storageDisplayCurrencyCode]) }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    @if($storagePlanMessage !== '')
                                        <div class="alert alert-{{ $storagePlanMessageType }} mb-3">{{ $storagePlanMessage }}</div>
                                    @endif

                                    @if($projectedStorageOverQuotaAfterDowngrade && $currentStoragePlanCancellationScheduled)
                                        <div class="alert alert-danger mb-3">
                                            {{ __('After the scheduled downgrade, your current usage will be above the future quota. Existing files stay preserved, but uploads and storage-growing actions will be blocked until usage drops below the limit or you upgrade again.') }}
                                        </div>
                                    @elseif($storageOverQuota)
                                        <div class="alert alert-danger mb-3">
                                            {{ __('You are currently over quota. Uploads should be blocked until you upgrade or delete files.') }}
                                        </div>
                                    @endif

                                    @if($storageHourlyTestingEnabled)
                                        <div class="alert alert-info mb-3">
                                            <div class="fw-semibold">{{ __('Hourly renewal is enabled for testing only.') }}</div>
                                            <div class="small mt-1">{{ __('This keeps the storage plan price but uses a fast recurring interval to verify renewal, cancellation, and downgrade behavior quickly.') }}</div>
                                        </div>

                                        <div class="btn-group mb-3" role="group" aria-label="{{ __('Billing cycle') }}">
                                            <button type="button"
                                                    class="btn {{ $storageBillingCycle === 'monthly' ? 'btn-primary' : 'btn-outline-primary' }}"
                                                    wire:click="$set('storageBillingCycle', 'monthly')">
                                                {{ __('Monthly') }}
                                            </button>
                                            <button type="button"
                                                    class="btn {{ $storageBillingCycle === 'hourly' ? 'btn-primary' : 'btn-outline-primary' }}"
                                                    wire:click="$set('storageBillingCycle', 'hourly')">
                                                {{ __('Hourly Test') }}
                                            </button>
                                        </div>
                                    @endif

                                    <div class="row g-3 align-items-end mb-3">
                                        <div class="col-md-7">
                                            <label class="form-label mb-1">{{ __('Recurring payment method') }}</label>
                                            @if(count($storagePaymentMethods) > 0)
                                                <select class="form-select"
                                                        wire:model.live="selectedStoragePaymentMethod"
                                                        @disabled($processingStoragePlan)>
                                                    @foreach($storagePaymentMethods as $method)
                                                        <option value="{{ $method['code'] }}">
                                                            {{ $method['name'] }} ({{ strtoupper($method['driver']) }})
                                                        </option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <div class="alert alert-warning mb-0 py-2">
                                                    {{ __('No payment method is currently available for storage subscriptions.') }}
                                                </div>
                                            @endif
                                        </div>
                                        <div class="col-md-5 text-md-end">
                                            @if($canCancelCurrentStoragePlan && !$currentStoragePlanCancellationScheduled)
                                                <button class="btn btn-outline-danger"
                                                        wire:click="openStorageCancelConfirm"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openStorageCancelConfirm,confirmStoragePlanCancel">
                                                    {{ __('Cancel Current Plan') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        @foreach($storagePlans as $plan)
                                            @php
                                                $showLocalPrice = (bool) data_get($plan, 'price_display.has_localized_estimate', false);
                                            @endphp
                                            <div class="col-lg-4 col-md-6">
                                                <div class="card h-100 {{ $plan['is_current'] ? 'border border-success' : '' }}">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                                            <div>
                                                                <h6 class="mb-1">{{ $plan['name'] }}</h6>
                                                                <div class="text-muted small">{{ strtoupper($plan['code']) }}</div>
                                                            </div>
                                                            @if($plan['is_current'])
                                                                <span class="badge bg-success-subtle text-success">{{ __('Current') }}</span>
                                                            @endif
                                                        </div>

                                                        <div class="text-muted small mb-1">{{ __('Quota') }}: <b>{{ number_format($plan['quota_mb']) }} MB</b></div>
                                                        <div class="text-muted small mb-3">
                                                            {{ __('Price') }}:
                                                            <b>{{ data_get($plan, 'price_display.iqd_label') }}</b>
                                                            @if($storageBillingCycle === 'hourly')
                                                                <span>({{ __('hourly test') }})</span>
                                                            @else
                                                                <span>({{ __('monthly') }})</span>
                                                            @endif
                                                            @if($showLocalPrice)
                                                                <div>{{ data_get($plan, 'price_display.estimated_label') }}</div>
                                                            @endif
                                                        </div>

                                                        @if($plan['is_current'])
                                                            <button class="btn btn-success w-100" disabled>
                                                                {{ $plan['cancellation_scheduled'] ? __('Current Plan') : __('Your Current Plan') }}
                                                            </button>
                                                            @if($plan['cancellation_scheduled'])
                                                                <div class="small text-muted text-center mt-2">
                                                                    {{ __('Access remains until :date', ['date' => $plan['access_until_label'] ?: __('the current period end')]) }}
                                                                </div>
                                                            @endif
                                                        @else
                                                            <button class="btn btn-primary w-100"
                                                                    wire:click="openStorageConfirm({{ $plan['id'] }})"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="openStorageConfirm"
                                                                    @disabled(count($storagePaymentMethods) === 0 || $processingStoragePlan)>
                                                                {{ __('Change Plan') }}
                                                            </button>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Overview / Preview -->
                    <div class="file-manager-detail-content p-3 py-0">
                        <div class="mx-n3 pt-3 px-3">
                            @if($selectedPreview)
                                @php $preview = $selectedPreview; @endphp
                                <div id="file-overview" class="h-100 sticky-pane">
                                    <div class="d-flex h-100 flex-column">
                                        <div class="d-flex align-items-center pb-3 border-bottom border-bottom-dashed mb-3 gap-2">
                                            <h5 class="flex-grow-1 fw-bold mb-0">{{ __('File Preview') }}</h5>
                                            <div>
                                                <button type="button" class="btn btn-soft-danger btn-icon btn-sm fs-16" wire:click="confirmDeleteFile('{{ $preview['relative_path'] }}')">
                                                    <i class="ri-delete-bin-line align-bottom"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="pb-3 border-bottom border-bottom-dashed mb-3">
                                            <div class="file-details-box bg-light p-3 text-center rounded-3 border border-light mb-3">
                                                <div class="display-4 file-icon">
                                                    <i class="{{ $preview['icon'] }}"></i>
                                                </div>
                                            </div>

                                            <div class="d-flex gap-2 float-end">
                                                @if($preview['is_audio'])
                                                    <a href="{{ $preview['stream_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-icon btn-sm btn-ghost-success fs-16">
                                                        <i class="ri-play-circle-line"></i>
                                                    </a>
                                                @endif

                                                <a href="{{ $preview['download_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-icon btn-sm btn-ghost-primary fs-16">
                                                    <i class="ri-download-2-line"></i>
                                                </a>
                                            </div>

                                            <h5 class="fs-16 mb-1">{{ $preview['basename'] }}</h5>
                                            <p class="text-muted mb-0 fs-12">
                                                {{ $this->formatBytes($preview['size_bytes']) }},
                                                {{ optional($preview['updated_at'])->format('d M, Y') }}
                                            </p>
                                        </div>

                                        @if($preview['is_audio'])
                                            <div class="audio-preview mb-3" wire:key="storage-audio-preview-{{ md5($preview['relative_path']) }}">
                                                <div class="fw-semibold mb-2">{{ __('Audio Player') }}</div>
                                                <div wire:ignore>
                                                    <audio controls class="w-100" preload="none">
                                                        <source src="{{ $preview['stream_url'] }}" type="{{ $preview['mime'] }}">
                                                        {{ __('Your browser does not support audio playback.') }}
                                                    </audio>
                                                </div>
                                            </div>
                                        @endif

                                        <div>
                                            <h5 class="fs-12 text-uppercase text-muted mb-3">{{ __('File Details') }}</h5>

                                            <div class="table-responsive">
                                                <table class="table table-borderless table-nowrap table-sm">
                                                    <tbody>
                                                        <tr>
                                                            <th scope="row" style="width: 35%;">{{ __('File Name :') }}</th>
                                                            <td class="text-break">{{ $preview['basename'] }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('File Type :') }}</th>
                                                            <td>{{ $preview['type_label'] }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('MIME :') }}</th>
                                                            <td class="text-break">{{ $preview['mime'] }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Size :') }}</th>
                                                            <td>{{ $this->formatBytes($preview['size_bytes']) }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Updated :') }}</th>
                                                            <td>{{ optional($preview['updated_at'])->format('d M Y, h:i A') }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Path :') }}</th>
                                                            <td>
                                                                <div class="user-select-all text-break small">{{ $preview['relative_path'] }}</div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Disk :') }}</th>
                                                            <td>{{ $preview['disk'] }}</td>
                                                        </tr>
                                                        <tr>
                                                            <th scope="row">{{ __('Tool :') }}</th>
                                                            <td>{{ strtoupper(explode('/', $preview['relative_path'])[0] ?? '-') }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            @php
                                                $deletePrefix = $this->resolveDeletePrefixForFile($preview['relative_path']);
                                                $linkedDelete = $deletePrefix !== $preview['relative_path'];
                                            @endphp

                                            @if($linkedDelete)
                                                <div class="alert alert-warning mt-3 mb-0">
                                                    {{ __('Deleting this file will remove its linked job folder too.') }}
                                                </div>
                                            @endif
                                        </div>

                                        <div class="mt-auto border-top border-top-dashed py-3">
                                            <div class="hstack gap-2">
                                                <a href="{{ $preview['download_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-soft-primary w-100">
                                                    <i class="ri-download-2-line align-bottom me-1"></i> {{ __('Download') }}
                                                </a>
                                                @if($preview['is_audio'])
                                                    <a href="{{ $preview['stream_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-soft-success w-100">
                                                        <i class="ri-play-circle-line align-bottom me-1"></i> {{ __('Stream') }}
                                                    </a>
                                                @endif
                                                <button type="button" class="btn btn-soft-danger w-100" wire:click="confirmDeleteFile('{{ $preview['relative_path'] }}')">
                                                    <i class="ri-delete-bin-line align-bottom me-1"></i> {{ __('Delete') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div id="folder-overview" class="sticky-pane">
                                    <div class="d-flex align-items-center pb-3 border-bottom border-bottom-dashed">
                                        <h5 class="flex-grow-1 fw-bold mb-0">{{ __('Overview') }}</h5>
                                    </div>

                                    <div class="mt-4">
                                        <ul class="list-unstyled vstack gap-4">
                                            @foreach($overviewStats as $label => $stat)
                                                <li>
                                                    <div class="d-flex align-items-center">
                                                        <div class="flex-shrink-0">
                                                            <div class="avatar-xs">
                                                                <div class="avatar-title rounded bg-{{ $stat['color'] }}-subtle text-{{ $stat['color'] }}">
                                                                    <i class="{{ $stat['icon'] }} fs-17"></i>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1 ms-3">
                                                            <h5 class="mb-1 fs-15">{{ __($label) }}</h5>
                                                            <p class="mb-0 fs-12 text-muted">{{ number_format($stat['count']) }} {{ __('files') }}</p>
                                                        </div>
                                                        <b>{{ $this->formatBytes($stat['size']) }}</b>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>

                                    <div class="pb-3 mt-4">
                                        <div class="alert alert-info d-flex align-items-center mb-0">
                                            <div class="flex-shrink-0">
                                                <i class="ri-lock-2-line text-info align-bottom display-6"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h5 class="text-info fs-14">{{ __('Storage Rules') }}</h5>
                                                <p class="text-muted mb-0">
                                                    {{ __('This page is read-only. Customers can stream, download, and delete only.') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        {{-- </div> --}}

        @if($showStoragePlanConfirm)
            @php
                $selectedStoragePlan = collect($storagePlans)->firstWhere('id', $selectedStoragePlanId);
                $selectedStorageDisplay = is_array($selectedStoragePlan) ? ($selectedStoragePlan['price_display'] ?? null) : null;
                $selectedStorageMethod = collect($storagePaymentMethods)->firstWhere('code', strtolower(trim((string) $selectedStoragePaymentMethod)));
                $selectedStorageDriver = strtolower(trim((string) data_get($selectedStorageMethod, 'driver')));
                $selectedStorageMode = strtolower((string) data_get($selectedStoragePlan, 'payment_mode', 'recurring'));
                $selectedStorageUsesRecurring = $selectedStorageMode === 'recurring';
            @endphp

            <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Confirm Storage Plan Change') }}</h5>
                            <button type="button"
                                    class="btn-close"
                                    wire:click="closeStorageConfirm"
                                    @disabled($processingStoragePlan)></button>
                        </div>

                        <div class="modal-body">
                            @if($selectedStoragePlan)
                                <p class="mb-2">
                                    {{ __('You are switching to:') }}
                                    <b>{{ $selectedStoragePlan['name'] }}</b>
                                    ({{ strtoupper($selectedStoragePlan['code']) }})
                                </p>
                                <p class="mb-2">
                                    {{ __('Billing cycle:') }}
                                    <b>{{ $storageBillingCycle === 'hourly' ? __('Hourly Test') : __('Monthly') }}</b>
                                </p>
                                <p class="mb-2">
                                    {{ __('New quota:') }}
                                    <b>{{ number_format($selectedStoragePlan['quota_mb']) }} MB</b>
                                </p>
                                <p class="mb-2">
                                    {{ __('Price:') }}
                                    <b>{{ data_get($selectedStorageDisplay, 'iqd_label') }}</b>
                                </p>
                                @if((bool) data_get($selectedStorageDisplay, 'has_localized_estimate', false))
                                    <p class="mb-2">
                                        {{ __('Estimated local display:') }}
                                        <b>{{ data_get($selectedStorageDisplay, 'display_label') }}</b>
                                    </p>
                                @endif
                                <p class="mb-2">
                                    {{ __('Payment method:') }}
                                    <b>{{ data_get($selectedStorageMethod, 'name', strtoupper((string) $selectedStoragePaymentMethod)) }}</b>
                                </p>
                            @endif

                            <div class="small text-muted">
                                {{ __('If your usage is above the target quota after downgrade, existing files remain preserved, but uploads and storage-growing actions stay blocked until usage is reduced or you upgrade again.') }}
                            </div>

                            <div class="alert alert-warning mt-3 mb-0">
                                @if($selectedStorageDriver === 'fib')
                                    @if($selectedStorageUsesRecurring)
                                        <div class="fw-semibold mb-2">{{ __('Next step: complete recurring checkout in First Iraqi Bank') }}</div>
                                        <div>{{ __('We will open a dedicated FIB subscription page with QR scan, manual code entry, automatic status refresh, and cancel controls.') }}</div>
                                    @else
                                        <div class="fw-semibold mb-2">{{ __('Next step: complete manual payment in First Iraqi Bank') }}</div>
                                        <div>{{ __('We will open a dedicated FIB payment page with QR scan, manual code entry, and automatic status refresh.') }}</div>
                                    @endif
                                @else
                                    <div class="fw-semibold mb-2">
                                        {{ $selectedStorageUsesRecurring
                                            ? __('Selected provider is not yet active for storage recurring checkout')
                                            : __('Selected provider is not yet active for manual storage payment') }}
                                    </div>
                                    <div>
                                        {{ $selectedStorageUsesRecurring
                                            ? __('Please use FIB until additional recurring providers are enabled.')
                                            : __('Please use FIB until additional manual-payment providers are enabled.') }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button class="btn btn-light"
                                    wire:click="closeStorageConfirm"
                                    @disabled($processingStoragePlan)>
                                {{ __('Cancel') }}
                            </button>
                            <button class="btn btn-primary"
                                    wire:click="confirmStoragePlanChange"
                                    @disabled($processingStoragePlan || count($storagePaymentMethods) === 0)>
                                @if($processingStoragePlan)
                                    {{ __('Preparing...') }}
                                @else
                                    {{ $selectedStorageUsesRecurring ? __('Open Subscription Checkout') : __('Open Payment Checkout') }}
                                @endif
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($showStorageCancelConfirm)
            <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Cancel Storage Plan') }}</h5>
                            <button type="button"
                                    class="btn-close"
                                    wire:click="closeStorageCancelConfirm"
                                    @disabled($processingStoragePlan)></button>
                        </div>

                        <div class="modal-body">
                            <p class="mb-2">
                                {{ __('Your current paid storage remains active until:') }}
                                <b>{{ $currentStoragePlanEndsAtLabel ?: __('the end of the current billing period') }}</b>
                            </p>
                            <p class="mb-2">
                                {{ __('Current usage:') }}
                                <b>{{ $this->formatBytes($usage['used_bytes']) }}</b>
                            </p>
                            <p class="mb-2">
                                {{ __('Current plan limit:') }}
                                <b>{{ $this->formatBytes($usage['limit_bytes']) }}</b>
                            </p>
                            <p class="mb-2">
                                {{ __('Future limit after downgrade:') }}
                                <b>{{ number_format($storageFutureQuotaMb) }} MB</b>
                            </p>

                            <div class="alert alert-warning mb-0">
                                {{ __('If your usage is above the future limit after the billing period ends, existing files will stay preserved, but uploads and storage-growing actions will be blocked until you delete files or upgrade again.') }}
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button class="btn btn-light"
                                    wire:click="closeStorageCancelConfirm"
                                    @disabled($processingStoragePlan)>
                                {{ __('Keep Plan') }}
                            </button>
                            <button class="btn btn-danger"
                                    wire:click="confirmStoragePlanCancel"
                                    @disabled($processingStoragePlan)>
                                @if($processingStoragePlan)
                                    {{ __('Scheduling...') }}
                                @else
                                    {{ __('Confirm Cancellation') }}
                                @endif
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Delete Modal -->
        <div
            id="removeFileItemModal"
            class="modal fade zoomIn"
            tabindex="-1"
            aria-hidden="true"
            wire:ignore.self
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0">
                    <div class="modal-header">
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="cancelDelete"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mt-2 text-center">
                            <div class="avatar-lg mx-auto mb-4">
                                <div class="avatar-title bg-danger-subtle text-danger rounded-circle fs-1">
                                    <i class="ri-delete-bin-5-line"></i>
                                </div>
                            </div>

                            <div class="mt-4 pt-2 fs-15 mx-4 mx-sm-5">
                                <h4>{{ __('Are you sure?') }}</h4>
                                <p class="text-muted mx-4 mb-0">
                                    @if($pendingDeleteType === 'folder')
                                        {{ __('Deleting this folder will permanently remove all files inside it.') }}
                                    @else
                                        {{ __('Deleting this item may also remove all linked assets in the same job folder.') }}
                                    @endif
                                </p>

                                @if($pendingDeleteLabel)
                                    <div class="mt-3 small text-break text-primary">
                                        {{ $pendingDeleteLabel }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="d-flex gap-2 justify-content-center mt-4 mb-2">
                            <button type="button" class="btn w-sm btn-light" data-bs-dismiss="modal" wire:click="cancelDelete">
                                {{ __('Close') }}
                            </button>
                            <button type="button" class="btn w-sm btn-danger" wire:click="deleteConfirmed">
                                {{ __('Yes, Delete It!') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
    let storageDeleteModal;

    const getStorageDeleteModal = () => {
        const el = document.getElementById('removeFileItemModal');
        if (!el) return null;
        storageDeleteModal ??= new bootstrap.Modal(el);
        return storageDeleteModal;
    };

    window.addEventListener('storage-delete-modal-open', () => {
        const modal = getStorageDeleteModal();
        modal?.show();
    });

    window.addEventListener('storage-delete-modal-close', () => {
        const modal = getStorageDeleteModal();
        modal?.hide();
    });
</script>
@endpush
