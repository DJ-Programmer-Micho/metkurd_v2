<?php

use App\Models\MlJob;
use App\Support\AppShellData;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public int $maxSlots = 5;
    public int $allowedSlots = 2;
    public int $activeJobs = 0;
    public array $slotsData = [];

    public function mount(): void
    {
        $this->hydrateBoard();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    public function refreshSlots(): void
    {
        $this->hydrateBoard();
    }

    public function pollJobs(): void
    {
        $this->hydrateBoard();
    }

    protected function hydrateBoard(): void
    {
        $shell = app(AppShellData::class)->forCurrentCustomer();
        $customer = $shell['customer'] ?? null;

        if (! $customer) {
            $this->allowedSlots = 2;
            $this->activeJobs = 0;
            $this->slotsData = $this->buildEmptySlots(2);
            return;
        }

        $this->allowedSlots = max(1, (int) ($shell['allowed_slots'] ?? 2));
        $accessMap = (array) ($shell['access_map'] ?? []);

        if ((int) ($shell['active_jobs'] ?? 0) <= 0) {
            $this->activeJobs = 0;
            $this->slotsData = $this->buildEmptySlots($this->allowedSlots);
            return;
        }

        $jobs = MlJob::query()
            ->with('tool:id,code')
            ->select(['id', 'customer_id', 'tool_id', 'status', 'lock_expires_at', 'created_at', 'updated_at'])
            ->where('customer_id', (int) $customer->id)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->where(function ($query) {
                $query->where(function ($live) {
                    $live->whereNotNull('lock_expires_at')
                        ->where('lock_expires_at', '>', now());
                })->orWhere(function ($fresh) {
                    $fresh->whereNull('lock_expires_at')
                        ->where('created_at', '>=', now()->subSeconds(15));
                });
            })
            ->orderByRaw("
                CASE
                    WHEN status = 'running' THEN 0
                    WHEN status = 'saving' THEN 1
                    WHEN status = 'queued' THEN 2
                    ELSE 3
                END
            ")
            ->orderByDesc('updated_at')
            ->limit($this->allowedSlots)
            ->get();

        $this->activeJobs = $jobs->count();

        $items = $jobs->map(function ($job) use ($accessMap) {
            $toolCode = (string) ($job->tool?->code ?? '');
            $normalizedToolCode = match ($toolCode) {
                'wasr' => 'asr',
                default => $toolCode,
            };
            $isAccessible = $normalizedToolCode !== '' && (bool) ($accessMap[$normalizedToolCode] ?? false);

            $route = $isAccessible ? match ($toolCode) {
                'tts' => route('app.xtts', ['locale' => app()->getLocale()]),
                'xomni' => route('app.xomni', ['locale' => app()->getLocale()]),
                'ftts' => route('app.f5tts', ['locale' => app()->getLocale()]),
                'clone_tts' => route('app.clone-xtts', ['locale' => app()->getLocale()]),
                'clone_xomni' => route('app.clone-xomni', ['locale' => app()->getLocale()]),
                'qasr' => route('app.qasr', ['locale' => app()->getLocale()]),
                'tran' => route('app.tran', ['locale' => app()->getLocale()]),
                'wasr', 'asr' => route('app.wasr', ['locale' => app()->getLocale()]),
                'ocr' => route('app.ocr', ['locale' => app()->getLocale()]),
                'stem' => route('app.stem', ['locale' => app()->getLocale()]),
                'youtube_audio', 'youtube_video' => route('app.youtube', ['locale' => app()->getLocale()]),
                default => route('app.home', ['locale' => app()->getLocale()]),
            } : null;

            return [
                'route' => $route,
                'title' => strtoupper($toolCode ?: 'job') . ' - ' . strtoupper((string) $job->status),
                'isClickable' => $isAccessible && $route !== null,
                'cellClass' => match ((string) $job->status) {
                    'queued' => 'mk-slot-queued',
                    'running' => 'mk-slot-running',
                    'saving' => 'mk-slot-saving',
                    default => 'mk-slot-idle',
                },
            ];
        })->values()->all();

        $slots = [];

        for ($i = 0; $i < $this->maxSlots; $i++) {
            if ($i < $this->allowedSlots) {
                $slots[] = $items[$i] ?? [
                    'route' => null,
                    'title' => __('Available slot'),
                    'isClickable' => false,
                    'cellClass' => 'mk-slot-idle',
                ];
            } else {
                $slots[] = [
                    'route' => null,
                    'title' => __('Locked by plan'),
                    'isClickable' => false,
                    'cellClass' => 'mk-slot-locked',
                ];
            }
        }

        $this->slotsData = $slots;
    }

    protected function buildEmptySlots(int $allowed): array
    {
        $slots = [];

        for ($i = 0; $i < $this->maxSlots; $i++) {
            $slots[] = $i < $allowed
                ? [
                    'route' => null,
                    'title' => __('Available slot'),
                    'isClickable' => false,
                    'cellClass' => 'mk-slot-idle',
                ]
                : [
                    'route' => null,
                    'title' => __('Locked by plan'),
                    'isClickable' => false,
                    'cellClass' => 'mk-slot-locked',
                ];
        }

        return $slots;
    }

    public function render()
    {
        return view('app.partials.⚡process-slots');
    }
};
?>
<div class="d-flex align-items-center gap-2">
    @if($activeJobs > 0)
        <div wire:poll.visible.8000ms="pollJobs"></div>
    @endif

    <div class="mk-slot-summary" aria-label="{{ __('Active jobs') }}">
        <span class="mk-slot-summary__count">{{ $activeJobs }}</span>
        <span class="mk-slot-summary__label">{{ __('of :count', ['count' => $allowedSlots]) }}</span>
    </div>

    <div class="d-flex align-items-center gap-2 px-2 py-2 rounded-4 mk-slot-board"
         aria-label="{{ __('Process queue') }}">
        @foreach($slotsData as $slot)
            @if($slot['isClickable'] && $slot['route'])
                <a href="{{ $slot['route'] }}"
                   wire:navigate
                   wire:key="process-slot-{{ $loop->index }}-{{ $slot['cellClass'] }}"
                   title="{{ $slot['title'] }}"
                   aria-label="{{ $slot['title'] }}"
                   class="{{ $slot['cellClass'] }}"
                   style="
                        width:16px;
                        height:30px;
                        border-radius:7px;
                        display:inline-block;
                        flex:0 0 auto;
                        text-decoration:none;
                        border:1px solid rgba(255,255,255,.06);
                   "></a>
            @else
                <div title="{{ $slot['title'] }}"
                     wire:key="process-slot-{{ $loop->index }}-{{ $slot['cellClass'] }}"
                     aria-label="{{ $slot['title'] }}"
                     class="{{ $slot['cellClass'] }}"
                     style="
                        width:16px;
                        height:30px;
                        border-radius:7px;
                        display:inline-block;
                        flex:0 0 auto;
                        border:1px solid rgba(255,255,255,.06);
                     "></div>
            @endif
        @endforeach
    </div>
</div>

<style>
    .mk-slot-summary{
        min-width: 58px;
        padding: .35rem .55rem;
        border-radius: 999px;
        background: rgba(0, 0, 0, .22);
        border: 1px solid rgba(255,255,255,.06);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
        color: rgba(255,255,255,.92);
        font-size: .78rem;
        line-height: 1;
        white-space: nowrap;
    }

    .mk-slot-summary__count{
        font-weight: 700;
    }

    .mk-slot-summary__label{
        color: rgba(255,255,255,.68);
    }

    .mk-slot-board{
        background: rgba(0,0,0,.22);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
    }

    .mk-slot-queued{
        background:#ffd60a;
        box-shadow:0 0 0 1px rgba(255,214,10,.18), 0 3px 10px rgba(255,214,10,.14);
    }

    .mk-slot-running{
        background:#0a84ff;
        box-shadow:0 0 0 1px rgba(10,132,255,.18), 0 3px 10px rgba(10,132,255,.18);
        animation: mkSlotPulse 1.6s ease-in-out infinite;
    }

    .mk-slot-saving{
        background:#5e5ce6;
        box-shadow:0 0 0 1px rgba(94,92,230,.18), 0 3px 10px rgba(94,92,230,.18);
    }

    .mk-slot-idle{
        background:#f2f2f7;
        box-shadow:0 0 0 1px rgba(255,255,255,.04);
    }

    .mk-slot-locked{
        background:#48484a;
        opacity:.82;
        box-shadow:0 0 0 1px rgba(255,255,255,.03);
    }

    @keyframes mkSlotPulse{
        0%,100%{
            opacity:1;
            box-shadow:0 0 0 1px rgba(10,132,255,.18), 0 3px 10px rgba(10,132,255,.18);
        }

        50%{
            opacity:.82;
            box-shadow:0 0 0 2px rgba(10,132,255,.28), 0 5px 14px rgba(10,132,255,.24);
        }
    }
</style>
