<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\MetKurd\V2\CttsWorkspaceCache;
use App\Services\MetKurd\V2\MultiSpeakerInput;
use App\Services\MetKurd\V2\MultiSpeakerReferences;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Support\Str;

class MultiSpeakerSubmissionService
{
    public function submit(Customer $customer, string $service, string $slug, string $key, array $segments, ?SubmissionContext $context = null): MlJob
    {
        $context ??= new SubmissionContext;
        $definition = app(MetKurdV2ToolCatalog::class)->tool($service, $slug);
        abort_unless(in_array($definition['kind'] ?? '', ['omni_tts_batch', 'omni_clone_batch'], true), 404);
        if (trim($key) === '' || strlen($key) > 64) {
            throw new \InvalidArgumentException('A valid submission identity is required.');
        }
        $clone = $definition['kind'] === 'omni_clone_batch';
        $action = ToolAction::query()->where('full_code', $definition['legacy_action'])->where('tool_code', $definition['legacy_tool'])->where('is_active', true)->firstOrFail();
        $tool = Tool::query()->where('code', $definition['legacy_tool'])->where('is_active', true)->firstOrFail();
        abort_unless($customer->isAllowed($action->full_code, $context->channel()), 403);
        $project = app(MultiSpeakerInput::class)->prepare($customer, $segments, $clone);
        $hash = hash('sha256', json_encode([$project, $context->channel(), $context->apiJob?->id], JSON_THROW_ON_ERROR));
        $cost = $this->quote($customer, $action->full_code, $project, $context->channel());
        if ($cost <= 0) {
            throw new \RuntimeException('Pricing is not configured for this service.');
        }
        $durable = app(DurableUploadSubmission::class);
        [$job, $created] = $durable->begin((int) $customer->id, $key, $action->full_code, $cost, 'omni_batch_charge',
            ['chars' => $project['total_chars'], 'segment_count' => $project['segment_count']],
            function () use ($customer, $tool, $action, $definition, $cost, $project, $hash, $clone, $context) {
                if ($context->channel() === 'app' && MlJob::query()->where('customer_id', $customer->id)->whereNull('input->api_job_id')->active()->count()
                    >= app(\App\Services\Plans\PlanConcurrencyService::class)->allowedConcurrentJobsForCustomer($customer)) {
                    throw new \RuntimeException('Your concurrent job limit has been reached. Wait for the current project to finish.');
                }

                return MlJob::create([
                    'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $tool->id, 'tool_action_id' => $action->id,
                    'job_kind' => $definition['kind'], 'status' => 'queued', 'provider' => 'runpod',
                    'endpoint_key' => 'omni_v2', 'model_key' => 'model_2', 'credits_charged' => $cost,
                    'input' => $project + ['project_hash' => $hash, 'mode' => $clone ? 'audio_url_batch' : 'builtin_ref_batch', 'output_format' => 'wav', 'return_base64' => true],
                ]);
            }, $context);
        if (! hash_equals((string) data_get($job->input, 'project_hash'), $hash)) {
            throw new \RuntimeException('This submission identity already belongs to a different project.');
        }
        if (! $created) {
            return $job;
        }
        try {
            $payload = $project['segments'];
            $urls = $clone ? app(MultiSpeakerReferences::class)->urls($customer, array_column($payload, 'reference_id')) : [];
            foreach ($payload as &$segment) {
                if ($clone) {
                    $segment['audio_url'] = $urls[$segment['reference_id']];
                }
                unset($segment['reference_id'], $segment['voice']);
            }
            unset($segment);
            $response = app(RunPodV2Adapter::class)->omniBatch($service, $slug, (string) $job->id, $payload,
                fn () => $job->update(['submission_attempted_at' => now()]));
            $providerId = trim((string) ($response['id'] ?? ''));
            if ($providerId === '') {
                throw new \RuntimeException('Provider submission did not return an identity.');
            }
            $job->update(['provider_job_id' => $providerId, 'status' => 'running', 'started_at' => now(), 'failure_stage' => null]);
        } catch (\Throwable $exception) {
            $durable->failed($job, $exception);
        }
        app(CttsWorkspaceCache::class)->forgetRenders((int) $customer->id, $tool->code);

        return $job->fresh();
    }

    public function quote(Customer $customer, string $action, array $project, string $channel = 'app'): int
    {
        $languages = array_unique(array_column($project['segments'], 'language'));

        return (int) $customer->priceCreditsFor($action, [
            'channel' => $channel, 'metric_code' => 'character', 'chars' => $project['total_chars'],
            'language' => count($languages) === 1 ? reset($languages) : 'mixed',
        ]);
    }
}
