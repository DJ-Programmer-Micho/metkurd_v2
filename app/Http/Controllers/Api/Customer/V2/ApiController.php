<?php

namespace App\Http\Controllers\Api\Customer\V2;

use App\Http\Controllers\Controller;
use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\CustomerApi\V2\ApiProblem;
use App\Services\CustomerApi\V2\ApiSubmission;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        return response()->json(['services' => ApiCatalog::SERVICES, 'speech_models' => ['1.5', '2.0'], 'stem_modes' => [2, 4],
            'service_details' => app(ApiCatalog::class)->additionalServices()]);
    }

    public function uploadReference(Request $request)
    {
        app(ApiCatalog::class)->authorize($request->user(), $request->attributes->get('customerApiKey'), 'v2:voice-clone', 'theta.generate');
        \Illuminate\Support\Facades\Validator::make(['input' => $request->all()], ['input' => 'required|array:file'])->validate();
        $file = $request->file('file');
        if (! $file instanceof \Illuminate\Http\UploadedFile) {
            throw new ApiProblem('invalid_file');
        }
        $reference = app(\App\Services\MetKurd\V2\MultiSpeakerReferences::class)->upload($request->user(), $file, 'api');

        return response()->json(['reference_id' => $reference->id, 'mime_type' => $reference->mime,
            'size_bytes' => $reference->size_bytes, 'expires_at' => $reference->expires_at?->toIso8601String()], 201);
    }

    public function download(Request $request, string $id)
    {
        $this->authorizeRequest($request, 'v2:files:download');
        $result = ApiResultFile::query()->where('customer_id', $request->user()->id)->whereNull('deleted_at')
            ->whereHas('apiJob', fn ($q) => $q->where('customer_id', $request->user()->id)->where('meta->api_version', 2))->find($id);
        $file = $result?->storageFile;
        if (! $file || (int) $file->customer_id !== (int) $request->user()->id || $file->status !== 'active' || $file->deleted_at !== null
            || ($file->expires_at && $file->expires_at->lessThanOrEqualTo(now())) || ! Storage::disk($file->disk)->exists($file->path)) {
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
        return app(\App\Services\CustomerApi\V2\ApiJobResult::class)->payload($job);
    }
}
