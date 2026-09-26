<?php

namespace App\Services\CustomerApi\V2;

use App\Models\ApiJob;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\MetKurd\Jobs\CaptionSubmissionService;
use App\Services\MetKurd\Jobs\CloneOmniSubmissionService;
use App\Services\MetKurd\Jobs\HarakatSubmissionService;
use App\Services\MetKurd\Jobs\LeoSubmissionService;
use App\Services\MetKurd\Jobs\MultiSpeakerSubmissionService;
use App\Services\MetKurd\Jobs\OcrV2SubmissionService;
use App\Services\MetKurd\Jobs\OmniSubmissionService;
use App\Services\MetKurd\Jobs\StemV2SubmissionService;
use App\Services\MetKurd\Jobs\SubmissionContext;
use App\Services\MetKurd\V2\HarakatInput;
use App\Services\MetKurd\V2\InputBoundary;
use App\Services\MetKurd\V2\MultiSpeakerInput;
use App\Services\MetKurd\V2\MultiSpeakerReferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ApiSubmission
{
    public const OCR_EXPORTS = ['txt', 'docx', 'markdown', 'html', 'zip'];

    public const OCR_DEFAULT_EXPORTS = ['txt', 'docx'];

    public function submit(Request $request, string $service): array
    {
        /** @var Customer $customer */
        $customer = $request->user();
        /** @var CustomerApiKey $key */
        $key = $request->attributes->get('customerApiKey');
        $input = array_merge(['language' => 'ckb', 'intelligent' => false, 'storage_mode' => 'temporary'], $request->all());
        [$group, $tool, $action] = app(ApiCatalog::class)->definition($service, $input);
        app(ApiCatalog::class)->authorize($customer, $key, app(ApiCatalog::class)->scopeForService($service), $action);
        $identity = trim((string) $request->header('Idempotency-Key'));
        if ($identity === '' || strlen($identity) > 128) {
            throw new ApiProblem('invalid_request');
        }
        $rules = ['language' => 'required|in:ckb,ar,en', 'intelligent' => 'boolean', 'storage_mode' => 'required|in:temporary,permanent'];
        if (in_array($service, ['speech', 'voice-clone'], true)) {
            $rules += ['model' => 'sometimes|in:1.5,2.0', 'text' => 'required|string', 'voice' => 'nullable|string', 'reference_id' => 'nullable|integer|min:1', 'reference_text' => 'nullable|string|max:4000'];
        }
        if ($service === 'stem') {
            $rules['mode'] = 'required|in:2,4';
        }
        if ($service === 'ocr') {
            $rules += ['pages' => 'sometimes|string|max:255', 'exports' => 'sometimes|array', 'exports.*' => 'in:'.implode(',', self::OCR_EXPORTS)];
        }
        $newTool = in_array($service, ['zeta', 'theta', 'harakat'], true);
        $data = $newTool ? app(ToolInput::class)->validate($service, $request->all()) : Validator::make($input, $rules)->validate();
        $file = $request->file('file');
        if ($file !== null && ! $file instanceof \Illuminate\Http\UploadedFile) {
            throw new ApiProblem('invalid_file');
        }
        if ($file !== null) {
            if ($service === 'speech' || $newTool) {
                throw new ApiProblem('invalid_request');
            }
            // Bound hashing cost before reading the file for its retry fingerprint.
            Validator::make(['file' => $file], ['file' => ['file', 'max:'.($service === 'voice-clone' ? 20480 : 102400)]])->validate();
        }
        $boundary = app(InputBoundary::class);
        // Fingerprint before probing/uploading. Repeated accepted requests return owned local state.
        $fingerprintData = $data;
        ksort($fingerprintData);
        $fingerprint = hash('sha256', json_encode([$service, $fingerprintData, $file ? $boundary->hash($file) : null], JSON_THROW_ON_ERROR));
        $identityHash = hash('sha256', $identity);
        $existing = ApiJob::query()->where('customer_id', $customer->id)->where('idempotency_hash', $identityHash)->first();
        if ($existing) {
            return [$this->repeated($existing, $fingerprint), false];
        }

        $options = [];
        if (in_array($service, ['zeta', 'theta'], true)) {
            app(MultiSpeakerInput::class)->prepare($customer, $data['segments'], $service === 'theta');
            if ($service === 'theta') {
                app(MultiSpeakerReferences::class)->validate($customer, array_column($data['segments'], 'reference_id'));
            }
        } elseif ($service === 'harakat') {
            $data['text'] = app(HarakatInput::class)->prepare($data['text'])['text'];
        }
        if (in_array($service, ['speech', 'voice-clone'], true)) {
            $data = array_merge($data, $boundary->text($customer, $action, $data, $service === 'speech'));
        }
        if ($service === 'voice-clone') {
            if ((bool) $file === ! empty($data['reference_id'])) {
                throw new ApiProblem('invalid_file');
            }
            if ($file) {
                $boundary->audio($file, true);
            } else {
                $boundary->reference($customer, (int) $data['reference_id']);
            }
        } elseif (in_array($service, ['transcriptions', 'captions', 'stem'], true)) {
            if (! $file) {
                throw new ApiProblem('invalid_file');
            }
            $options = $boundary->audio($file);
        } elseif ($service === 'ocr') {
            if (! $file) {
                throw new ApiProblem('invalid_file');
            }
            $options = $boundary->document($file, ['pages' => $data['pages'] ?? 'all']);
            foreach (self::OCR_EXPORTS as $export) {
                $options['export_'.$export] = in_array($export, $data['exports'] ?? self::OCR_DEFAULT_EXPORTS, true);
            }
            $options['run_llm_corrector'] = (bool) $data['intelligent'];
        }
        [$api, $created] = DB::transaction(function () use ($customer, $key, $identityHash, $fingerprint, $service, $action, $data) {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $existing = ApiJob::query()->where('customer_id', $customer->id)->where('idempotency_hash', $identityHash)->first();
            if ($existing) {
                return [$this->repeated($existing, $fingerprint), false];
            }
            $limit = app(CustomerApiAccessService::class)->allowedConcurrentJobs($customer);
            if ($limit < 1 || ApiJob::query()->where('customer_id', $customer->id)->whereIn('status', ['queued', 'processing', 'accepted'])->count() >= $limit) {
                throw new ApiProblem('concurrency_limit_exceeded', 429);
            }

            return [ApiJob::create([
                'id' => 'job_'.Str::lower((string) Str::ulid()), 'customer_id' => $customer->id, 'api_key_id' => $key->id,
                'tool_code' => explode('.', $action)[0], 'tool_action' => $action, 'status' => 'accepted',
                'input_hash' => $fingerprint, 'idempotency_hash' => $identityHash, 'storage_mode' => $data['storage_mode'],
                'meta' => ['api_version' => 2, 'service' => $service, 'preparation' => 'started',
                    'expires_at' => $data['storage_mode'] === 'temporary' ? now()->addDays((int) config('customer_api.temporary_file_ttl_days', 7))->toIso8601String() : null],
            ]), true];
        }, 3);
        if (! $created) {
            return [$api, false];
        }
        $context = new SubmissionContext($api);
        $submissionKey = hash('sha256', 'api-v2:'.$api->id);
        $options = array_merge($options, ['submission_key' => $submissionKey, 'language' => $data['language'] ?? 'ckb', 'intelligent' => (bool) ($data['intelligent'] ?? false), 'model_variant' => 'fine_tuned']);
        try {
            $job = match ($service) {
                'zeta', 'theta' => app(MultiSpeakerSubmissionService::class)->submit($customer, $group, $tool, $submissionKey, $data['segments'], $context),
                'harakat' => app(HarakatSubmissionService::class)->submit($customer, $submissionKey, $data['text'], $context),
                'speech' => app(OmniSubmissionService::class)->submit($customer, $group, $tool, $submissionKey, $data, $context),
                'voice-clone' => app(CloneOmniSubmissionService::class)->submit($customer, $group, $tool, $submissionKey, $data, $file, $data['reference_id'] ?? null, $context),
                'transcriptions' => app(LeoSubmissionService::class)->submit($customer, $file, $options, $context),
                'captions' => app(CaptionSubmissionService::class)->submit($customer, $file, $options, $context),
                'ocr' => app(OcrV2SubmissionService::class)->submit($customer, $file, $options, $context),
                'stem' => app(StemV2SubmissionService::class)->submit($customer, $file, array_merge($options, ['stems' => (int) $data['mode']]), $context),
            };
            $api->refresh()->update(['meta' => array_merge($api->meta, ['preparation' => 'finished'])]);

            return [$api->fresh(), true];
        } catch (\Throwable $e) {
            $api->refresh();
            // An established MlJob owns recovery; never undo an uncertain dispatch here.
            if ($api->ml_job_id) {
                return [$api, true];
            }
            $code = $e->getMessage() === 'Not enough credits.' ? 'insufficient_credits' : 'server_error';
            $api->update(['status' => 'failed', 'error_code' => $code, 'completed_at' => now()]);
            if ($code === 'server_error') {
                report($e);
            }
            throw new ApiProblem($code, $code === 'insufficient_credits' ? 422 : 500);
        }
    }

    private function repeated(ApiJob $job, string $fingerprint): ApiJob
    {
        if (! hash_equals((string) $job->input_hash, $fingerprint)) {
            throw new ApiProblem('idempotency_conflict', 409);
        }

        return $job;
    }
}
