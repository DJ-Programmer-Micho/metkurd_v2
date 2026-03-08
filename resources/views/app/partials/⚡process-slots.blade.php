<?php

use Livewire\Component;
use Livewire\Attributes\On;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\MlJob;
use App\Models\Tool;
use App\Models\CustomerUsage;
use App\Services\Providers\RunPodProvider;
use App\Services\Storage\CustomerOutputStorage;

new class extends Component
{
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
    public function refreshSlots(): void
    {
        $this->hydrateBoard();
    }

    public function pollJobs(RunPodProvider $runpod, CustomerOutputStorage $storage): void
    {
        $customer = auth('app')->user();
        if (!$customer) {
            return;
        }

        $jobs = MlJob::query()
            ->where('customer_id', (int) $customer->id)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->orderBy('created_at')
            ->limit($this->maxSlots)
            ->get();

        foreach ($jobs as $job) {
            try {
                $tool = Tool::find($job->tool_id);

                if (!$tool) {
                    continue;
                }

                if ((string) $tool->code === 'tts') {
                    $this->syncXttsJob($job, $tool, $runpod, $storage);
                }
            } catch (\Throwable $e) {
                Log::warning('PROCESS_SLOT_SYNC_FAIL', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
            ->where('customer_id', (int) $customer->id)
            ->where(function ($q) {
                $q->whereIn('status', ['queued', 'running', 'saving'])
                ->orWhere(function ($q2) {
                    $q2->whereIn('status', ['done', 'failed'])
                        ->where('finished_at', '>=', now()->subSeconds(3));
                });
            })
            ->orderBy('created_at')
            ->limit($this->allowedSlots)
            ->get();

        $items = $jobs->map(function ($job) {
            $tool = Tool::find($job->tool_id);
            $toolCode = (string) ($tool?->code ?? '');

            $route = match ($toolCode) {
                'tts'   => route('app.xtts', ['locale' => app()->getLocale()]),
                'wasr'  => route('app.wasr', ['locale' => app()->getLocale()]),
                'ocr'   => route('app.ocr', ['locale' => app()->getLocale()]),
                'stem'  => route('app.stem', ['locale' => app()->getLocale()]),
                default => route('app.home', ['locale' => app()->getLocale()]),
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
        $planCode = strtolower((string) ($customer?->serviceCode() ?? 'free'));

        return match ($planCode) {
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

    protected function syncXttsJob(
        MlJob $job,
        Tool $tool,
        RunPodProvider $runpod,
        CustomerOutputStorage $storage
    ): void {
        if (in_array((string) $job->status, ['done', 'failed', 'deleted', 'deleting'], true)) {
            return;
        }

        $endpointId = data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.xtts');
        if (!$endpointId) {
            return;
        }

        $rpId = (string) $job->provider_job_id;
        if ($rpId === '') {
            return;
        }

        $st = $runpod->status($endpointId, $rpId);

        $rawStatus = strtoupper((string) data_get($st, 'status', ''));
        $out = (array) data_get($st, 'output', []);

        $wavB64 = (string) (
            data_get($out, 'wav_b64', '')
            ?: data_get($out, 'wav_base64', '')
            ?: data_get($out, 'audio_b64', '')
            ?: data_get($out, 'audio_base64', '')
            ?: data_get($st, 'output.wav_b64', '')
            ?: data_get($st, 'output.audio_b64', '')
        );

        $mapped = match ($rawStatus) {
            'IN_QUEUE', 'QUEUED'                => 'queued',
            'IN_PROGRESS', 'RUNNING'            => 'running',
            'COMPLETED'                         => 'saving',
            'FAILED', 'CANCELLED', 'TIMED_OUT' => 'failed',
            default                             => 'running',
        };

        if ($mapped === 'failed') {
            $err = (string) (
                data_get($st, 'error', '')
                ?: data_get($out, 'error', '')
                ?: 'RunPod failed'
            );

            MlJob::where('id', $job->id)->update([
                'status' => 'failed',
                'error' => ['message' => $err],
                'finished_at' => now(),
            ]);

            return;
        }

        MlJob::where('id', $job->id)->update([
            'status' => $mapped,
        ]);

        if ($rawStatus === 'COMPLETED' && $wavB64 === '') {
            return;
        }

        if ($wavB64 === '') {
            return;
        }

        $customer = auth('app')->user();
        if (!$customer) {
            return;
        }

        $folder = \App\Support\CustomerFolder::make(
            (int) $customer->id,
            $customer->profile?->first_name ?? $customer->first_name ?? null,
            $customer->profile?->last_name ?? $customer->last_name ?? null,
            $customer->username ?? null
        );

        $fileKey = "renders/{$folder}/tts/{$job->id}/out.wav";

        $saved = $storage->saveWavB64ToS3((int) $customer->id, $fileKey, $wavB64, [
            'job_id' => $job->id,
            'tool' => 'tts',
        ]);

        DB::transaction(function () use ($job, $saved, $customer) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (!$fresh || (string) $fresh->status === 'done') {
                $this->dispatch('alert', type: 'success', message: 'Job Done!');
                return;
            }

            $fresh->status = 'done';
            $fresh->output = [
                'disk' => $saved['disk'],
                'path' => $saved['path'],
                'bytes' => $saved['bytes'],
                'mime' => 'audio/wav',
            ];
            $fresh->storage_out_bytes = (int) $saved['bytes'];
            $fresh->finished_at = now();
            $fresh->error = null;
            $fresh->save();

            $usage = CustomerUsage::query()->lockForUpdate()->firstOrCreate(
                ['customer_id' => (int) $customer->id],
                ['storage_used_bytes' => 0, 'jobs_total' => 0, 'jobs_succeeded' => 0, 'jobs_failed' => 0]
            );

            $usage->storage_used_bytes = (int) $usage->storage_used_bytes + (int) $saved['bytes'];
            $usage->jobs_total = (int) $usage->jobs_total + 1;
            $usage->jobs_succeeded = (int) $usage->jobs_succeeded + 1;
            $usage->save();
        }, 3);

        $this->dispatch('customerStorageUpdated');
        $this->dispatch('customerPlanUpdated');
        $this->dispatch('xtts-renders-refresh');
        $this->dispatch('header:refresh');
    }

    public function render()
    {
        return view('app.partials.⚡process-slots');
    }
};
?>

<div wire:key="process-slots-{{ $refreshKey }}" class="d-flex align-items-center">
    <div wire:poll.keep-alive.2500ms="pollJobs"></div>

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