<?php

namespace App\Services\MetKurd\V2;

use App\Models\Customer;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MultiSpeakerInput
{
    public function prepare(Customer $customer, array $segments, bool $clone): array
    {
        // Bound the collection before expanding wildcard validation rules.
        Validator::make(['segments' => $segments], [
            'segments' => ['required', 'array', 'list', 'min:1', 'max:'.config('metkurd_v2.multi_speaker.max_segments')],
        ])->validate();
        foreach ($segments as &$segment) {
            if (is_array($segment) && is_string($segment['text'] ?? null)) {
                $segment['text'] = trim($segment['text']);
            }
        }
        unset($segment);
        $data = Validator::make(['segments' => $segments], [
            'segments' => ['required', 'array', 'list', 'min:1', 'max:'.config('metkurd_v2.multi_speaker.max_segments')],
            'segments.*' => ['required', 'array'],
            'segments.*.id' => ['required', 'string', 'distinct', 'regex:/^[a-zA-Z0-9_-]{1,128}$/'],
            'segments.*.text' => ['required', 'string', 'max:'.config('metkurd_v2.multi_speaker.max_segment_chars')],
            'segments.*.language' => ['required', 'in:ckb,en,ar'],
            'segments.*.pause_after_ms' => ['required', 'integer', 'in:0,500,1000,2000'],
            'segments.*.voice' => $clone ? ['nullable'] : ['required', 'string'],
            'segments.*.reference_id' => $clone ? ['required', 'integer', 'min:1'] : ['nullable'],
            'segments.*.ref_text' => ['nullable', 'string', 'max:4000'],
        ])->validate()['segments'];
        $chars = array_sum(array_map(fn ($s) => mb_strlen($s['text']), $data));
        if ($chars > config('metkurd_v2.multi_speaker.max_total_chars')) {
            throw ValidationException::withMessages(['segments' => __('The project exceeds the total character limit.')]);
        }
        $voices = $clone ? collect() : collect(app(OmniSpeakerCatalog::class)->forCustomer($customer, app()->getLocale()))->pluck('speakers')->flatten(1)->keyBy('code');
        $prepared = [];
        foreach ($data as $index => $segment) {
            $item = [
                'id' => $segment['id'], 'text' => $segment['text'],
                'language' => $segment['language'], 'text_language' => $segment['language'],
                'pause_after_ms' => $index === count($data) - 1 ? 0 : (int) $segment['pause_after_ms'],
            ];
            if ($clone) {
                $item += ['reference_id' => (int) $segment['reference_id'], 'ref_text' => trim($segment['ref_text'] ?? ''), 'ref_max_sec' => 20, 'ref_sample_rate' => 24000];
            } else {
                $voice = $voices->get($segment['voice']);
                $path = (string) ($voice['ref_audio'] ?? '');
                if (! $voice || $path === '' || str_contains($path, '..') || preg_match('~^(?:/|[a-z]+:)~i', $path) || str_contains($path, "\0")) {
                    throw ValidationException::withMessages(["segments.$index.voice" => __('The selected voice is no longer available.')]);
                }
                $item += ['voice' => $segment['voice'], 'ref_audio' => $path, 'ref_text' => $voice['ref_text'] ?? ''];
            }
            $prepared[] = $item;
        }

        return ['segments' => $prepared, 'total_chars' => $chars, 'segment_count' => count($prepared)];
    }
}
