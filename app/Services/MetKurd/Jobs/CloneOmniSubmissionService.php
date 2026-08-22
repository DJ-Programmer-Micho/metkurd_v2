<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\MetKurd\V2\CttsWorkspaceCache;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Coordinates the V2 CTTS flow while retaining ownership of reusable references. */
class CloneOmniSubmissionService
{
    private const REFERENCE_MIMES = [
        'audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/mp3', 'audio/mp4',
        'audio/x-m4a', 'audio/aac', 'audio/ogg', 'audio/webm',
    ];

    public function __construct(
        private readonly MetKurdV2ToolCatalog $catalog,
        private readonly CreditService $credits,
        private readonly CustomerOutputStorage $storage,
        private readonly RunPodV2Adapter $provider,
        private readonly MlJobRefundService $refunds,
        private readonly JobExecutionLockService $locks,
        private readonly CttsWorkspaceCache $workspaceCache,
    ) {}

    /** @param array{text:string,language?:string} $input */
    public function submit(
        Customer $customer,
        string $service,
        string $toolSlug,
        string $submissionKey,
        array $input,
        ?UploadedFile $uploadedReference = null,
        ?int $referenceFileId = null,
    ): MlJob {
        $definition = $this->catalog->tool($service, $toolSlug);
        if (! is_array($definition) || ($definition['kind'] ?? null) !== 'omni_clone') {
            throw new \InvalidArgumentException('This is not a native CTTS tool.');
        }

        $text = trim((string) ($input['text'] ?? ''));
        $language = strtolower(trim((string) ($input['language'] ?? 'ckb')));
        $submissionKey = trim($submissionKey);
        if ($text === '' || ! in_array($language, ['ckb', 'en', 'ar'], true) || $submissionKey === '' || strlen($submissionKey) > 64) {
            throw new \InvalidArgumentException('A valid text, language, and submission identity are required.');
        }

        if (! $uploadedReference && ! $referenceFileId) {
            throw new \InvalidArgumentException('Choose a saved reference voice or upload a new one.');
        }
        if ($uploadedReference) {
            $this->assertValidUpload($uploadedReference);
        }

        [$tool, $action] = $this->resolveToolAndAction($definition);
        if (method_exists($customer, 'isAllowed') && ! $customer->isAllowed((string) $action->full_code)) {
            throw new \RuntimeException('Your plan does not allow this tool.');
        }

        $cost = max(0, (int) $customer->priceCreditsFor((string) $action->full_code, [
            'chars' => mb_strlen($text), 'metric_code' => 'character', 'language' => $language,
        ]));
        if ($cost <= 0) {
            throw new \RuntimeException('Pricing is not configured for this service.');
        }

        [$job, $shouldSubmit] = DB::transaction(function () use ($customer, $submissionKey, $definition, $tool, $action, $cost, $text, $language): array {
            $job = MlJob::query()->where('customer_id', $customer->id)->where('submission_key', $submissionKey)->lockForUpdate()->first();
            if (! $job) {
                $jobId = (string) Str::uuid();
                $job = MlJob::create([
                    'id' => $jobId, 'customer_id' => (int) $customer->id, 'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id, 'job_kind' => (string) $tool->code, 'status' => 'queued',
                    'provider' => 'runpod', 'submission_key' => $submissionKey,
                    'endpoint_key' => (string) $definition['endpoint'], 'model_key' => (string) $definition['provider_model'],
                    'charge_reference' => "ml-job:{$jobId}:charge", 'credits_charged' => $cost,
                    'input' => ['mode' => 'audio_url', 'text' => $text, 'language' => $language, 'text_language' => $language, 'ref_text' => '', 'output_format' => 'wav', 'return_base64' => true, 'ref_max_sec' => 20],
                ]);
                $this->credits->charge((int) $customer->id, $cost, (string) $tool->code.'_charge', [
                    'reference_code' => (string) $job->charge_reference, 'related_type' => 'ml_job', 'related_id' => (string) $job->id,
                    'ml_job_id' => (string) $job->id, 'tool_action' => (string) $action->full_code, 'chars' => mb_strlen($text),
                ]);
            }
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

        $this->workspaceCache->forgetRenders((int) $customer->id, (string) $tool->code);

        $request = request();
        $lock = $this->locks->acquireCloneLock(
            (int) $customer->id,
            (string) $job->id,
            $request->hasSession() ? $request->session() : null,
            $request->userAgent(),
            $request->ip(),
            (string) $tool->code,
        );
        if (! ($lock['ok'] ?? false)) {
            return $this->failAndRefund($job, 'execution_lock', (string) ($lock['message'] ?? 'Could not acquire the CTTS lock.'));
        }

        try {
            $reference = $uploadedReference
                ? $this->storeUploadedReference($customer, $job, $uploadedReference, (string) $tool->code)
                : $this->ownedReference($customer, (int) $referenceFileId);

            $reference = $this->ensurePermanentS3Reference($customer, $job, (string) $tool->code, $reference);
            $audioUrl = $this->storage->temporaryUrl((string) $reference->path, 120, [
                'ResponseContentType' => (string) $reference->mime,
            ]);
            $job->input = array_merge((array) $job->input, [
                'reference_file_id' => (int) $reference->id, 'reference_audio_name' => $this->referenceName($reference),
                'reference_audio_mime' => (string) $reference->mime, 'reference_audio_bytes' => (int) $reference->size_bytes,
                'reference_audio_disk' => (string) $reference->disk, 'reference_audio_path' => (string) $reference->path,
                'audio_url' => $audioUrl, 'reference_is_reusable' => true,
            ]);
            $job->storage_in_bytes = (int) $reference->size_bytes;
            $job->save();

            $response = $this->provider->omni($service, $toolSlug, array_merge((array) $job->input, ['audio_url' => $audioUrl]));
            $providerJobId = trim((string) data_get($response, 'id'));
            if ($providerJobId === '') {
                throw new \RuntimeException('RunPod did not return a job ID.');
            }
            MlJob::query()->whereKey($job->id)->update(['status' => 'running', 'provider_job_id' => $providerJobId, 'started_at' => now(), 'failure_stage' => null]);
        } catch (\Throwable $exception) {
            return $this->failAndRefund($job, 'provider_submission', $exception->getMessage());
        }

        return $job->fresh();
    }

    private function ownedReference(Customer $customer, int $referenceFileId): CustomerFile
    {
        $reference = CustomerFile::query()->whereKey($referenceFileId)->where('customer_id', $customer->id)->where('status', 'active')
            ->where('purpose', 'reference')->whereIn('tool_code', ['clone_tts', 'clone_xomni', 'vector-v2'])->first();
        if (! $reference || ! $this->isReferenceFile($reference) || ! Storage::disk((string) $reference->disk)->exists((string) $reference->path)) {
            throw new \RuntimeException('That saved reference voice is no longer available.');
        }
        return $reference;
    }

    private function storeUploadedReference(Customer $customer, MlJob $job, UploadedFile $file, string $toolCode): CustomerFile
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: 'wav'));
        $saved = $this->storage->saveUploadedFileToS3((int) $customer->id, $file, $this->storage->inputPath($customer, $toolCode, (string) $job->id, $extension, 'reference'), [
            'job_id' => (string) $job->id, 'tool' => $toolCode, 'purpose' => 'reference', 'role' => 'speaker_reference',
            'source_type' => 'ml_job', 'source_id' => (string) $job->id, 'original_name' => $file->getClientOriginalName(),
        ]);
        $reference = CustomerFile::query()->where('customer_id', $customer->id)->where('disk', $saved['disk'])->where('path', $saved['path'])->latest('id')->firstOrFail();
        $this->workspaceCache->forgetReferences((int) $customer->id);

        return $reference;
    }

    /**
     * References may predate the S3-only CTTS flow. Materialize those owned,
     * validated legacy objects onto the private S3 disk before creating the
     * worker URL; browser values and arbitrary keys never take part here.
     */
    private function ensurePermanentS3Reference(Customer $customer, MlJob $job, string $toolCode, CustomerFile $reference): CustomerFile
    {
        if ((string) $reference->disk === 's3') {
            return $reference;
        }

        $sourceDisk = (string) $reference->disk;
        $sourcePath = (string) $reference->path;
        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'wav';
        $targetPath = $this->storage->inputPath($customer, $toolCode, (string) $job->id, $extension, 'reference');
        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);

        if (! is_resource($stream)) {
            throw new \RuntimeException('That saved reference voice could not be prepared for secure processing.');
        }

        try {
            $written = Storage::disk('s3')->put($targetPath, $stream, [
                'visibility' => 'private',
                'ContentType' => (string) $reference->mime,
            ]);
        } finally {
            fclose($stream);
        }

        if (! $written || ! Storage::disk('s3')->exists($targetPath)) {
            throw new \RuntimeException('That saved reference voice could not be moved to secure storage.');
        }

        $reference->forceFill([
            'disk' => 's3',
            'path' => $targetPath,
            'meta' => array_merge((array) $reference->meta, [
                'secured_for_ctts_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return $reference->fresh();
    }

    private function assertValidUpload(UploadedFile $file): void
    {
        if (! $file->isValid() || (int) $file->getSize() > 20 * 1024 * 1024 || ! in_array((string) $file->getMimeType(), self::REFERENCE_MIMES, true)) {
            throw new \InvalidArgumentException('Reference audio must be a supported audio file no larger than 20 MB.');
        }
    }

    private function isReferenceFile(CustomerFile $file): bool
    {
        return str_starts_with(strtolower((string) $file->mime), 'audio/') && (string) data_get($file->meta, 'role', 'speaker_reference') === 'speaker_reference';
    }

    private function referenceName(CustomerFile $file): string
    {
        return (string) (data_get($file->meta, 'original_name') ?: basename((string) $file->path));
    }

    /** @param array<string,mixed> $definition @return array{Tool,ToolAction} */
    private function resolveToolAndAction(array $definition): array
    {
        $tool = Tool::query()->where('code', (string) $definition['legacy_tool'])->first();
        $action = ToolAction::query()->where('full_code', (string) $definition['legacy_action'])->first();
        if (! $tool || ! $action) throw new \RuntimeException('The configured CTTS tool or action is missing.');
        return [$tool, $action];
    }

    private function failAndRefund(MlJob $job, string $stage, string $message): MlJob
    {
        MlJob::query()->whereKey($job->id)->update(['status' => 'failed', 'failure_stage' => $stage, 'error' => ['message' => $message], 'finished_at' => now()]);
        $this->locks->releaseLock((string) $job->id);
        $this->refunds->refundFailedJob((string) $job->id, $stage);
        $this->workspaceCache->forgetRenders((int) $job->customer_id, (string) $job->tool?->code);
        return $job->fresh();
    }
}
