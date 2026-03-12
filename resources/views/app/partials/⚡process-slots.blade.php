<?php

use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Facades\Log;
use App\Models\MlJob;
use App\Services\XTTS\XttsJobSyncService;

new class extends Component {
    public int $refreshKey = 0;
    public int $maxSlots = 5;
    public int $allowedSlots = 2;
    public array $slotsData = [];

    public function mount(): void
    {
        $this->hydrateBoard();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('xtts-renders-refresh')]
    #[On('clone-xtts-renders-refresh')]
    #[On('wasr-renders-refresh')]
    #[On('asr-renders-refresh')]
    public function refreshSlots(): void
    {
        $this->hydrateBoard();
    }

    protected function hydrateBoard(): void
    {
        $customer = auth('app')->user();

        if (!$customer) {
            $this->allowedSlots = 2;
            $this->slotsData = $this->buildEmptySlots(2);
            $this->refreshKey++;
            return;
        }

        $this->allowedSlots = $this->resolveAllowedSlots($customer);

        $jobs = MlJob::query()
            ->with('tool:id,code')
            ->where('customer_id', (int) $customer->id)
            ->where(function ($q) {
                $q->where(function ($qa) {
                    $qa->whereIn('status', ['queued', 'running', 'saving'])
                    ->whereNotNull('lock_expires_at')
                    ->where('lock_expires_at', '>', now());
                })->orWhere(function ($q2) {
                    $q2->whereIn('status', ['done', 'failed'])
                    ->where('finished_at', '>=', now()->subSeconds(3));
                });
            })
            ->orderBy('created_at')
            ->limit($this->allowedSlots)
            ->get();

        $items = $jobs->map(function ($job) {
            $toolCode = (string) ($job->tool?->code ?? '');

            $route = match ($toolCode) {
                'tts'       => route('app.xtts', ['locale' => app()->getLocale()]),
                'clone_tts' => route('app.clone-xtts', ['locale' => app()->getLocale()]),
                'wasr','asr'=> route('app.wasr', ['locale' => app()->getLocale()]),
                'ocr'       => route('app.ocr', ['locale' => app()->getLocale()]),
                'stem'      => route('app.stem', ['locale' => app()->getLocale()]),
                default     => route('app.home', ['locale' => app()->getLocale()]),
            };

            return [
                'route'       => $route,
                'title'       => strtoupper($toolCode ?: 'JOB') . ' • ' . strtoupper((string) $job->status),
                'isClickable' => true,
                'cellClass'   => match ((string) $job->status) {
                    'queued'  => 'mk-slot-queued',
                    'running' => 'mk-slot-running',
                    'saving'  => 'mk-slot-saving',
                    'done'    => 'mk-slot-done',
                    'failed'  => 'mk-slot-failed',
                    default   => 'mk-slot-idle',
                },
            ];
        })->values()->all();

        $slots = [];

        for ($i = 0; $i < $this->maxSlots; $i++) {
            if ($i < $this->allowedSlots) {
                $slots[] = $items[$i] ?? [
                    'route'       => null,
                    'title'       => 'Available slot',
                    'isClickable' => false,
                    'cellClass'   => 'mk-slot-idle',
                ];
            } else {
                $slots[] = [
                    'route'       => null,
                    'title'       => 'Locked by plan',
                    'isClickable' => false,
                    'cellClass'   => 'mk-slot-locked',
                ];
            }
        }

        $this->slotsData = $slots;
        $this->refreshKey++;
    }

    protected function resolveAllowedSlots($customer): int
    {
        return match (strtolower((string) ($customer?->serviceCode() ?? 'free'))) {
            'student' => 2,
            'pro'     => 3,
            'premium' => 5,
            default   => 2,
        };
    }

    protected function buildEmptySlots(int $allowed): array
    {
        $slots = [];

        for ($i = 0; $i < $this->maxSlots; $i++) {
            $slots[] = $i < $allowed
                ? [
                    'route' => null,
                    'title' => 'Available slot',
                    'isClickable' => false,
                    'cellClass' => 'mk-slot-idle',
                ]
                : [
                    'route' => null,
                    'title' => 'Locked by plan',
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
@php
    $type = $type ?? null;
    $status = $status ?? null;
    $jobId = $jobId ?? null;

    $label = match ($type) {
        'stem' => 'STEM job status',
        'wasr', 'asr' => 'ASR job status',
        'tts', 'clone_tts' => 'TTS job status',
        default => 'Job status',
    };

    $message = match ($status) {
        'queued'  => 'Queued...',
        'running' => $type === 'stem' ? 'Separating Audio...' : 'Running...',
        'saving'  => 'Saving output...',
        'done'    => 'Completed.',
        'failed'  => 'Failed.',
        default   => 'Processing...',
    };

    $glassClass = match ($status) {
        'queued'  => 'glass-warning',
        'running' => 'glass-info',
        'saving'  => 'glass-primary',
        'done'    => 'glass-success',
        'failed'  => 'glass-danger',
        default   => 'glass-info',
    };
@endphp
<div wire:key="process-slots-{{ $refreshKey }}" class="d-flex align-items-center">
    {{-- <div wire:poll.keep-alive.2500ms="pollJobs"></div> --}}

    <div class="d-flex align-items-center gap-2 px-2 py-2 rounded-4"
         style="background: rgba(0,0,0,.22); box-shadow: inset 0 1px 0 rgba(255,255,255,.04);">
        @foreach($slotsData as $slot)
            @if($slot['isClickable'] && $slot['route'])
                <a href="{{ $slot['route'] }}"
                   wire:navigate.hover
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
    .mk-slot-done{
        background:#37c759;
        box-shadow:0 0 0 1px rgba(55,199,89,.18), 0 3px 10px rgba(55,199,89,.18);
    }
    .mk-slot-queued{
        background:#ffd60a;
        box-shadow:0 0 0 1px rgba(255,214,10,.18), 0 3px 10px rgba(255,214,10,.14);
    }
    .mk-slot-running{
        background:#0a84ff;
        box-shadow:0 0 0 1px rgba(10,132,255,.18), 0 3px 10px rgba(10,132,255,.18);
        animation: mkSlotPulse 1.25s ease-in-out infinite;
    }
    .mk-slot-saving{
        background:#5e5ce6;
        box-shadow:0 0 0 1px rgba(94,92,230,.18), 0 3px 10px rgba(94,92,230,.18);
    }
    .mk-slot-failed{
        background:#ff453a;
        box-shadow:0 0 0 1px rgba(255,69,58,.18), 0 3px 10px rgba(255,69,58,.18);
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
            transform:scaleY(1);
            box-shadow:0 0 0 1px rgba(10,132,255,.18), 0 3px 10px rgba(10,132,255,.18);
        }
        50%{
            transform:scaleY(1.06);
            box-shadow:0 0 0 2px rgba(10,132,255,.28), 0 5px 14px rgba(10,132,255,.24);
        }
    }
</style>