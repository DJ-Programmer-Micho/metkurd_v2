<?php

namespace App\Http\Controllers\Api\Customer\V2;

use App\Http\Controllers\Controller;
use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\CustomerFile;
use App\Services\CustomerApi\CustomerApiJobSyncService;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\CustomerApi\V2\ApiProblem;
use App\Services\CustomerApi\V2\ApiSubmission;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    public function submit(Request $request, ApiSubmission $submissions)
    {
        [$job, $created] = $submissions->submit($request, (string) $request->route('service'));

        return response()->json($this->payload($job), $created ? 202 : 200);
    }

    public function show(Request $request, string $id)
    {
        $this->authorizeRequest($request, 'v2:jobs:read');
        $job = ApiJob::query()->where('customer_id', $request->user()->id)->where('meta->api_version', 2)->find($id);
        if (! $job) {
            throw new ApiProblem('job_not_found', 404);
        }

        return response()->json($this->payload($job));
    }

    public function voices(Request $request)
    {
        $this->authorizeRequest($request, 'v2:speech');
        $voices = collect(app(OmniSpeakerCatalog::class)->forCustomer($request->user(), 'en'))->pluck('speakers')->flatten(1)
            ->map(fn ($voice) => ['id' => $voice['code'], 'name' => $voice['name']])->values();

        return response()->json(['voices' => $voices]);
    }

    public function services(Request $request)
    {
        $this->authorizeRequest($request, 'v2:jobs:read');

        return response()->json(['services' => ApiCatalog::SERVICES, 'speech_models' => ['1.5', '2.0'], 'stem_modes' => [2, 4]]);
    }

    public function download(Request $request, string $id)
    {
        $this->authorizeRequest($request, 'v2:files:download');
        $result = ApiResultFile::query()->where('customer_id', $request->user()->id)->whereNull('deleted_at')
            ->whereHas('apiJob', fn ($q) => $q->where('customer_id', $request->user()->id)->where('meta->api_version', 2))->find($id);
        $file = $result?->storageFile;
        if (! $file || (int) $file->customer_id !== (int) $request->user()->id || $file->status !== 'active'
            || ($file->expires_at && $file->expires_at->isPast()) || ! Storage::disk($file->disk)->exists($file->path)) {
            throw new ApiProblem('file_not_found', 404);
        }

        return Storage::disk($file->disk)->download($file->path, 'result.'.pathinfo($file->path, PATHINFO_EXTENSION), ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store']);
    }

    private function authorizeRequest(Request $request, string $scope): void
    {
        app(ApiCatalog::class)->authorize($request->user(), $request->attributes->get('customerApiKey'), $scope);
    }

    private function payload(ApiJob $job): array
    {
        // Settlement reads application-owned state; status GET never asks the GPU.
        if ($job->ml_job_id) {
            $job = app(CustomerApiJobSyncService::class)->syncFromMlJob($job);
        }
        $job->loadMissing('mlJob');
        $status = $job->status === 'accepted' ? 'queued' : $job->status;
        $status = $job->mlJob?->failure_stage === 'provider_submission_unknown' ? 'processing' : $status;
        $payload = ['id' => $job->id, 'status' => $status, 'service' => data_get($job->meta, 'service'),
            'created_at' => $job->created_at->toIso8601String(), 'completed_at' => $job->completed_at?->toIso8601String(),
            'expires_at' => data_get($job->meta, 'expires_at'), 'result' => null];
        if ($status === 'failed') {
            $code = $job->error_code === 'insufficient_credits' ? 'insufficient_credits' : (data_get($job->mlJob?->error, 'code') === 'storage_limit_exceeded' ? 'storage_limit_exceeded' : 'processing_failed');
            $payload['error'] = ['code' => $code, 'message' => \App\Http\Middleware\ApiV2Boundary::MESSAGES[$code]];
        }
        if ($status !== 'completed') {
            return $payload;
        }
        if (data_get($job->meta, 'expires_at') && now()->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse(data_get($job->meta, 'expires_at')))) {
            $payload['result'] = ['expired' => true, 'files' => []];

            return $payload;
        }
        $files = CustomerFile::query()->where('customer_id', $job->customer_id)->where('status', 'active')
            ->where('meta->job_id', $job->ml_job_id)->whereIn('purpose', ['render', 'transcription', 'caption'])->get();
        $resultFiles = [];
        foreach ($files as $file) {
            if ($file->expires_at && $file->expires_at->isPast()) {
                continue;
            }
            $link = ApiResultFile::firstOrCreate(['api_job_id' => $job->id, 'storage_file_id' => $file->id, 'result_kind' => 'artifact'], ['id' => 'file_'.Str::lower((string) Str::ulid()), 'customer_id' => $job->customer_id]);
            $resultFiles[] = ['id' => $link->id, 'kind' => data_get($file->meta, 'role') ?: $file->purpose, 'mime_type' => $file->mime,
                'size_bytes' => $file->size_bytes, 'download_url' => route('api.customer.v2.files.download', ['id' => $link->id])];
        }
        $output = (array) $job->mlJob?->output;
        $service = data_get($job->meta, 'service');
        $result = ['files' => $resultFiles];
        if (in_array($service, ['transcriptions', 'captions'], true)) {
            $result['text'] = is_string($output['text'] ?? null) ? $output['text'] : '';
        }
        if ($service === 'captions') {
            $result['srt'] = is_string($output['srt'] ?? null) ? $output['srt'] : null;
            // Only public caption fields, never arbitrary worker metadata.
            $result['segments'] = collect($output['segments'] ?? [])->map(fn ($segment) => array_intersect_key((array) $segment, array_flip(['start', 'end', 'text'])))->all();
        }
        if ($service === 'ocr') {
            $result['text'] = (string) data_get($output, 'text.inline', '');
        }
        $payload['result'] = $result;

        return $payload;
    }
}
