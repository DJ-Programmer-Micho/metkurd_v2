<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Creates V2 OCR jobs while retaining the established OCR storage and billing lifecycle. */
class OcrV2SubmissionService
{
    public function __construct(
        private readonly DurableUploadSubmission $durable,
        private readonly CustomerOutputStorage $storage,
        private readonly JobExecutionLockService $locks,
        private readonly RunPodV2Adapter $runpod,
    ) {}

    /** @param array{pages:string,estimated_pages:int,run_llm_corrector:bool,export_docx:bool,export_txt:bool,export_markdown:bool,export_html:bool,export_zip:bool,input_hash:string,file_name:string,file_mime:string,file_ext:string,file_bytes:int} $options */
    public function submit(object $customer, UploadedFile $file, array $options, ?SubmissionContext $context = null): MlJob
    {
        $context ??= new SubmissionContext;
        $probe = app(\App\Services\OCR\OcrDocumentProbe::class);
        $selected = $probe->selectedPages($probe->pageCount($file), (string) $options['pages']);
        $options['estimated_pages'] = count($selected);
        $options['pages'] = $options['pages'] === 'all' ? 'all' : implode(',', $selected);
        $action = 'ocr.standard';
        $credits = max(0, (int) $customer->priceCreditsFor($action, [
            'channel' => $context->channel(),
            'metric_code' => 'page', 'pages' => max(1, (int) $options['estimated_pages']),
            'page_count' => max(1, (int) $options['estimated_pages']), 'files' => 1, 'file_count' => 1,
        ]));
        if ($credits <= 0) {
            throw new \RuntimeException(__('Pricing is not configured for this service. Please contact support.'));
        }

        $jobId = (string) Str::uuid();
        $key = (string) ($options['submission_key'] ?? Str::uuid());
        $toolId = Tool::query()->where('code', 'ocr')->value('id');
        $actionId = ToolAction::query()->where('tool_code', 'ocr')->where('action_code', 'standard')->value('id');
        [$job, $created] = $this->durable->begin((int) $customer->id, $key, $action, $credits, 'ocr_charge', ['pages' => max(1, (int) $options['estimated_pages'])], function () use ($jobId, $customer, $credits, $toolId, $actionId, $options) {
            return MlJob::create([
                'id' => $jobId, 'customer_id' => (int) $customer->id, 'tool_id' => $toolId, 'tool_action_id' => $actionId,
                'job_kind' => 'ocr', 'status' => 'queued', 'provider' => 'runpod', 'endpoint_key' => 'kocr_v2',
                'input_hash' => (string) $options['input_hash'], 'credits_charged' => $credits, 'started_at' => now(),
                'input' => [
                    'v2' => true, 'file_name' => (string) $options['file_name'], 'file_mime' => (string) $options['file_mime'],
                    'file_ext' => (string) $options['file_ext'], 'file_bytes' => (int) $options['file_bytes'],
                    'page_range' => (string) $options['pages'], 'pages_estimated' => (int) $options['estimated_pages'],
                    'run_llm_corrector' => (bool) $options['run_llm_corrector'],
                    'exports' => array_intersect_key($options, array_flip(['export_docx', 'export_txt', 'export_markdown', 'export_html', 'export_zip'])),
                ],
            ]);
        }, $context);
        if (! $created) {
            return $job;
        }
        try {
            $stored = $this->storage->storeJobInputFile($customer, $file, 'ocr', $jobId, [
                'job_id' => $jobId, 'tool' => 'ocr', 'purpose' => 'input_document', 'role' => 'source_document',
                'checksum' => (string) $options['input_hash'], 'original_name' => (string) $options['file_name'],
            ], (string) $options['file_ext'], 'input');
            $fileUrl = $this->storage->temporaryUrl((string) $stored['path'], 120, ['ResponseContentType' => (string) $stored['mime']]);
            $job->update(['input' => array_merge((array) $job->input, [
                'file_disk' => (string) $stored['disk'], 'file_path' => (string) $stored['path'], 'file_url' => $fileUrl,
            ]), 'storage_in_bytes' => (int) $stored['bytes']]);
            $lock = $this->locks->acquireOcrLock((int) $customer->id, $jobId, (string) $options['input_hash'], (request()->hasSession() ? request()->session() : null), request()->userAgent(), request()->ip());
            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? __('Could not lock the OCR job.')));
            }
            $job->update(['submission_attempted_at' => now(), 'failure_stage' => null]);
            $response = $this->runpod->kocr('ocr', 'scanner', array_merge($options, [
                'job_id' => $jobId, 'file_url' => $fileUrl, 'file_name' => (string) $options['file_name'],
            ]));
            $providerId = trim((string) data_get($response, 'id'));
            if ($providerId === '') {
                throw new \RuntimeException(__('RunPod did not return a provider job ID.'));
            }
            $job->update(['provider_job_id' => $providerId, 'status' => 'running', 'failure_stage' => null]);

            return $job->fresh();
        } catch (\Throwable $exception) {
            $result = $this->durable->failed($job, $exception);
            if ($result->status === 'failed') {
                $this->locks->releaseLock($jobId);
            }

            return $result;
        }
    }
}
