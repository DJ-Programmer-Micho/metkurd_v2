<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates OMNI's local lifecycle. It deliberately commits the local job
 * and financial intent before making the independent RunPod HTTP request.
 */
class OmniSubmissionService
{
    public function __construct(
        private readonly MetKurdV2ToolCatalog $catalog,
        private readonly CreditService $credits,
        private readonly RunPodV2Adapter $provider,
        private readonly MlJobRefundService $refunds,
    ) {}

    /** @param array{text:string,ref_audio:string,language?:string,ref_text?:string} $input */
    public function submit(Customer $customer, string $service, string $toolSlug, string $submissionKey, array $input, ?SubmissionContext $context = null): MlJob
    {
        $context ??= new SubmissionContext;
        $definition = $this->catalog->tool($service, $toolSlug);
        if (! is_array($definition) || ! in_array($definition['kind'] ?? null, ['omni_tts'], true)) {
            throw new \InvalidArgumentException('This is not a native OMNI text-to-speech tool.');
        }

        $submissionKey = trim($submissionKey);
        if ($submissionKey === '' || strlen($submissionKey) > 64) {
            throw new \InvalidArgumentException('A valid submission identity is required.');
        }

        $text = trim((string) ($input['text'] ?? ''));
        $refAudio = $this->safeReferencePath((string) ($input['ref_audio'] ?? ''));
        $language = strtolower(trim((string) ($input['language'] ?? 'ckb')));
        if ($text === '' || $refAudio === '') {
            throw new \InvalidArgumentException('Text and a built-in reference voice are required.');
        }

        [$tool, $action] = $this->resolveToolAndAction($definition);
        if (method_exists($customer, 'isAllowed') && ! $customer->isAllowed((string) $action->full_code, $context->channel())) {
            throw new \RuntimeException('Your plan does not allow this tool.');
        }

        $cost = max(0, (int) $customer->priceCreditsFor((string) $action->full_code, [
            'channel' => $context->channel(),
            'chars' => mb_strlen($text),
            'metric_code' => 'character',
            'language' => $language,
        ]));
        if ($cost <= 0) {
            throw new \RuntimeException('Pricing is not configured for this service.');
        }

        [$job, $shouldSubmit] = DB::transaction(function () use ($context, $customer, $submissionKey, $definition, $tool, $action, $cost, $text, $refAudio, $language): array {
            $job = MlJob::query()
                ->where('customer_id', $customer->id)
                ->where('submission_key', $submissionKey)
                ->lockForUpdate()
                ->first();

            if (! $job) {
                $jobId = (string) \Illuminate\Support\Str::uuid();
                $job = MlJob::create([
                    'id' => $jobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => (string) $definition['kind'],
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'submission_key' => $submissionKey,
                    'endpoint_key' => (string) $definition['endpoint'],
                    'model_key' => (string) $definition['provider_model'],
                    'charge_reference' => "ml-job:{$jobId}:charge",
                    'credits_charged' => $cost,
                    'input' => [
                        'mode' => 'builtin_ref',
                        'text' => $text,
                        'ref_audio' => $refAudio,
                        'ref_text' => '',
                        'language' => $language,
                        'text_language' => $language,
                        'output_format' => 'wav',
                        'return_base64' => true,
                    ],
                ]);

                $context->charge(
                    customerId: (int) $customer->id,
                    credits: $cost,
                    type: 'omni_charge',
                    meta: [
                        'reference_code' => (string) $job->charge_reference,
                        'related_type' => 'ml_job',
                        'related_id' => (string) $job->id,
                        'ml_job_id' => (string) $job->id,
                        'tool_action' => (string) $action->full_code,
                        'chars' => mb_strlen($text),
                    ],
                );
            }

            $job->refresh();
            if ($job->provider_job_id || $job->submission_attempted_at || in_array((string) $job->status, ['done', 'failed'], true)) {
                return [$job, false];
            }

            $job->submission_attempted_at = now();
            $job->save();

            return [$job, true];
        }, 3);

        if (! $shouldSubmit) {
            return $job->fresh();
        }

        try {
            $response = $this->provider->omni($service, $toolSlug, (array) $job->input);
            $providerJobId = trim((string) data_get($response, 'id'));
            if ($providerJobId === '') {
                throw new \RuntimeException('RunPod did not return a job ID.');
            }

            MlJob::query()->whereKey($job->id)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'started_at' => now(),
                'failure_stage' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            app(DurableUploadSubmission::class)->failed($job, $exception);
        }

        return $job->fresh();
    }

    /** @param array<string,mixed> $definition @return array{Tool,ToolAction} */
    private function resolveToolAndAction(array $definition): array
    {
        $tool = Tool::query()->where('code', (string) $definition['legacy_tool'])->first();
        $action = ToolAction::query()->where('full_code', (string) $definition['legacy_action'])->first();
        if (! $tool || ! $action) {
            throw new \RuntimeException('The configured OMNI tool or action is missing.');
        }

        return [$tool, $action];
    }

    private function safeReferencePath(string $value): string
    {
        $path = trim(str_replace('\\', '/', $value));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('The selected reference voice is invalid.');
        }

        return $path;
    }
}
