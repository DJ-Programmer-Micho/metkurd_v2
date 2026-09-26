<?php

namespace App\Services\Mcp;

class ToolCatalog
{
    public function definitions(): array
    {
        $string = ['type' => 'string', 'minLength' => 1];
        $call = $string + ['format' => 'uuid', 'description' => 'Generate a new UUID for a deliberate new job. Preserve it on every retry, even across connections to the server.'];
        $language = ['type' => 'string', 'enum' => ['ckb', 'ar', 'en'], 'default' => 'ckb'];
        $model = ['type' => 'string', 'enum' => ['1.5', '2.0'], 'default' => '2.0'];
        $file = ['type' => 'integer', 'minimum' => 1];
        $boolean = ['type' => 'boolean', 'default' => false];
        $definitions = [
            'list_services' => ['scope' => 'v2:jobs:read', 'description' => 'Discover MetKurd services available to this connection.', 'properties' => [], 'required' => []],
            'list_voices' => ['scope' => 'v2:speech', 'description' => 'List public voice IDs and names. Call this before speech when the user has not selected a valid voice ID.', 'properties' => [], 'required' => []],
            'speak' => ['service' => 'speech', 'scope' => 'v2:speech', 'description' => 'Generate Apollo speech. Discover a valid public voice ID with metkurd_list_voices first.',
                'properties' => ['request_id' => $call, 'text' => $string, 'voice' => $string, 'language' => $language, 'model' => $model], 'required' => ['request_id', 'text', 'voice']],
            'get_job' => ['scope' => 'v2:jobs:read', 'description' => 'Read a persisted job and its results. Long jobs finish asynchronously.', 'properties' => ['job_id' => $string], 'required' => ['job_id']],
        ];
        $definitions['clone_voice'] = ['service' => 'voice-clone', 'scope' => 'v2:voice-clone', 'description' => 'Generate Vector speech using an owned saved voice reference.',
            'properties' => ['request_id' => $call, 'text' => $string, 'reference_id' => $file, 'reference_text' => $string + ['maxLength' => 4000], 'language' => $language, 'model' => $model],
            'required' => ['request_id', 'text', 'reference_id']];
        foreach (['zeta', 'theta'] as $service) {
            $reference = $service === 'theta' ? ['reference_id' => $file, 'reference_text' => $string + ['maxLength' => 4000]] : ['voice' => $string];
            $segment = ['type' => 'object', 'additionalProperties' => false,
                'properties' => ['text' => $string + ['maxLength' => (int) config('metkurd_v2.multi_speaker.max_segment_chars')],
                    'language' => $language, 'pause_after_ms' => ['type' => 'integer', 'enum' => [0, 500, 1000, 2000]]] + $reference,
                'required' => ['text', 'language', 'pause_after_ms', $service === 'theta' ? 'reference_id' : 'voice']];
            $definitions[$service] = ['service' => $service, 'scope' => $service === 'theta' ? 'v2:voice-clone' : 'v2:speech',
                'description' => 'Generate one ordered multi-speaker project. No trailing pause. '.($service === 'theta' ? 'Use owned reference IDs.' : 'Discover public voice IDs first.'),
                'properties' => ['request_id' => $call, 'segments' => ['type' => 'array', 'items' => $segment, 'minItems' => 1, 'maxItems' => (int) config('metkurd_v2.multi_speaker.max_segments')]],
                'required' => ['request_id', 'segments']];
        }
        foreach (['transcribe' => 'transcriptions', 'caption' => 'captions', 'ocr' => 'ocr', 'stem' => 'stem'] as $name => $service) {
            $properties = ['request_id' => $call, 'file_id' => $file];
            $properties += match ($service) {
                'ocr' => ['pages' => $string + ['maxLength' => 255], 'exports' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => \App\Services\CustomerApi\V2\ApiSubmission::OCR_EXPORTS], 'maxItems' => 5], 'intelligent' => $boolean],
                'stem' => ['mode' => ['type' => 'integer', 'enum' => [2, 4]]],
                default => ['language' => $language, 'intelligent' => $boolean],
            };
            $definitions[$name] = ['service' => $service, 'scope' => 'v2:'.$service, 'description' => 'Process an owned uploaded file with '.ucfirst($name).'.',
                'properties' => $properties, 'required' => $service === 'stem' ? ['request_id', 'file_id', 'mode'] : ['request_id', 'file_id']];
        }
        $definitions['harakat'] = ['service' => 'harakat', 'scope' => 'v2:harakat', 'description' => 'Add Arabic diacritics with Harakat 1.0.',
            'properties' => ['request_id' => $call, 'text' => $string + ['maxLength' => app(\App\Services\MetKurd\V2\HarakatInput::class)->limit()]], 'required' => ['request_id', 'text']];
        $definitions['list_recent_files'] = ['scope' => 'v2:files:download', 'description' => 'List up to 20 owned active uploaded files and saved references. Chat attachments are not transferred automatically.', 'properties' => [], 'required' => []];
        $definitions['create_upload_session'] = ['scope' => 'mcp:uploads', 'description' => 'Create an expiring browser upload page. Sign in as the same MetKurd customer. Upload creates no processing charge; storage quota applies.',
            'properties' => ['request_id' => $call, 'purpose' => ['type' => 'string', 'enum' => ['ocr', 'transcription', 'caption', 'stem', 'voice_reference']]], 'required' => ['request_id', 'purpose']];
        foreach ($definitions as $name => &$definition) {
            $definition['name'] = 'metkurd_'.$name;
            $definition['paid'] = isset($definition['service']);
            if ($definition['paid']) {
                $definition['description'] .= ' This tool creates a paid MetKurd processing job and consumes API credits. Returns a job ID; use metkurd_get_job later.';
            }
            $definition['schema'] = ['type' => 'object', 'properties' => $definition['properties'] ?: new \stdClass,
                'required' => $definition['required'], 'additionalProperties' => false];
        }

        return $definitions;
    }
}
