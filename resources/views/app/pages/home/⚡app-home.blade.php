<?php

use App\Models\Customer;
use App\Models\MlJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('app::layouts.app')]
#[Title('Dashboard | METKURD')]
class extends Component
{
    public int $refreshTick = 0;

    public function mount(): void
    {
        $this->refreshDashboard();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('xtts-renders-refresh')]
    #[On('clone-xtts-renders-refresh')]
    #[On('wasr-renders-refresh')]
    #[On('asr-renders-refresh')]
    #[On('stem-renders-refresh')]
    #[On('ocr-renders-refresh')]
    #[On('youtube-downloads-refresh')]
    public function refreshDashboard(): void
    {
        $this->refreshTick++;
    }

    #[Computed]
    public function customer(): ?Customer
    {
        $customerId = $this->customerId();

        if ($customerId <= 0) {
            return null;
        }

        return Customer::query()
            ->with([
                'profile',
                'usage',
                'wallet',
                'servicePlan',
                'storagePlan',
                'activeServiceSubscription.servicePlan',
                'activeStorageSubscription.storagePlan',
            ])
            ->find($customerId);
    }

    #[Computed]
    public function dashboardStats(): array
    {
        $customer = $this->customer();
        $wallet = $customer?->wallet;
        $usage = $customer?->usage;
        $customerId = $this->customerId();
        $allowedSlots = $this->allowedSlots();

        if ($customerId <= 0) {
            return [
                'credits_balance' => 0,
                'subscription_balance' => 0,
                'addon_balance' => 0,
                'storage_used_bytes' => 0,
                'storage_quota_bytes' => 1,
                'storage_pct' => 0,
                'active_jobs' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
                'completed_today' => 0,
                'jobs_30' => 0,
                'completed_30' => 0,
                'failed_30' => 0,
                'success_pct' => 0,
                'credits_spent_30' => 0,
                'storage_in_30' => 0,
                'storage_out_30' => 0,
                'allowed_slots' => $allowedSlots,
                'available_slots' => $allowedSlots,
            ];
        }

        $windowStart = now()->subDays(30);

        $jobsBase = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'deleted');

        $windowBase = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'deleted')
            ->where('created_at', '>=', $windowStart);

        $jobsThirty = (int) (clone $windowBase)->count();
        $completedThirty = (int) (clone $windowBase)->where('status', 'done')->count();
        $failedThirty = (int) (clone $windowBase)->where('status', 'failed')->count();
        $resolvedThirty = $completedThirty + $failedThirty;
        $quotaBytes = max(1, (int) (($customer?->storagePlan?->quota_mb ?? 512) * 1024 * 1024));
        $usedBytes = (int) ($usage?->storage_used_bytes ?? 0);
        $activeJobs = (int) (clone $jobsBase)->active()->count();

        return [
            'credits_balance' => (int) ($wallet?->balance_credits ?? 0),
            'subscription_balance' => (int) ($wallet?->subscription_balance_credits ?? 0),
            'addon_balance' => (int) ($wallet?->addon_balance_credits ?? 0),
            'storage_used_bytes' => $usedBytes,
            'storage_quota_bytes' => $quotaBytes,
            'storage_pct' => min(100, (int) round(($usedBytes / $quotaBytes) * 100)),
            'active_jobs' => $activeJobs,
            'queued_jobs' => (int) (clone $jobsBase)->where('status', 'queued')->count(),
            'running_jobs' => (int) (clone $jobsBase)->whereIn('status', ['running', 'saving'])->count(),
            'completed_today' => (int) (clone $jobsBase)
                ->where('status', 'done')
                ->whereDate('finished_at', now()->toDateString())
                ->count(),
            'jobs_30' => $jobsThirty,
            'completed_30' => $completedThirty,
            'failed_30' => $failedThirty,
            'success_pct' => $resolvedThirty > 0
                ? (int) round(($completedThirty / $resolvedThirty) * 100)
                : 0,
            'credits_spent_30' => (int) (clone $windowBase)->sum('credits_charged'),
            'storage_in_30' => (int) (clone $windowBase)->sum('storage_in_bytes'),
            'storage_out_30' => (int) (clone $windowBase)->sum('storage_out_bytes'),
            'allowed_slots' => $allowedSlots,
            'available_slots' => max(0, $allowedSlots - $activeJobs),
        ];
    }

    #[Computed]
    public function quickActions(): array
    {
        $locale = app()->getLocale();
        $customer = $this->customer();

        $actions = [
            [
                'tool' => 'tts',
                'label' => 'Text to Speech',
                'description' => 'Turn scripts into natural audio output.',
                'route' => route('app.xtts', ['locale' => $locale]),
                'icon' => 'ri-volume-up-line',
                'entitlement' => 'tts.standard',
                'cta' => 'Open XTTS',
            ],
            [
                'tool' => 'clone_tts',
                'label' => 'Voice Clone',
                'description' => 'Generate speech with a cloned voice profile.',
                'route' => route('app.clone-xtts', ['locale' => $locale]),
                'icon' => 'ri-user-voice-line',
                'entitlement' => 'clone_tts.standard',
                'cta' => 'Open Clone XTTS',
            ],
            [
                'tool' => 'asr',
                'label' => 'Speech to Text',
                'description' => 'Transcribe audio and export clean text.',
                'route' => route('app.wasr', ['locale' => $locale]),
                'icon' => 'ri-file-text-line',
                'entitlement' => 'asr.standard',
                'cta' => 'Open WASR',
            ],
            [
                'tool' => 'stem',
                'label' => 'Stem Separation',
                'description' => 'Split vocals and instruments into tracks.',
                'route' => route('app.stem', ['locale' => $locale]),
                'icon' => 'bx bx-music',
                'entitlement' => 'stem.sep2',
                'cta' => 'Open STEM',
            ],
            [
                'tool' => 'ocr',
                'label' => 'OCR',
                'description' => 'Extract text from scans and images.',
                'route' => route('app.ocr', ['locale' => $locale]),
                'icon' => 'bx bx-aperture',
                'entitlement' => 'ocr.standard',
                'cta' => 'Open OCR',
            ],
            [
                'tool' => 'youtube_video',
                'label' => 'YouTube Downloader',
                'description' => 'Preview and download video or audio jobs.',
                'route' => route('app.youtube', ['locale' => $locale]),
                'icon' => 'ri-youtube-line',
                'tool_codes' => ['youtube_audio', 'youtube_video'],
                'cta' => 'Open YouTube',
            ],
        ];

        if (! $customer) {
            return [];
        }

        return array_values(array_filter($actions, function (array $action) use ($customer): bool {
            $toolCodes = $action['tool_codes'] ?? [$action['tool']];

            foreach ((array) $toolCodes as $toolCode) {
                if ($customer->canAccessTool((string) $toolCode)) {
                    return true;
                }
            }

            return false;
        }));
    }

    #[Computed]
    public function runningJobs(): Collection
    {
        $customerId = $this->customerId();

        if ($customerId <= 0) {
            return collect();
        }

        return MlJob::query()
            ->with('tool:id,code,name')
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'deleted')
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->orderBy('created_at')
            ->limit(max(5, $this->allowedSlots()))
            ->get();
    }

    #[Computed]
    public function recentJobs(): Collection
    {
        $customerId = $this->customerId();

        if ($customerId <= 0) {
            return collect();
        }

        return MlJob::query()
            ->with('tool:id,code,name')
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'deleted')
            ->latest('created_at')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function toolBreakdown(): Collection
    {
        $customerId = $this->customerId();

        if ($customerId <= 0) {
            return collect();
        }

        $toolExpr = "COALESCE(tools.code, ml_jobs.job_kind, 'unknown')";

        return DB::table('ml_jobs')
            ->leftJoin('tools', 'tools.id', '=', 'ml_jobs.tool_id')
            ->where('ml_jobs.customer_id', $customerId)
            ->where('ml_jobs.status', '!=', 'deleted')
            ->where('ml_jobs.created_at', '>=', now()->subDays(30))
            ->selectRaw($toolExpr . ' as tool_code')
            ->selectRaw('COUNT(*) as jobs')
            ->selectRaw("SUM(CASE WHEN ml_jobs.status = 'done' THEN 1 ELSE 0 END) as completed_jobs")
            ->selectRaw("SUM(CASE WHEN ml_jobs.status = 'failed' THEN 1 ELSE 0 END) as failed_jobs")
            ->selectRaw('SUM(COALESCE(ml_jobs.credits_charged, 0)) as credits')
            ->groupBy(DB::raw($toolExpr))
            ->orderByDesc('jobs')
            ->get()
            ->map(function ($row) {
                $toolCode = (string) ($row->tool_code ?? 'unknown');

                return [
                    'code' => $toolCode,
                    'label' => $this->toolLabel($toolCode),
                    'jobs' => (int) ($row->jobs ?? 0),
                    'completed' => (int) ($row->completed_jobs ?? 0),
                    'failed' => (int) ($row->failed_jobs ?? 0),
                    'credits' => (int) ($row->credits ?? 0),
                    'color' => $this->toolColor($toolCode),
                ];
            })
            ->values();
    }

    protected function customerId(): int
    {
        return (int) auth('app')->id();
    }

    public function customerDisplayName(): string
    {
        $customer = $this->customer();
        $fullName = trim(
            (string) ($customer?->profile?->first_name ?? '') . ' ' . (string) ($customer?->profile?->last_name ?? '')
        );

        if ($fullName !== '') {
            return $fullName;
        }

        $username = trim((string) ($customer?->username ?? ''));

        if ($username !== '') {
            return $username;
        }

        $email = trim((string) ($customer?->email ?? ''));

        if ($email !== '') {
            return (string) Str::before($email, '@');
        }

        return 'there';
    }

    public function greetingLabel(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    public function allowedSlots(): int
    {
        $customer = $this->customer();
        $serviceCode = strtolower((string) (
            $customer?->servicePlan?->code
            ?? $customer?->activeServiceSubscription?->servicePlan?->code
            ?? 'free'
        ));

        return match ($serviceCode) {
            'student' => 2,
            'pro' => 3,
            'premium' => 5,
            default => 2,
        };
    }

    public function toolLabel(?string $toolCode): string
    {
        return match ($this->normalizeToolCode($toolCode)) {
            'tts' => 'Text to Speech',
            'clone_tts' => 'Voice Clone',
            'asr' => 'Speech to Text',
            'stem' => 'Stem Separation',
            'ocr' => 'OCR',
            'youtube_audio' => 'YouTube Audio',
            'youtube_video' => 'YouTube Video',
            'youtube_download' => 'YouTube Downloader',
            default => Str::headline(str_replace('_', ' ', (string) $toolCode)),
        };
    }

    public function toolBadgeClass(?string $toolCode): string
    {
        return match ($this->normalizeToolCode($toolCode)) {
            'tts' => 'primary',
            'clone_tts' => 'info',
            'asr' => 'warning',
            'stem' => 'success',
            'ocr' => 'white',
            'youtube_audio', 'youtube_video', 'youtube_download' => 'danger',
            default => 'secondary',
        };
    }

    public function toolColor(?string $toolCode): string
    {
        return match ($this->normalizeToolCode($toolCode)) {
            'tts' => '#85a7ec',
            'clone_tts' => '#73cfeb',
            'asr' => '#edc975',
            'stem' => '#42d189',
            'ocr' => '#e0e9fa',
            'youtube_audio' => '#f17e7e',
            'youtube_video', 'youtube_download' => '#f17e7e',
            default => '#64748b',
        };
    }

    public function statusBadgeClass(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'done' => 'success',
            'failed' => 'danger',
            'running', 'saving' => 'info',
            'queued' => 'warning',
            default => 'secondary',
        };
    }

    public function statusLabel(?string $status): string
    {
        return Str::headline((string) $status);
    }

    public function routeForTool(?string $toolCode): string
    {
        $locale = app()->getLocale();
        $normalizedToolCode = $this->normalizeToolCode($toolCode);

        if (! $this->canOpenTool($normalizedToolCode)) {
            return route('app.home', ['locale' => $locale]);
        }

        return match ($normalizedToolCode) {
            'tts' => route('app.xtts', ['locale' => $locale]),
            'clone_tts' => route('app.clone-xtts', ['locale' => $locale]),
            'asr' => route('app.wasr', ['locale' => $locale]),
            'stem' => route('app.stem', ['locale' => $locale]),
            'ocr' => route('app.ocr', ['locale' => $locale]),
            'youtube_audio', 'youtube_video', 'youtube_download' => route('app.youtube', ['locale' => $locale]),
            default => route('app.home', ['locale' => $locale]),
        };
    }

    public function canOpenTool(?string $toolCode): bool
    {
        $customer = $this->customer();

        if (! $customer) {
            return false;
        }

        return match ($this->normalizeToolCode($toolCode)) {
            'youtube_download' => $customer->canAccessAnyTool(['youtube_audio', 'youtube_video']),
            default => $customer->canAccessTool($this->normalizeToolCode($toolCode)),
        };
    }

    public function formatCredits(int|float|null $value): string
    {
        return number_format((float) $value) . ' cr';
    }

    public function formatBytes(int|float|null $bytes): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format($bytes, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }

    protected function normalizeToolCode(?string $toolCode): string
    {
        return match ((string) $toolCode) {
            'wasr' => 'asr',
            default => (string) $toolCode,
        };
    }
};
?>

@php
    $customer = $this->customer();
    $stats = $this->dashboardStats();
    $toolBreakdown = $this->toolBreakdown();
    $toolTotal = max(1, (int) $toolBreakdown->sum('jobs'));
    $servicePlan = $customer?->servicePlan ?: $customer?->activeServiceSubscription?->servicePlan;
    $storagePlan = $customer?->storagePlan ?: $customer?->activeStorageSubscription?->storagePlan;
@endphp

<div class="mk-home-dashboard" wire:poll.15000ms="refreshDashboard">
    <style>
        .mk-home-dashboard {
            --mk-ink: #0f172a;
            --mk-muted: #5b6475;
            --mk-surface: #ffffff;
            --mk-border: rgba(15, 23, 42, 0.08);
            --mk-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            --mk-hero-start: #09111f;
            --mk-hero-end: #17345f;
        }

        .mk-home-dashboard .mk-section-card,
        .mk-home-dashboard .mk-metric-card,
        .mk-home-dashboard .mk-quick-card {
            border: 1px solid var(--mk-border);
            border-radius: 1.25rem;
            /* background: var(--mk-surface); */
            box-shadow: var(--mk-shadow);
        }

        .mk-home-dashboard .mk-dashboard-hero {
            border: 0;
            border-radius: 1.5rem;
            overflow: hidden;
            color: #fff;
            background:
                radial-gradient(circle at top right, rgba(56, 189, 248, 0.25), transparent 35%),
                radial-gradient(circle at bottom left, rgba(34, 197, 94, 0.22), transparent 30%),
                linear-gradient(135deg, var(--mk-hero-start), var(--mk-hero-end));
            box-shadow: 0 24px 60px rgba(9, 17, 31, 0.25);
        }

        .mk-home-dashboard .mk-dashboard-hero::after {
            content: '';
            position: absolute;
            inset: auto -8% -35% auto;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            filter: blur(8px);
        }

        .mk-home-dashboard .mk-hero-shell {
            position: relative;
            z-index: 1;
        }

        .mk-home-dashboard .mk-hero-pill {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .55rem .85rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .mk-home-dashboard .mk-hero-copy {
            max-width: 640px;
        }

        .mk-home-dashboard .mk-hero-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .mk-home-dashboard .mk-hero-stat {
            padding: 1rem 1.1rem;
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            backdrop-filter: blur(8px);
        }

        .mk-home-dashboard .mk-hero-stat .label {
            color: rgba(255, 255, 255, 0.72);
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .mk-home-dashboard .mk-hero-stat .value {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .mk-home-dashboard .mk-metric-card,
        .mk-home-dashboard .mk-quick-card {
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .mk-home-dashboard .mk-metric-card:hover,
        .mk-home-dashboard .mk-quick-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 44px rgba(15, 23, 42, 0.12);
        }

        .mk-home-dashboard .mk-metric-icon,
        .mk-home-dashboard .mk-quick-icon {
            width: 3rem;
            height: 3rem;
            border-radius: .95rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            background: rgba(37, 99, 235, 0.08);
        }

        .mk-home-dashboard .mk-mini-note {
            color: var(--mk-muted);
            font-size: .9rem;
        }

        .mk-home-dashboard .mk-quick-card {
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .mk-home-dashboard .mk-quick-card::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--mk-tool-color, #2563eb);
        }

        .mk-home-dashboard .mk-progress-track {
            width: 100%;
            height: .6rem;
            border-radius: 999px;
            background: #e9eef5;
            overflow: hidden;
        }

        .mk-home-dashboard .mk-progress-bar {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, rgba(37, 99, 235, 0.9), rgba(14, 165, 233, 0.95));
        }

        .mk-home-dashboard .mk-segmented-bar {
            display: flex;
            width: 100%;
            height: .85rem;
            overflow: hidden;
            border-radius: 999px;
            background: #e9eef5;
        }

        .mk-home-dashboard .mk-segment {
            height: 100%;
            min-width: .5rem;
        }

        .mk-home-dashboard .mk-stat-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .8rem 0;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }

        .mk-home-dashboard .mk-stat-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .mk-home-dashboard .mk-inline-dot {
            width: .7rem;
            height: .7rem;
            border-radius: 50%;
            display: inline-block;
        }

        .mk-home-dashboard .mk-job-row + .mk-job-row {
            border-top: 1px solid rgba(15, 23, 42, 0.06);
        }

        .mk-home-dashboard .mk-empty-state {
            border: 1px dashed rgba(15, 23, 42, 0.15);
            border-radius: 1rem;
            background: #333435;
        }

        .mk-home-dashboard .mk-table-card .table > :not(caption) > * > * {
            padding-top: .9rem;
            padding-bottom: .9rem;
        }

        @media (max-width: 991.98px) {
            .mk-home-dashboard .mk-hero-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div>
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-sm-0">Customer Dashboard</h4>
                        <div class="text-muted mt-1">A live view of credits, AI jobs, storage, and recent activity.</div>
                    </div>

                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="javascript:void(0);">App</a></li>
                            <li class="breadcrumb-item active">Dashboard</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mk-dashboard-hero position-relative mb-4">
            <div class="card-body p-4 p-xl-5 mk-hero-shell">
                <div class="row g-4 align-items-center">
                    <div class="col-xl-7">
                        <span class="mk-hero-pill mb-3">
                            <i class="ri-flashlight-line"></i>
                            Live customer workspace
                        </span>

                        <div class="mk-hero-copy">
                            <h2 class="text-white mb-2">{{ $this->greetingLabel() }}, {{ $this->customerDisplayName() }}</h2>
                            <p class="mb-4 text-white text-opacity-75">
                                This dashboard keeps the important customer signals in one place:
                                current balance, live jobs, storage usage, and direct access back into each ML workflow.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a wire:navigate.hover href="{{ route('app.storage', ['locale' => app()->getLocale()]) }}" class="btn btn-light btn-label waves-effect waves-light">
                                <i class="ri-folder-line label-icon align-middle fs-16 me-2"></i>My Storage
                            </a>
                            <a wire:navigate.hover href="{{ route('app.billing', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-light btn-label waves-effect waves-light">
                                <i class="ri-wallet-3-line label-icon align-middle fs-16 me-2"></i>Billing
                            </a>
                            <a wire:navigate.hover href="{{ route('app.profile', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-light btn-label waves-effect waves-light">
                                <i class="ri-user-settings-line label-icon align-middle fs-16 me-2"></i>Profile
                            </a>
                        </div>
                    </div>

                    <div class="col-xl-5">
                        <div class="mk-hero-grid">
                            <div class="mk-hero-stat">
                                <div class="label mb-2">Service plan</div>
                                <div class="value">{{ $servicePlan?->name ?? 'Free' }}</div>
                                <div class="small text-white text-opacity-75 mt-1">
                                    {{ number_format((int) ($servicePlan?->monthly_credits ?? 0)) }} monthly credits
                                </div>
                            </div>

                            <div class="mk-hero-stat">
                                <div class="label mb-2">Storage plan</div>
                                <div class="value">{{ $storagePlan?->name ?? 'Default Storage' }}</div>
                                <div class="small text-white text-opacity-75 mt-1">
                                    {{ $this->formatBytes($stats['storage_quota_bytes']) }} capacity
                                </div>
                            </div>

                            <div class="mk-hero-stat">
                                <div class="label mb-2">Open job slots</div>
                                <div class="value">{{ number_format($stats['available_slots']) }} / {{ number_format($stats['allowed_slots']) }}</div>
                                <div class="small text-white text-opacity-75 mt-1">
                                    {{ number_format($stats['active_jobs']) }} active job(s) right now
                                </div>
                            </div>

                            <div class="mk-hero-stat">
                                <div class="label mb-2">Cycle window</div>
                                <div class="value">
                                    {{ optional($customer?->wallet?->cycle_ends_on)->format('d M Y') ?: 'Not set' }}
                                </div>
                                <div class="small text-white text-opacity-75 mt-1">
                                    Auto-refreshing every 15 seconds
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card mk-metric-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="text-muted text-uppercase fw-semibold fs-12 mb-2">Available credits</div>
                                <h3 class="mb-1">{{ $this->formatCredits($stats['credits_balance']) }}</h3>
                                <div class="mk-mini-note">
                                    Subscription: <span class="fw-semibold text-body">{{ $this->formatCredits($stats['subscription_balance']) }}</span><br>
                                    Add-on: <span class="fw-semibold text-body">{{ $this->formatCredits($stats['addon_balance']) }}</span>
                                </div>
                            </div>
                            <div class="mk-metric-icon text-primary">
                                <i class="ri-coins-line"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card mk-metric-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div class="w-100">
                                <div class="text-muted text-uppercase fw-semibold fs-12 mb-2">Storage usage</div>
                                <h3 class="mb-1">{{ $this->formatBytes($stats['storage_used_bytes']) }}</h3>
                                <div class="mk-mini-note mb-3">
                                    {{ $this->formatBytes($stats['storage_quota_bytes']) }} total quota
                                </div>
                                <div class="mk-progress-track">
                                    <div class="mk-progress-bar" style="width: {{ $stats['storage_pct'] }}%;"></div>
                                </div>
                                <div class="small text-muted mt-2">{{ $stats['storage_pct'] }}% used</div>
                            </div>
                            <div class="mk-metric-icon text-info">
                                <i class="ri-database-2-line"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card mk-metric-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="text-muted text-uppercase fw-semibold fs-12 mb-2">Live jobs</div>
                                <h3 class="mb-1">{{ number_format($stats['active_jobs']) }}</h3>
                                <div class="mk-mini-note">
                                    Queued: <span class="fw-semibold text-body">{{ number_format($stats['queued_jobs']) }}</span><br>
                                    Running or saving: <span class="fw-semibold text-body">{{ number_format($stats['running_jobs']) }}</span>
                                </div>
                            </div>
                            <div class="mk-metric-icon text-warning">
                                <i class="ri-focus-line"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card mk-metric-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="text-muted text-uppercase fw-semibold fs-12 mb-2">Last 30 days</div>
                                <h3 class="mb-1">{{ number_format($stats['success_pct']) }}%</h3>
                                <div class="mk-mini-note">
                                    {{ number_format($stats['completed_30']) }} completed / {{ number_format($stats['failed_30']) }} failed<br>
                                    {{ $this->formatCredits($stats['credits_spent_30']) }} charged
                                </div>
                            </div>
                            <div class="mk-metric-icon text-success">
                                <i class="ri-line-chart-line"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-7">
                <div class="card mk-section-card h-100">
                    <div class="card-header border-0 pb-0 bg-transparent">
                        <div class="d-flex justify-content-between align-items-center gap-3">
                            <div>
                                <h5 class="card-title mb-1">Quick Actions</h5>
                                <p class="text-muted mb-0">Jump straight into the tools customers use the most.</p>
                            </div>
                            <span class="badge bg-primary-subtle text-primary">{{ count($this->quickActions()) }} tools</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            @forelse($this->quickActions() as $action)
                                <div class="col-md-6">
                                    <div class="card mk-quick-card h-100" style="--mk-tool-color: {{ $this->toolColor($action['tool']) }};">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                                <div class="mk-quick-icon" style="color: {{ $this->toolColor($action['tool']) }};">
                                                    <i class="{{ $action['icon'] }}"></i>
                                                </div>
                                                <span class="badge border border-{{ $this->toolBadgeClass($action['tool']) }} text-{{ $this->toolBadgeClass($action['tool']) }}">
                                                    Enabled
                                                </span>
                                            </div>

                                            <h5 class="mb-2">{{ $action['label'] }}</h5>
                                            <p class="text-muted mb-3">{{ $action['description'] }}</p>

                                            <a wire:navigate.hover href="{{ $action['route'] }}" class="btn btn-sm btn-outline-dark">
                                                {{ $action['cta'] }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="col-12">
                                    <div class="border rounded-4 p-4 text-center bg-light-subtle">
                                        <h6 class="mb-2">No active tools on this plan</h6>
                                        <p class="text-muted mb-3">Your current plan or the global service status is hiding the available tool shortcuts.</p>
                                        <a wire:navigate.hover href="{{ route('app.billing', ['locale' => app()->getLocale()]) }}" class="btn btn-sm btn-outline-dark">
                                            Open Billing
                                        </a>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card mk-section-card h-100">
                    <div class="card-header border-0 pb-0 bg-transparent">
                        <h5 class="card-title mb-1">Account Snapshot</h5>
                        <p class="text-muted mb-0">Key account details without leaving the dashboard.</p>
                    </div>
                    <div class="card-body">
                        <div class="mk-stat-row pt-0">
                            <div>
                                <div class="text-muted small text-uppercase fw-semibold">Plan tier</div>
                                <div class="fw-semibold">{{ $servicePlan?->name ?? 'Free' }}</div>
                            </div>
                        </div>

                        <div class="mk-stat-row">
                            <div>
                                <div class="text-muted small text-uppercase fw-semibold">Storage package</div>
                                <div class="fw-semibold">{{ $storagePlan?->name ?? 'Default Storage' }}</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-semibold">{{ $this->formatBytes($stats['storage_quota_bytes']) }}</div>
                                <div class="small text-muted">available quota</div>
                            </div>
                        </div>

                        <div class="mk-stat-row">
                            <div>
                                <div class="text-muted small text-uppercase fw-semibold">Customer Email Identity</div>
                                <div class="fw-semibold">{{ $customer?->email ?? 'No email found' }}</div>
                            </div>
                            <div class="text-end">
                                <div class="badge bg-{{ $customer?->email_verify ? 'success' : 'warning' }}-subtle text-{{ $customer?->email_verify ? 'success' : 'warning' }}">
                                    {{ $customer?->email_verify ? 'Email verified' : 'Email pending' }}
                                </div><br>
                                <div class="badge bg-{{ $customer?->phone_verify ? 'success' : 'warning' }}-subtle text-{{ $customer?->phone_verify ? 'success' : 'warning' }}">
                                    {{ $customer?->phone_verify ? 'Phone verified' : 'Phone pending' }}
                                </div>
                            </div>
                        </div>

                        <div class="mk-stat-row">
                            <div>
                                <div class="text-muted small text-uppercase fw-semibold">Today</div>
                                <div class="fw-semibold">{{ number_format($stats['completed_today']) }} completed job(s)</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-semibold">{{ $this->formatBytes($stats['storage_out_30']) }}</div>
                                <div class="small text-muted">30 day output volume</div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <a wire:navigate.hover href="{{ route('app.profile', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-primary btn-sm">
                                Edit profile
                            </a>
                            <a wire:navigate.hover href="{{ route('app.billing', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-dark btn-sm">
                                Open billing
                            </a>
                            <a wire:navigate.hover href="{{ route('app.storage', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-info btn-sm">
                                Review storage
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-5">
                <div class="card mk-section-card h-100">
                    <div class="card-header border-0 pb-0 bg-transparent">
                        <h5 class="card-title mb-1">Tool Activity</h5>
                        <p class="text-muted mb-0">Distribution of customer jobs over the last 30 days.</p>
                    </div>
                    <div class="card-body">
                        @if($toolBreakdown->isNotEmpty())
                            <div class="mk-segmented-bar mb-4">
                                @foreach($toolBreakdown as $tool)
                                    @php
                                        $segmentWidth = max(8, round(($tool['jobs'] / $toolTotal) * 100, 2));
                                    @endphp
                                    <div
                                        class="mk-segment"
                                        style="width: {{ $segmentWidth }}%; background: {{ $tool['color'] }};"
                                        title="{{ $tool['label'] }}: {{ number_format($tool['jobs']) }} jobs"
                                    ></div>
                                @endforeach
                            </div>

                            <div class="d-flex flex-column gap-3">
                                @foreach($toolBreakdown as $tool)
                                    @php
                                        $share = (int) round(($tool['jobs'] / $toolTotal) * 100);
                                    @endphp
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="mk-inline-dot" style="background: {{ $tool['color'] }};"></span>
                                                <span class="fw-semibold">{{ $tool['label'] }}</span>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-semibold">{{ number_format($tool['jobs']) }} jobs</div>
                                                <div class="small text-muted">{{ $share }}% of activity</div>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between text-muted small">
                                            <span>{{ number_format($tool['completed']) }} completed</span>
                                            <span>{{ number_format($tool['failed']) }} failed</span>
                                            <span>{{ $this->formatCredits($tool['credits']) }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="mk-empty-state p-4 text-center">
                                <div class="avatar-sm mx-auto mb-3">
                                    <div class="avatar-title bg-light text-dark rounded-circle fs-20">
                                        <i class="ri-bar-chart-box-line"></i>
                                    </div>
                                </div>
                                <h6 class="mb-1">No activity yet</h6>
                                <p class="text-muted mb-3">Once the customer starts using the tools, activity will appear here.</p>
                                @if($this->canOpenTool('tts'))
                                    <a wire:navigate.hover href="{{ route('app.xtts', ['locale' => app()->getLocale()]) }}" class="btn btn-primary btn-sm">
                                        Start with XTTS
                                    </a>
                                @else
                                    <a wire:navigate.hover href="{{ route('app.billing', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-dark btn-sm">
                                        Review Plan Access
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-xl-7">
                <div class="card mk-section-card h-100">
                    <div class="card-header border-0 pb-0 bg-transparent">
                        <div class="d-flex justify-content-between align-items-center gap-3">
                            <div>
                                <h5 class="card-title mb-1">Live Queue</h5>
                                <p class="text-muted mb-0">Active and waiting ML jobs across the customer workspace.</p>
                            </div>
                            <span class="badge bg-warning-subtle text-warning">{{ number_format($stats['active_jobs']) }} active</span>
                        </div>
                    </div>
                    <div class="card-body">
                        @if($this->runningJobs()->isNotEmpty())
                            <div class="d-flex flex-column">
                                @foreach($this->runningJobs() as $job)
                                    @php
                                        $toolCode = (string) ($job->tool?->code ?? $job->job_kind ?? 'unknown');
                                    @endphp
                                    <div class="mk-job-row py-3" wire:key="dashboard-live-job-{{ $job->id }}">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                    <span class="badge bg-{{ $this->toolBadgeClass($toolCode) }}-subtle text-{{ $this->toolBadgeClass($toolCode) }}">
                                                        {{ $this->toolLabel($toolCode) }}
                                                    </span>
                                                    <span class="badge bg-{{ $this->statusBadgeClass($job->status) }}-subtle text-{{ $this->statusBadgeClass($job->status) }}">
                                                        {{ $this->statusLabel($job->status) }}
                                                    </span>
                                                </div>

                                                <div class="fw-semibold mb-1">{{ (string) $job->id }}</div>
                                                <div class="text-muted small">
                                                    Created {{ optional($job->created_at)->diffForHumans() ?: 'just now' }}
                                                    @if($job->started_at)
                                                        | Started {{ optional($job->started_at)->diffForHumans() }}
                                                    @endif
                                                </div>
                                            </div>

                                            <div class="text-end">
                                                <div class="small text-muted mb-1">Charged</div>
                                                <div class="fw-semibold">{{ $this->formatCredits((int) ($job->credits_charged ?? 0)) }}</div>
                                                @if($this->canOpenTool($toolCode))
                                                    <a wire:navigate.hover href="{{ $this->routeForTool($toolCode) }}" class="small text-decoration-underline">
                                                        Open tool
                                                    </a>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="mk-empty-state p-4 text-center">
                                <div class="avatar-sm mx-auto mb-3">
                                    <div class="avatar-title bg-light text-dark rounded-circle fs-20">
                                        <i class="ri-checkbox-circle-line text-success"></i>
                                    </div>
                                </div>
                                <h6 class="mb-1">No active jobs right now</h6>
                                <p class="text-muted mb-3">Queued, running, and saving jobs will appear here automatically.</p>
                                <div class="d-flex justify-content-center gap-2 flex-wrap">
                                    <a wire:navigate.hover href="{{ route('app.stem', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-success btn-sm">Open STEM</a>
                                    <a wire:navigate.hover href="{{ route('app.youtube', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-dark btn-sm">Open YouTube</a>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card mk-section-card mk-table-card">
            <div class="card-header border-0 bg-transparent">
                <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <h5 class="card-title mb-1">Recent Jobs</h5>
                        <p class="text-muted mb-0">The latest customer jobs across XTTS, ASR, STEM, OCR, and YouTube.</p>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary">Auto-updating</span>
                </div>
            </div>

            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle table-nowrap mb-0">
                        <thead class="table-light text-muted">
                            <tr>
                                <th>Tool</th>
                                <th>Job</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Credits</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($this->recentJobs() as $job)
                                @php
                                    $toolCode = (string) ($job->tool?->code ?? $job->job_kind ?? 'unknown');
                                @endphp
                                <tr wire:key="dashboard-recent-job-{{ $job->id }}">
                                    <td>
                                        <span class="badge bg-{{ $this->toolBadgeClass($toolCode) }}-subtle text-{{ $this->toolBadgeClass($toolCode) }}">
                                            {{ $this->toolLabel($toolCode) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ (string) $job->id }}</div>
                                        <div class="small text-muted">
                                            {{ optional($job->created_at)->format('d M Y, h:i A') }}
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $this->statusBadgeClass($job->status) }}-subtle text-{{ $this->statusBadgeClass($job->status) }}">
                                            {{ $this->statusLabel($job->status) }}
                                        </span>
                                    </td>
                                    <td>{{ optional($job->created_at)->diffForHumans() ?: '-' }}</td>
                                    <td>{{ $this->formatCredits((int) ($job->credits_charged ?? 0)) }}</td>
                                    <td class="text-end">
                                        @if($this->canOpenTool($toolCode))
                                            <a wire:navigate.hover href="{{ $this->routeForTool($toolCode) }}" class="btn btn-sm btn-outline-dark">
                                                View tool
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-5 text-center">
                                        <div class="mk-empty-state p-4">
                                            <div class="avatar-sm mx-auto mb-3">
                                                <div class="avatar-title bg-light text-body rounded-circle fs-20">
                                                    <i class="ri-inbox-archive-line"></i>
                                                </div>
                                            </div>
                                            <h6 class="mb-1">No recent jobs yet</h6>
                                            <p class="text-muted mb-0">Start a tool workflow and the latest jobs will appear here.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
