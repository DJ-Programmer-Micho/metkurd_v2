<?php

namespace App\Services\Mcp;

class ToolOutputSchemas
{
    private function object(array $properties, ?array $required = null): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required ?? array_keys($properties), 'additionalProperties' => false];
    }

    public function forTool(string $name): array
    {
        $string = ['type' => 'string'];
        $nullable = ['type' => ['string', 'null']];
        $integer = ['type' => 'integer'];
        $list = fn ($item) => ['type' => 'array', 'items' => $item];
        $error = $this->object(['error' => $this->object(['code' => $string])]);
        $file = $this->object(['id' => $string, 'kind' => $string, 'mime_type' => $nullable, 'size_bytes' => $integer,
            'expires_at' => $nullable, 'download_url' => $string, 'resource_uri' => $string, 'artifact_uri' => $string]);
        $jobResult = $this->object(['files' => $list($file), 'expired' => ['type' => 'boolean'], 'text' => $string,
            'srt' => $nullable, 'truncated' => ['type' => 'boolean'], 'characters' => $integer, 'words' => $integer,
            'lines' => $integer, 'chunks' => $integer, 'segment_count' => $integer, 'stem_count' => ['type' => 'integer', 'enum' => [2, 4]],
            'total_chars' => $integer, 'duration' => ['type' => 'number']], ['files']);
        $jobResult['type'] = ['object', 'null'];
        $job = $this->object(['job_id' => $string, 'status' => $string, 'service' => $nullable, 'created_at' => $string,
            'completed_at' => $nullable, 'expires_at' => $nullable, 'result' => $jobResult, 'resource_uri' => $string,
            'credits_reserved' => $integer, 'error' => $this->object(['code' => $string, 'message' => $string])],
            ['job_id', 'status', 'service', 'created_at', 'completed_at', 'expires_at', 'result', 'resource_uri', 'credits_reserved']);
        $success = match ($name) {
            'list_services' => $this->object(['services' => $list($string)]),
            'list_voices' => $this->object(['voices' => $list($this->object(['id' => $string, 'name' => $string]))]),
            'list_recent_files' => $this->object(['files' => $list($this->object(['file_id' => $integer,
                'reference_id' => ['type' => ['integer', 'null']], 'purpose' => $string, 'mime_type' => $nullable,
                'size_bytes' => $integer, 'expires_at' => $nullable]))]),
            'create_upload_session' => $this->object(['upload_session_id' => $string, 'upload_url' => $string,
                'expires_at' => $string, 'file_id' => ['type' => ['integer', 'null']]]),
            default => $job,
        };

        // Object root for the handshake-era protocol; errors keep the established envelope.
        return ['type' => 'object', 'anyOf' => [$success, $error]];
    }
}
