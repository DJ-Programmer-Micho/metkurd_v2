<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\MetKurd\V2\HarakatInput;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Plans\PlanConcurrencyService;
use Illuminate\Support\Str;

class HarakatSubmissionService
{
    public function quote(Customer $customer, int $characters, string $channel = 'app'): int
    {
        return $characters > 0 ? (int) $customer->priceCreditsFor('harakat.diacritize', [
            'channel' => $channel, 'metric_code' => 'character', 'chars' => $characters, 'language' => 'ar',
        ]) : 0;
    }

    public function submit(Customer $customer, string $key, mixed $text, ?SubmissionContext $context = null): MlJob
    {
        $context ??= new SubmissionContext;
        if (trim($key) === '' || strlen($key) > 64) {
            throw new \InvalidArgumentException('A valid submission identity is required.');
        }
        $input = app(HarakatInput::class)->prepare($text);
        $tool = Tool::query()->where('code', 'harakat')->where('is_active', true)->firstOrFail();
        $action = ToolAction::query()->where('tool_code', 'harakat')->where('full_code', 'harakat.diacritize')->where('is_active', true)->firstOrFail();
        abort_unless($customer->isAllowed($action->full_code, $context->channel()), 403);
        $hash = hash('sha256', json_encode([$input, $context->channel(), $context->apiJob?->id], JSON_THROW_ON_ERROR));
        $cost = $this->quote($customer, $input['characters'], $context->channel());
        if ($cost <= 0) {
            throw new \RuntimeException('Pricing is not configured for this service.');
        }
        $durable = app(DurableUploadSubmission::class);
        [$job, $created] = $durable->begin((int) $customer->id, $key, $action->full_code, $cost, 'harakat_charge',
            ['chars' => $input['characters']], function () use ($customer, $tool, $action, $cost, $input, $hash, $context) {
                if ($context->channel() === 'app' && MlJob::query()->where('customer_id', $customer->id)->whereNull('input->api_job_id')->active()->count()
                    >= app(PlanConcurrencyService::class)->allowedConcurrentJobsForCustomer($customer)) {
                    throw new \RuntimeException('Your concurrent job limit has been reached. Wait for the current project to finish.');
                }

                return MlJob::create([
                    'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $tool->id, 'tool_action_id' => $action->id,
                    'job_kind' => 'harakat', 'status' => 'queued', 'provider' => 'runpod', 'endpoint_key' => 'tashkeel_v1',
                    'credits_charged' => $cost, 'input' => $input + ['source_mode' => 'text', 'request_hash' => $hash, 'v2' => true],
                ]);
            }, $context);
        if (! hash_equals((string) data_get($job->input, 'request_hash'), $hash)) {
            throw new \RuntimeException('This submission identity already belongs to a different project.');
        }
        if (! $created) {
            return $job;
        }
        try {
            $response = app(RunPodV2Adapter::class)->harakat((string) $job->id, $input['text'],
                fn () => $job->update(['submission_attempted_at' => now()]));
            $providerId = trim((string) ($response['id'] ?? ''));
            if ($providerId === '') {
                throw new \RuntimeException('Provider submission did not return an identity.');
            }
            $job->update(['provider_job_id' => $providerId, 'status' => 'running', 'started_at' => now(), 'failure_stage' => null]);
        } catch (\Throwable $exception) {
            $durable->failed($job, $exception);
        }

        return $job->fresh();
    }
}
