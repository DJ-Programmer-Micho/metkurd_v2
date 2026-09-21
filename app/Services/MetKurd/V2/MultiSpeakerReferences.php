<?php

namespace App\Services\MetKurd\V2;

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MultiSpeakerReferences
{
    public function upload(Customer $customer, UploadedFile $file): CustomerFile
    {
        abort_unless($customer->isAllowed('theta.generate', 'app'), 403);
        $info = app(InputBoundary::class)->audio($file, true);
        $record = DB::transaction(function () use ($customer, $file, $info) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $existing = CustomerFile::query()->where('customer_id', $customer->id)->where('status', 'active')
                ->where('purpose', 'reference')->where('tool_code', 'theta')->where('meta->input_hash', $info['input_hash'])
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
            if ($existing && Storage::disk($existing->disk)->exists($existing->path)) {
                return $existing;
            }
            $storage = app(CustomerOutputStorage::class);
            $id = (string) Str::uuid();
            $saved = $storage->storeJobInputFile($customer, $file, 'theta', $id, [
                'purpose' => 'reference', 'role' => 'speaker_reference', 'source_type' => 'upload', 'source_id' => $id,
                'input_hash' => $info['input_hash'], 'duration_sec' => $info['duration_sec'],
            ], baseName: 'reference');

            return CustomerFile::query()->where('customer_id', $customer->id)->where('disk', $saved['disk'])->where('path', $saved['path'])->firstOrFail();
        }, 3);
        app(CttsWorkspaceCache::class)->forgetReferences((int) $customer->id);

        return $record;
    }

    /** Resolve each distinct owned object once, including one URL signature per job. */
    public function urls(Customer $customer, array $ids): array
    {
        $urls = [];
        $objects = [];
        $bytes = 0;
        foreach (array_unique($ids) as $id) {
            $file = app(InputBoundary::class)->reference($customer, (int) $id);
            $key = $file->disk.':'.$file->path;
            if (! isset($objects[$key])) {
                $bytes += (int) $file->size_bytes;
                if ($bytes > config('metkurd_v2.multi_speaker.max_reference_bytes')) {
                    throw new \RuntimeException('The project references exceed the size limit.');
                }
                // Existing S3 references need no second object. Legacy references are
                // secured using the same materialization path as Vector.
                $file = app(\App\Services\MetKurd\Jobs\CloneOmniSubmissionService::class)->prepareReusableReference($customer, $file);
                $objects[$key] = app(CustomerOutputStorage::class)->temporaryUrl($file->path, 120, ['ResponseContentType' => $file->mime]);
            }
            $urls[(int) $id] = $objects[$key];
        }

        return $urls;
    }
}
