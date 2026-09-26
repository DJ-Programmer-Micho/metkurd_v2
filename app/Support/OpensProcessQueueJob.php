<?php

namespace App\Support;

use App\Models\MlJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Initial navigation only; selection delegates to the existing workspace UI. */
trait OpensProcessQueueJob
{
    abstract protected function processQueueAction(): string;

    abstract protected function selectProcessQueueJob(MlJob $job): void;

    protected function openProcessQueueJob(): void
    {
        $id = request()->query('queue_job');
        if (! is_string($id) || ! Str::isUuid($id)) {
            return;
        }
        $customer = auth('app')->user();
        $action = $this->processQueueAction();
        abort_unless($customer?->isAllowed($action, 'app'), 403);
        $job = app(CustomerProcessQueue::class)->appJobs($customer)
            ->whereHas('toolAction', fn ($query) => $query->where('full_code', $action))
            ->whereIn('status', ['queued', 'running', 'saving', 'done', 'failed', 'cancelled', 'canceled'])
            ->find($id);
        if ($job) {
            $this->selectProcessQueueJob($job);
        }
    }

    protected function processQueueHistoryPage(Builder $history, MlJob $job, string $page, string $column = 'updated_at', int $size = 3): void
    {
        $newer = $history->where(fn ($q) => $q->where($column, '>', $job->{$column})
            ->orWhere(fn ($tie) => $tie->where($column, $job->{$column})->where('id', '>', $job->id)))->count();
        $this->setPage(intdiv($newer, $size) + 1, $page);
    }
}
