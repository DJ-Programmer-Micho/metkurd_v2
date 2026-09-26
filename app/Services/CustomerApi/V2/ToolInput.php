<?php

namespace App\Services\CustomerApi\V2;

use Illuminate\Support\Facades\Validator;

/** Public fields for the additive tools; worker fields never cross this boundary. */
class ToolInput
{
    public function validate(string $service, array $input): array
    {
        $batch = $service !== 'harakat';
        Validator::make(['input' => $input], ['input' => 'required|array:'.($batch ? 'segments' : 'text').',storage_mode'])->validate();
        $input += ['storage_mode' => 'temporary'];
        $rules = ['storage_mode' => 'required|in:temporary,permanent'];
        if (! $batch) {
            return Validator::make($input, $rules + ['text' => 'required|string'])->validate();
        }
        // Bound wildcard expansion before validating individual rows.
        Validator::make($input, ['segments' => ['required', 'array', 'list', 'min:1', 'max:'.config('metkurd_v2.multi_speaker.max_segments')]])->validate();
        $clone = $service === 'theta';
        $rules += [
            'segments' => 'required|array|list',
            'segments.*' => 'required|array:text,language,pause_after_ms,'.($clone ? 'reference_id,reference_text' : 'voice'),
            'segments.*.text' => ['required', 'string'],
            'segments.*.language' => 'required|in:ckb,ar,en',
            'segments.*.pause_after_ms' => 'required|integer|in:0,500,1000,2000',
        ];
        $rules += $clone ? ['segments.*.reference_id' => 'required|integer|min:1', 'segments.*.reference_text' => 'nullable|string|max:4000'] : ['segments.*.voice' => 'required|string'];
        $data = Validator::make($input, $rules)->validate();
        foreach ($data['segments'] as $index => &$segment) {
            $segment['id'] = 'segment-'.($index + 1);
            if ($clone) {
                $segment['ref_text'] = $segment['reference_text'] ?? '';
                unset($segment['reference_text']);
            }
            // JSON object key order is immaterial; segment list order is not.
            ksort($segment);
        }
        unset($segment);

        return $data;
    }
}
