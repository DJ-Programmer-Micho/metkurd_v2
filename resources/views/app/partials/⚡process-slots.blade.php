<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
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

    public function mount(): void
    {
        $this->refreshKey++;
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('xtts-renders-refresh')]
    public function refreshSlots(): void
    {
        $this->refreshKey++;
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
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        foreach ($jobs as $job) {
            try {
                $tool = Tool::find($job->tool_id);

                if (!$tool) {
                    continue;
                }

                // Phase 1: XTTS only
                if ((string) $tool->code === 'tts') {
                    $this->syncXttsJob($job, $tool, $runpod, $storage);
                }
            } catch (\Throwable $e) {
                Log::warning('PROCESS_SLOT_SYNC_FAIL', [
                    'job_id' => $job->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $this->refreshKey++;
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
        $out       = (array) data_get($st, 'output', []);

        $wavB64 = (string) (
            data_get($out, 'wav_b64', '')
            ?: data_get($out, 'wav_base64', '')
            ?: data_get($out, 'audio_b64', '')
            ?: data_get($out, 'audio_base64', '')
            ?: data_get($st, 'output.wav_b64', '')
            ?: data_get($st, 'output.audio_b64', '')
        );

        $mapped = match ($rawStatus) {
            'IN_QUEUE', 'QUEUED'              => 'queued',
            'IN_PROGRESS', 'RUNNING'          => 'running',
            'COMPLETED'                       => 'saving',
            'FAILED', 'CANCELLED', 'TIMED_OUT' => 'failed',
            default                           => 'running',
        };

        if ($mapped === 'failed') {
            $err = (string) (
                data_get($st, 'error', '')
                ?: data_get($out, 'error', '')
                ?: 'RunPod failed'
            );

            MlJob::where('id', $job->id)->update([
                'status'      => 'failed',
                'error'       => ['message' => $err],
                'finished_at' => now(),
            ]);

            $this->dispatch('xtts-renders-refresh');
            $this->dispatch('header:refresh');
            return;
        }

        MlJob::where('id', $job->id)->update([
            'status' => $mapped,
        ]);

        if ($rawStatus === 'COMPLETED' && $wavB64 === '') {
            // keep waiting; RunPod sometimes completes slightly before output is readable
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
            'tool'   => 'tts',
        ]);

        DB::transaction(function () use ($job, $saved, $customer) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (!$fresh || (string) $fresh->status === 'done') {
                return;
            }

            $fresh->status = 'done';
            $fresh->output = [
                'disk'  => $saved['disk'],
                'path'  => $saved['path'],
                'bytes' => $saved['bytes'],
                'mime'  => 'audio/wav',
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

    #[Computed]
    public function jobs()
    {
        $customerId = auth('app')->id();

        if (!$customerId) {
            return collect();
        }

        return MlJob::query()
            ->where('customer_id', $customerId)
            ->orderByRaw("
                CASE
                    WHEN status IN ('queued','running','saving') THEN 0
                    WHEN status = 'done' THEN 1
                    WHEN status = 'failed' THEN 2
                    ELSE 3
                END
            ")
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(function ($job) {
                $tool = Tool::find($job->tool_id);
                $toolCode = (string) ($tool?->code ?? '');

                $route = match ($toolCode) {
                    'tts' => route('app.xtts', ['locale' => app()->getLocale()]),
                    default => route('app.home', ['locale' => app()->getLocale()]),
                };

                $label = match ($toolCode) {
                    'tts' => 'XTTS',
                    default => strtoupper($toolCode ?: 'JOB'),
                };

                return [
                    'id'         => (string) $job->id,
                    'tool_code'  => $toolCode,
                    'label'      => $label,
                    'status'     => (string) $job->status,
                    'route'      => $route,
                    'created_at' => optional($job->created_at)->format('H:i'),
                ];
            });
    }

    #[Computed]
    public function slots(): array
    {
        $jobs = $this->jobs->values();

        return collect(range(0, 9))->map(function ($i) use ($jobs) {
            $job = $jobs->get($i);

            if (!$job) {
                return [
                    'idle'       => true,
                    'status'     => 'idle',
                    'label'      => 'Idle',
                    'title'      => 'Empty slot',
                    'route'      => null,
                    'dotClass'   => 'bg-secondary-subtle border border-secondary-subtle',
                    'cardClass'  => 'border-secondary-subtle bg-secondary-subtle bg-opacity-10',
                    'textClass'  => 'text-muted',
                ];
            }

            [$dotClass, $cardClass, $textClass] = match ($job['status']) {
                'queued'  => ['bg-warning', 'border-warning-subtle', 'text-warning'],
                'running' => ['bg-info', 'border-info-subtle', 'text-info'],
                'saving'  => ['bg-primary', 'border-primary-subtle', 'text-primary'],
                'done'    => ['bg-success', 'border-success-subtle', 'text-success'],
                'failed'  => ['bg-danger', 'border-danger-subtle', 'text-danger'],
                default   => ['bg-secondary', 'border-secondary-subtle', 'text-muted'],
            };

            return [
                'idle'       => false,
                'status'     => $job['status'],
                'label'      => $job['label'],
                'title'      => "{$job['label']} • {$job['status']}",
                'route'      => $job['route'],
                'dotClass'   => $dotClass,
                'cardClass'  => $cardClass,
                'textClass'  => $textClass,
            ];
        })->all();
    }

    public function render()
    {
        
        return view('app.partials.⚡process-slots');
    }
};
?>

<div wire:key="process-slots-{{ $refreshKey }}">
    <div wire:poll.keep-alive.3000ms="pollJobs"></div>

    <div class="d-none d-xl-block me-2">
        <div class="px-2 py-1 rounded-3 border border-secondary-subtle"
             style="min-width: 220px; background: rgba(255,255,255,.03);">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted">Processes</small>
                <small class="text-muted">{{ collect($this->slots)->where('idle', false)->count() }}/10</small>
            </div>

            <div class="row g-1">
                @foreach($this->slots as $slot)
                    <div class="col-2">
                        @if($slot['route'])
                            <a href="{{ $slot['route'] }}"
                               wire:navigate.hover
                               class="d-flex align-items-center justify-content-center rounded-2 border {{ $slot['cardClass'] }} text-decoration-none"
                               title="{{ $slot['title'] }}"
                               style="height: 26px;">
                                <span class="rounded-circle {{ $slot['dotClass'] }}"
                                      style="width:10px;height:10px;display:inline-block;"></span>
                            </a>
                        @else
                            <div class="d-flex align-items-center justify-content-center rounded-2 border {{ $slot['cardClass'] }}"
                                 title="{{ $slot['title'] }}"
                                 style="height: 26px;">
                                <span class="rounded-circle {{ $slot['dotClass'] }}"
                                      style="width:10px;height:10px;display:inline-block;"></span>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>