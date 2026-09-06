<?php

namespace App\Services\MetKurd\V2;

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Services\Media\AudioProbeService;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use App\Services\OCR\OcrDocumentProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Authoritative input preparation shared by web and API; no billing supplied by clients. */
class InputBoundary
{
    public const AUDIO_MIMES = 'audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac';

    public function characterLimit(Customer $customer, string $action): int
    {
        return max(1, (int) (method_exists($customer, 'entitlementLimitFor') ? ($customer->entitlementLimitFor($action, 'max_chars_per_submit') ?? 400) : 400));
    }

    public function text(Customer $customer, string $action, array $input, bool $voice = false): array
    {
        $input['text'] = is_string($input['text'] ?? null) ? trim($input['text']) : ($input['text'] ?? null);
        $data = Validator::make($input, [
            'text' => ['required', 'string', 'max:'.$this->characterLimit($customer, $action)],
            'language' => ['required', 'in:ckb,en,ar'],
            'voice' => $voice ? ['required', 'string'] : ['nullable', 'string'],
            'reference_text' => ['nullable', 'string', 'max:4000'],
        ])->validate();
        if ($voice) {
            $speaker = collect(app(OmniSpeakerCatalog::class)->forCustomer($customer, app()->getLocale()))->pluck('speakers')->flatten(1)->firstWhere('code', $data['voice']);
            if (! $speaker) {
                throw ValidationException::withMessages(['voice' => __('The selected voice is no longer available.')]);
            }
            $data['ref_audio'] = $speaker['ref_audio'];
        }

        return $data;
    }

    public function audio(UploadedFile $file, bool $reference = false): array
    {
        Validator::make(['audioFile' => $file], ['audioFile' => ['required', 'file', 'mimetypes:'.($reference ? str_replace(',audio/flac,audio/x-flac', '', self::AUDIO_MIMES) : self::AUDIO_MIMES), 'max:'.($reference ? 20480 : 102400)]])->validate();
        try {
            $info = app(AudioProbeService::class)->probeUploadedFile($file);
            $seconds = (float) ($info['duration_sec'] ?? 0);
            if (! is_finite($seconds) || $seconds <= 0) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['audioFile' => __('Failed to inspect the uploaded audio.')]);
        }

        return array_merge($info, [
            'duration_sec' => $seconds, 'billable_min' => max(1, (int) ceil($seconds / 60)),
            'billable_minutes' => max(1, (int) ceil($seconds / 60)), 'input_hash' => $this->hash($file),
            'audio_name' => $file->getClientOriginalName(), 'audio_mime' => $file->getMimeType(),
        ]);
    }

    public function reference(Customer $customer, int $id): CustomerFile
    {
        $file = CustomerFile::query()->where('customer_id', $customer->id)->where('status', 'active')->where('purpose', 'reference')
            ->whereIn('tool_code', ['clone_tts', 'clone_xomni', 'vector-v2'])->find($id);
        if (! $file || ($file->expires_at && $file->expires_at->isPast()) || ! Storage::disk($file->disk)->exists($file->path)) {
            throw ValidationException::withMessages(['reference_id' => __('That saved reference voice is no longer available.')]);
        }
        $stream = Storage::disk($file->disk)->readStream($file->path);
        $temporary = tempnam(sys_get_temp_dir(), 'mk-reference-');
        if (! is_resource($stream) || $temporary === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($temporary !== false) {
                unlink($temporary);
            }
            throw ValidationException::withMessages(['reference_id' => __('Failed to inspect the uploaded audio.')]);
        }
        try {
            $target = fopen($temporary, 'wb');
            if (! is_resource($target)) {
                throw new \RuntimeException('Could not prepare reference inspection.');
            }
            try {
                $bytes = stream_copy_to_stream($stream, $target, 20480 * 1024 + 1);
            } finally {
                fclose($target);
            }
            if ($bytes === false || $bytes > 20480 * 1024) {
                throw ValidationException::withMessages(['reference_id' => __('Upload failed')]);
            }
            $this->audio(new UploadedFile($temporary, basename($file->path), null, null, true), true);
        } finally {
            fclose($stream);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $file;
    }

    public function document(UploadedFile $file, array $options): array
    {
        Validator::make(['documentFile' => $file], ['documentFile' => 'required|file|mimes:pdf,jpg,jpeg,png,webp,bmp,gif,tif,tiff|max:102400'])->validate();
        $probe = app(OcrDocumentProbe::class);
        try {
            $pages = $probe->selectedPages($probe->pageCount($file), (string) ($options['pages'] ?? 'all'));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['documentFile' => \App\Support\CustomerFacingError::message($e->getMessage())]);
        }

        return array_merge($options, [
            'pages' => implode(',', $pages), 'estimated_pages' => count($pages), 'input_hash' => $this->hash($file),
            'file_name' => $file->getClientOriginalName(), 'file_mime' => $file->getMimeType(),
            'file_ext' => strtolower($file->getClientOriginalExtension()), 'file_bytes' => $file->getSize(),
        ]);
    }

    public function hash(UploadedFile $file): string
    {
        $stream = method_exists($file, 'readStream') ? $file->readStream() : fopen($file->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['file' => __('Upload failed')]);
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
