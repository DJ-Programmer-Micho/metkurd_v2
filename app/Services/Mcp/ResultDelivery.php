<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\Storage;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ReadResourceResult;

/** Delivery of already-persisted results; never reconciles, bills or dispatches. */
class ResultDelivery
{
    public function tool(McpConnectionPrincipal $principal, array $data, string|int $requestId = 0): CallToolResult
    {
        if (($data['status'] ?? null) !== 'completed' || ! empty($data['result']['expired'])) {
            return $this->bounded(new CallToolResult([new TextContent(json_encode($data, JSON_THROW_ON_ERROR))], structuredContent: $data), $requestId);
        }

        $files = [];
        foreach ($data['result']['files'] ?? [] as $descriptor) {
            try {
                $file = app(Files::class)->result($principal, $descriptor['id']);
                $descriptor['mime_type'] = $file->mime;
                $files[] = $descriptor;
            } catch (\Throwable) {
                // Do not embed or advertise a file whose current authority failed.
            }
        }
        $allAuthorized = count($files) === count($data['result']['files'] ?? []);
        $data['result']['files'] = $files;
        $remaining = max(0, (int) config('mcp.inline_total_bytes'));
        $inline = [];
        foreach (['text', 'srt'] as $field) {
            $value = $data['result'][$field] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            // Include readable text once in content and once in structured data.
            $cost = strlen($value) * 2;
            if ($cost > $remaining || ! mb_check_encoding($value, 'UTF-8')) {
                unset($data['result'][$field]);
                $data['result']['truncated'] = true;

                continue;
            }
            $remaining -= $cost;
            if ($field === 'text') {
                $inline[] = new TextContent($value);
            }
            // SRT is delivered from its authoritative file, not duplicated here.
        }

        $service = $data['service'] ?? '';
        $stem = $service === 'stem';
        $stemRoles = ['vocals', 'instrumental', 'drums', 'bass', 'other'];
        $candidates = $stem ? array_values(array_filter($files, fn ($f) => in_array($f['kind'], $stemRoles, true))) : $files;
        $media = [];
        $complete = $allAuthorized;
        if ($stem) {
            $roles = array_column($candidates, 'kind');
            $expected = match ($data['result']['stem_count'] ?? null) {
                2 => ['vocals', 'instrumental'], 4 => ['vocals', 'drums', 'bass', 'other'], default => [],
            };
            sort($roles);
            sort($expected);
            $complete = $complete && $expected !== [] && $roles === $expected;
        }
        foreach ($candidates as $descriptor) {
            $audio = in_array($service, ['speech', 'voice-clone', 'zeta', 'theta', 'stem'], true);
            $text = in_array($service, ['transcriptions', 'captions', 'ocr', 'harakat'], true);
            if (! $audio && ! $text) {
                continue;
            }
            $value = $this->read($principal, $descriptor['id'], $remaining, $audio ? 'audio' : 'text');
            if ($value === null) {
                $complete = false;

                continue;
            }
            $remaining -= strlen($value['bytes']);
            $media[] = new TextContent($descriptor['kind'].' — '.$descriptor['id']);
            $media[] = $audio ? new AudioContent(base64_encode($value['bytes']), $value['mime'])
                : EmbeddedResource::fromText($descriptor['artifact_uri'], $value['bytes'], $value['mime']);
        }
        if ($stem && ! $complete) {
            $media = [];
        }
        $fallback = $data;
        foreach (['text', 'srt'] as $field) {
            if (isset($fallback['result'][$field])) {
                unset($fallback['result'][$field]);
                $fallback['result']['truncated'] = true;
            }
        }
        $links = $this->links($files);
        $content = [...$inline, ...$media, ...$links];
        if ($inline === [] && $media === []) {
            array_unshift($content, new TextContent('Completed. Retrieve the available artifacts using the authenticated MetKurd resources or downloads.'));
        }
        $result = new CallToolResult($content, structuredContent: $data);
        if ($this->fits($result, $requestId)) {
            return $result;
        }

        return $this->bounded(new CallToolResult([
            new TextContent('Completed. Inline content exceeds the response budget; use the authenticated artifact downloads.'),
            ...$links,
        ], structuredContent: $fallback), $requestId);
    }

    /** Actual artifact resources are distinct from the legacy JSON file descriptor. */
    public function resource(McpConnectionPrincipal $principal, string $id, string|int $requestId): ReadResourceResult
    {
        $value = $this->read($principal, $id, max(0, (int) config('mcp.inline_total_bytes')));
        if ($value !== null) {
            $uri = 'metkurd://artifacts/'.$id;
            $contents = str_starts_with($value['mime'], 'audio/')
                ? new BlobResourceContents($uri, $value['mime'], base64_encode($value['bytes']))
                : new TextResourceContents($uri, $value['mime'], $value['bytes']);
            $result = new ReadResourceResult([$contents]);
            if ($this->fits($result, $requestId)) {
                return $result;
            }
        }
        throw new ResourceReadException('Inline artifact unavailable; read its metkurd://files/{file_id} metadata for the authenticated download, subject to current access.');
    }

    public function metadata(string $uri, array $data, string|int $requestId): ReadResourceResult
    {
        $result = new ReadResourceResult([new TextResourceContents($uri, 'application/json', json_encode($data, JSON_THROW_ON_ERROR))]);
        if (! $this->fits($result, $requestId)) {
            throw new ResourceReadException('Metadata exceeds the configured response budget. Use authenticated MetKurd downloads.');
        }

        return $result;
    }

    /** Read at most the remaining budget plus one byte; size_bytes is never trusted. */
    private function read(McpConnectionPrincipal $principal, string $id, int $remaining, ?string $kind = null): ?array
    {
        $stream = null;
        try {
            $file = app(Files::class)->result($principal, $id);
            $limit = min(max(0, (int) config('mcp.inline_file_bytes')), $remaining,
                max(4096, (int) config('mcp.response_bytes')) - 1024);
            $mime = $file->mime;
            $audio = in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/mpeg'], true);
            $text = in_array($mime, ['text/plain', 'text/vtt', 'application/x-subrip', 'text/srt'], true);
            if ($limit < 1 || (! $audio && ! $text) || ($kind === 'audio' && ! $audio) || ($kind === 'text' && ! $text)) {
                return null;
            }
            $stream = Storage::disk($file->disk)->readStream($file->path);
            if (! is_resource($stream)) {
                return null;
            }
            $bytes = stream_get_contents($stream, $limit + 1);
            if ($bytes === false || $bytes === '' || strlen($bytes) > $limit || ! feof($stream) || ! $this->matchesMime($bytes, $mime)) {
                return null;
            }

            return ['bytes' => $bytes, 'mime' => $mime];
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function matchesMime(string $bytes, string $mime): bool
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (in_array($mime, ['audio/wav', 'audio/x-wav'], true)) {
            return in_array($detected, ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'], true)
                && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WAVE';
        }
        if ($mime === 'audio/mpeg') {
            return $detected === 'audio/mpeg';
        }
        if (! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            return false;
        }

        return match ($mime) {
            'text/vtt' => str_starts_with(ltrim($bytes, "\xEF\xBB\xBF"), 'WEBVTT') && in_array($detected, ['text/plain', 'text/vtt'], true),
            'application/x-subrip', 'text/srt' => in_array($detected, ['text/plain', 'application/x-subrip', 'text/srt'], true)
                && preg_match('/\d{2}:\d{2}:\d{2},\d{3} --> \d{2}:\d{2}:\d{2},\d{3}/', $bytes) === 1,
            'text/plain' => $detected === 'text/plain',
            default => false,
        };
    }

    private function links(array $files): array
    {
        return array_map(fn ($file) => new ResourceLink($file['artifact_uri'], $file['id'], title: $file['kind'],
            description: 'Authenticated MetKurd artifact. Download: '.$file['download_url'].' (OAuth bearer token required). Metadata: '.$file['resource_uri'],
            mimeType: $file['mime_type']), $files);
    }

    public function fits(mixed $result, string|int $requestId): bool
    {
        // Default SDK JSON escaping, full JSON-RPC ID/envelope, plus framing headroom.
        return strlen(json_encode(new Response($requestId, $result), JSON_THROW_ON_ERROR)) + 1024 <= max(4096, (int) config('mcp.response_bytes'));
    }

    private function bounded(CallToolResult $result, string|int $requestId): CallToolResult
    {
        if ($this->fits($result, $requestId)) {
            return $result;
        }

        return new CallToolResult([new TextContent('Response exceeds the configured delivery budget; use the authenticated MetKurd job resource.')],
            isError: true, structuredContent: ['error' => ['code' => 'response_too_large']]);
    }
}
