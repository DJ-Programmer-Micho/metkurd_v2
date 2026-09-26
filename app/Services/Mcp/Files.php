<?php

namespace App\Services\Mcp;

use App\Models\ApiResultFile;
use App\Models\CustomerFile;
use App\Services\CustomerApi\V2\ApiProblem;
use App\Services\MetKurd\V2\InputBoundary;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Files
{
    public function authorizePurpose(McpConnectionPrincipal $principal, string $purpose): void
    {
        $customer = $principal->customer();
        $scope = match ($purpose) {
            'ocr' => 'v2:ocr', 'transcription' => 'v2:transcriptions', 'caption' => 'v2:captions',
            'stem' => 'v2:stem', 'voice_reference' => 'v2:voice-clone', default => throw new ApiProblem('invalid_file'),
        };
        $principal->authorize($customer, $scope);
    }

    public function createUpload(McpConnectionPrincipal $principal, array $args): array
    {
        $principal->authorize($principal->customer(), 'mcp:uploads');
        $this->authorizePurpose($principal, $args['purpose']);
        if (! Str::isUuid($args['request_id'])) {
            throw new ApiProblem('invalid_request');
        }
        $customer = $principal->customer();
        // A UUID argument is a durable operation identity; no file contents are retained here.
        $id = (string) \Symfony\Component\Uid\Uuid::v5(\Symfony\Component\Uid\Uuid::fromString($principal->connectionId), strtolower($args['request_id']));
        DB::table('mcp_upload_sessions')->insertOrIgnore(['id' => $id, 'customer_id' => $customer->id,
            'connection_id' => $principal->connectionId, 'purpose' => $args['purpose'], 'expires_at' => now()->addMinutes(config('mcp.upload_minutes')), 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('mcp_upload_sessions')->where('id', $id)->first();
        if ($row->purpose !== $args['purpose']) {
            throw new ApiProblem('idempotency_conflict', 409);
        }

        return ['upload_session_id' => $id, 'upload_url' => config('mcp.issuer').'/en/app-v2/mcp/uploads/'.$id,
            'expires_at' => $row->expires_at, 'file_id' => $row->customer_file_id];
    }

    public function upload(McpConnectionPrincipal $principal, string $id, UploadedFile $file): CustomerFile
    {
        $customer = $principal->customer();
        $principal->authorize($customer, 'mcp:uploads');

        return DB::transaction(function () use ($principal, $customer, $id, $file) {
            \App\Models\Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $session = DB::table('mcp_upload_sessions')->where('id', $id)->where('customer_id', $customer->id)
                ->where('connection_id', $principal->connectionId)->lockForUpdate()->first();
            if (! $session || now()->greaterThanOrEqualTo($session->expires_at)) {
                throw new ApiProblem('invalid_file', 404);
            }
            $this->authorizePurpose($principal, $session->purpose);
            if ($session->customer_file_id) {
                return $this->owned($principal, $session->customer_file_id);
            }
            $reference = $session->purpose === 'voice_reference';
            $boundary = app(InputBoundary::class);
            $session->purpose === 'ocr' ? $boundary->document($file, ['pages' => 'all']) : $boundary->audio($file, $reference);
            $saved = app(CustomerOutputStorage::class)->storeJobInputFile($customer, $file, $reference ? 'vector-v2' : 'mcp', $id,
                ['purpose' => $reference ? 'reference' : 'input', 'role' => $reference ? 'speaker_reference' : 'source_file', 'mcp_purpose' => $session->purpose,
                    'source_type' => 'mcp_upload', 'source_id' => $id, 'retention_mode' => 'temporary',
                    'expires_at' => now()->addDays(config('customer_api.temporary_file_ttl_days', 7))->toIso8601String()]);
            $record = CustomerFile::where('customer_id', $customer->id)->where('disk', $saved['disk'])->where('path', $saved['path'])->firstOrFail();
            DB::table('mcp_upload_sessions')->where('id', $id)->update(['customer_file_id' => $record->id, 'updated_at' => now()]);
            if ($reference) {
                app(\App\Services\MetKurd\V2\CttsWorkspaceCache::class)->forgetReferences($customer->id);
            }

            return $record;
        });
    }

    public function owned(McpConnectionPrincipal $principal, int $id): CustomerFile
    {
        $file = CustomerFile::where('customer_id', $principal->customer()->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->find($id);
        if (! $file) {
            throw new ApiProblem('invalid_file', 404);
        }

        return $file;
    }

    /** A bounded local copy for existing native UploadedFile entry points, always removed. */
    public function withInput(McpConnectionPrincipal $principal, int $id, string $service, callable $work): mixed
    {
        $file = $this->owned($principal, $id);
        $purpose = match ($service) {
            'transcriptions' => 'transcription', 'captions' => 'caption', default => $service
        };
        if ($file->purpose !== 'input' || data_get($file->meta, 'mcp_purpose') !== $purpose || $file->size_bytes > 100 * 1024 * 1024) {
            throw new ApiProblem('invalid_file');
        }
        $source = Storage::disk($file->disk)->readStream($file->path);
        $temp = tmpfile();
        try {
            if (! is_resource($source) || ! is_resource($temp)) {
                throw new ApiProblem('invalid_file');
            }
            $bytes = stream_copy_to_stream($source, $temp, 100 * 1024 * 1024 + 1);
            if ($bytes === false || $bytes <= 0 || $bytes > 100 * 1024 * 1024) {
                throw new ApiProblem('invalid_file');
            }
            $upload = new UploadedFile(stream_get_meta_data($temp)['uri'], 'input.'.pathinfo($file->path, PATHINFO_EXTENSION), $file->mime, null, true);

            return $work($upload);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($temp)) {
                fclose($temp);
            }
        }
    }

    public function recent(McpConnectionPrincipal $principal): array
    {
        $principal->authorize($principal->customer(), 'v2:files:download');
        $files = CustomerFile::where('customer_id', $principal->customer()->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->where('purpose', 'reference')->orWhere('source_type', 'mcp_upload'))
            ->orderByDesc('id')->limit(20)->get();

        return ['files' => $files->map(fn ($file) => ['file_id' => $file->id,
            'reference_id' => $file->purpose === 'reference' ? $file->id : null, 'purpose' => data_get($file->meta, 'mcp_purpose', $file->purpose),
            'mime_type' => $file->mime, 'size_bytes' => $file->size_bytes, 'expires_at' => $file->expires_at?->toIso8601String()])->all()];
    }

    public function result(McpConnectionPrincipal $principal, string $id): CustomerFile
    {
        $customer = $principal->customer();
        $principal->authorize($customer, 'v2:files:download');
        $link = ApiResultFile::where('customer_id', $customer->id)->whereNull('deleted_at')
            ->whereHas('apiJob', fn ($q) => $q->where('customer_id', $customer->id)->where('meta->api_version', 2)->where('status', 'completed'))->find($id);
        if (! $link || (data_get($link->apiJob->meta, 'expires_at') && now()->greaterThanOrEqualTo(data_get($link->apiJob->meta, 'expires_at')))) {
            throw new ApiProblem('invalid_file', 404);
        }

        return $this->owned($principal, $link->storage_file_id);
    }
}
