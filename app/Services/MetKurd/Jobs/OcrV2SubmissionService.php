<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Creates V2 OCR jobs while retaining the established OCR storage and billing lifecycle. */
class OcrV2SubmissionService
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly CustomerOutputStorage $storage,
        private readonly JobExecutionLockService $locks,
        private readonly RunPodV2Adapter $runpod,
    ) {}

    /** @param array{pages:string,estimated_pages:int,run_llm_corrector:bool,export_docx:bool,export_txt:bool,export_markdown:bool,export_html:bool,export_zip:bool,input_hash:string,file_name:string,file_mime:string,file_ext:string,file_bytes:int} $options */
    public function submit(object $customer, UploadedFile $file, array $options): MlJob
    {
        $action = 'ocr.standard';
        $credits = max(0, (int) $customer->priceCreditsFor($action, [
            'metric_code' => 'page', 'pages' => max(1, (int) $options['estimated_pages']),
            'page_count' => max(1, (int) $options['estimated_pages']), 'files' => 1, 'file_count' => 1,
        ]));
        if ($credits <= 0) throw new \RuntimeException(__('Pricing is not configured for this service. Please contact support.'));

        $jobId = (string) Str::uuid(); $stored = null; $charged = false;
        try {
            $this->credits->charge((int) $customer->id, $credits, 'ocr_charge', [
                'related_type' => 'ml_job', 'related_id' => $jobId, 'tool_action' => $action,
                'pages' => max(1, (int) $options['estimated_pages']),
            ]);
            $charged = true;
            $toolId = Tool::query()->where('code', 'ocr')->value('id');
            $actionId = ToolAction::query()->where('tool_code', 'ocr')->where('action_code', 'standard')->value('id');
            DB::transaction(function () use ($jobId, $customer, $credits, $toolId, $actionId, $options): void {
                MlJob::create([
                    'id' => $jobId, 'customer_id' => (int) $customer->id, 'tool_id' => $toolId, 'tool_action_id' => $actionId,
                    'job_kind' => 'ocr', 'status' => 'queued', 'provider' => 'runpod', 'endpoint_key' => 'kocr_v2',
                    'input_hash' => (string) $options['input_hash'], 'credits_charged' => $credits, 'started_at' => now(),
                    'input' => [
                        'v2' => true, 'file_name' => (string) $options['file_name'], 'file_mime' => (string) $options['file_mime'],
                        'file_ext' => (string) $options['file_ext'], 'file_bytes' => (int) $options['file_bytes'],
                        'page_range' => (string) $options['pages'], 'pages_estimated' => (int) $options['estimated_pages'],
                        'run_llm_corrector' => (bool) $options['run_llm_corrector'],
                        'exports' => array_intersect_key($options, array_flip(['export_docx','export_txt','export_markdown','export_html','export_zip'])),
                    ],
                ]);
            }, 3);
            $job = MlJob::query()->findOrFail($jobId);
            $stored = $this->storage->storeJobInputFile($customer, $file, 'ocr', $jobId, [
                'job_id' => $jobId, 'tool' => 'ocr', 'purpose' => 'input_document', 'role' => 'source_document',
                'checksum' => (string) $options['input_hash'], 'original_name' => (string) $options['file_name'],
            ], (string) $options['file_ext'], 'input');
            $fileUrl = $this->storage->temporaryUrl((string) $stored['path'], 120, ['ResponseContentType' => (string) $stored['mime']]);
            $job->update(['input' => array_merge((array) $job->input, [
                'file_disk' => (string) $stored['disk'], 'file_path' => (string) $stored['path'], 'file_url' => $fileUrl,
            ]), 'storage_in_bytes' => (int) $stored['bytes']]);
            $lock = $this->locks->acquireOcrLock((int) $customer->id, $jobId, (string) $options['input_hash'], request()->session(), request()->userAgent(), request()->ip());
            if (! ($lock['ok'] ?? false)) throw new \RuntimeException((string) ($lock['message'] ?? __('Could not lock the OCR job.')));
            $response = $this->runpod->kocr('ocr', 'scanner', array_merge($options, [
                'job_id' => $jobId, 'file_url' => $fileUrl, 'file_name' => (string) $options['file_name'],
            ]));
            $providerId = trim((string) data_get($response, 'id'));
            if ($providerId === '') throw new \RuntimeException(__('RunPod did not return a provider job ID.'));
            $job->update(['provider_job_id' => $providerId, 'status' => 'running', 'submission_attempted_at' => now()]);
            return $job->fresh();
        } catch (\Throwable $e) {
            Log::error('OCR_V2_SUBMIT_FAIL', ['job_id' => $jobId, 'message' => $e->getMessage()]);
            if ($stored && ! empty($stored['path'])) {
                try { $this->storage->deleteFromDiskAndUncount((int) $customer->id, (string) $stored['disk'], (string) $stored['path'], (int) $stored['bytes']); } catch (\Throwable) {}
            }
            $this->locks->releaseLock($jobId);
            if ($charged) {
                try { $this->credits->refund((int) $customer->id, $credits, 'ocr_refund', ['related_type' => 'ml_job', 'related_id' => $jobId, 'tool_action' => $action, 'reason' => 'provider_start_failed']); } catch (\Throwable) {}
            }
            MlJob::query()->whereKey($jobId)->update(['status' => 'failed', 'error' => ['message' => __('OCR could not be started.')], 'finished_at' => now()]);
            throw $e;
        }
    }
}
