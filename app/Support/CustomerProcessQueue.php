<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\MlJob;
use Illuminate\Database\Eloquent\Builder;

/** Read-only shell projection. Never reconcile jobs or inspect result storage here. */
class CustomerProcessQueue
{
    public const LIMIT = 12;

    public function definitions(): array
    {
        $definitions = [];
        foreach (app(MetKurdV2ToolCatalog::class)->services() as $service => $group) {
            foreach ($group['tools'] as $slug => $tool) {
                if (isset($tool['legacy_action']) && ! ($tool['coming_soon'] ?? false)) {
                    $definitions[$tool['legacy_action']] = $tool + ['service' => $service, 'slug' => $slug];
                }
            }
        }

        return $definitions;
    }

    public function appJobs(Customer $customer): Builder
    {
        return MlJob::query()->where('ml_jobs.customer_id', $customer->id)
            ->whereNull('input->api_job_id')
            ->where(fn ($q) => $q->whereNull('input->wallet_type')->orWhere('input->wallet_type', '!=', 'api'))
            ->where(function ($q) {
                $q->whereIn('endpoint_key', ['omni_v2', 'qasr_v2', 'kocr_v2', 'tashkeel_v1'])
                    ->orWhere('input->workspace', 'stem_v2');
            });
    }

    public function read(Customer $customer): array
    {
        $definitions = $this->definitions();
        $rows = $this->appJobs($customer)
            ->join('tool_actions as queue_action', 'queue_action.id', '=', 'ml_jobs.tool_action_id')
            ->whereIn('queue_action.full_code', array_keys($definitions))
            ->where(function ($q) {
                $q->where(fn ($active) => $active->active())->orWhere(function ($terminal) {
                    $terminal->whereIn('ml_jobs.status', ['done', 'failed', 'cancelled', 'canceled'])
                        ->where('ml_jobs.updated_at', '>=', now()->subDay());
                });
            })
            ->select(['ml_jobs.id', 'ml_jobs.status', 'ml_jobs.created_at', 'ml_jobs.finished_at', 'ml_jobs.updated_at', 'queue_action.full_code as action'])
            ->orderByRaw("CASE WHEN ml_jobs.status IN ('queued','running','saving') THEN 0 ELSE 1 END")
            ->orderByDesc('ml_jobs.updated_at')->orderByDesc('ml_jobs.id')
            ->limit(self::LIMIT + 1)->get();
        $jobs = $rows->take(self::LIMIT)->map(function ($job) use ($definitions) {
            $definition = $definitions[$job->action];
            $status = (string) $job->status;
            $time = $job->finished_at ?? $job->updated_at;

            return [
                'id' => (string) $job->id,
                'label' => $definition['kind'] === 'stem' ? __('process_queue.stem', ['count' => $definition['stems']]) : __($definition['name']),
                'status' => $status,
                'status_label' => __('process_queue.'.match ($status) {
                    'done' => 'ready', 'running' => 'processing', 'canceled' => 'cancelled', default => $status
                }),
                'terminal_key' => in_array($status, ['done', 'failed'], true) ? $job->id.':'.$status.':'.$time?->toIso8601String() : null,
                'when' => ($job->finished_at ?? $job->created_at)?->format('Y-m-d H:i'),
                'url' => $this->workspaceUrl($definition, (string) $job->id),
            ];
        })->values()->all();

        return ['jobs' => $jobs, 'has_active' => collect($jobs)->contains(fn ($job) => in_array($job['status'], ['queued', 'running', 'saving'], true)), 'truncated' => $rows->count() > self::LIMIT];
    }

    private function workspaceUrl(array $definition, string $id): string
    {
        $parameters = ['locale' => app()->getLocale(), 'queue_job' => $id];

        return match ($definition['kind']) {
            'stem' => route('app.v2.stem', $parameters + ['mode' => $definition['stems']]),
            'kocr' => route('app.v2.ocr', $parameters),
            'qasr' => route('app.v2.leo', $parameters),
            'caption' => route('app.v2.caption', $parameters),
            'harakat' => route('app.v2.harakat', $parameters),
            'omni_tts_batch' => route('app.v2.zeta', $parameters),
            'omni_clone_batch' => route('app.v2.theta', $parameters),
            default => route('app.v2.tool', $parameters + ['service' => $definition['service'], 'tool' => $definition['slug']]),
        };
    }
}
