# MCP result-delivery examples

Synthetic local fixture responses, not production data. Credit amounts are illustrative, not quotes. Downloads require OAuth bearer authorization.

These are complete illustrative JSON-RPC response envelopes from the isolated fixtures. Native rendering depends on the MCP host. No production calls generated these examples.

## Small WAV — native audio and protected fallback

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "audio — file_delivery_0"
      },
      {
        "type": "audio",
        "data": "UklGRigAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQQAAAAAAAAA",
        "mimeType": "audio/wav"
      },
      {
        "type": "resource_link",
        "uri": "metkurd://artifacts/file_delivery_0",
        "name": "file_delivery_0",
        "title": "audio",
        "description": "Authenticated MetKurd artifact. Download: https://metkurd.test/mcp/files/file_delivery_0 (OAuth bearer token required). Metadata: metkurd://files/file_delivery_0",
        "mimeType": "audio/wav"
      }
    ],
    "isError": false,
    "structuredContent": {
      "status": "completed",
      "service": "speech",
      "created_at": "2026-10-05T18:19:25+03:00",
      "completed_at": "2026-10-05T18:19:25+03:00",
      "expires_at": "2026-10-12T18:19:25+03:00",
      "result": {
        "files": [
          {
            "id": "file_delivery_0",
            "kind": "audio",
            "mime_type": "audio/wav",
            "size_bytes": 48,
            "expires_at": "2026-10-06T18:19:26+03:00",
            "download_url": "https://metkurd.test/mcp/files/file_delivery_0",
            "resource_uri": "metkurd://files/file_delivery_0",
            "artifact_uri": "metkurd://artifacts/file_delivery_0"
          }
        ]
      },
      "job_id": "job_EXAMPLE_small_wav",
      "resource_uri": "metkurd://jobs/job_EXAMPLE_small_wav",
      "credits_reserved": 75
    }
  }
}
```

## Oversized WAV — authenticated download fallback

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "Completed. Retrieve the available artifacts using the authenticated MetKurd resources or downloads."
      },
      {
        "type": "resource_link",
        "uri": "metkurd://artifacts/file_delivery_0",
        "name": "file_delivery_0",
        "title": "audio",
        "description": "Authenticated MetKurd artifact. Download: https://metkurd.test/mcp/files/file_delivery_0 (OAuth bearer token required). Metadata: metkurd://files/file_delivery_0",
        "mimeType": "audio/wav"
      }
    ],
    "isError": false,
    "structuredContent": {
      "status": "completed",
      "service": "speech",
      "created_at": "2026-10-05T18:20:03+03:00",
      "completed_at": "2026-10-05T18:20:03+03:00",
      "expires_at": "2026-10-12T18:20:03+03:00",
      "result": {
        "files": [
          {
            "id": "file_delivery_0",
            "kind": "audio",
            "mime_type": "audio/wav",
            "size_bytes": 1048577,
            "expires_at": "2026-10-06T18:20:03+03:00",
            "download_url": "https://metkurd.test/mcp/files/file_delivery_0",
            "resource_uri": "metkurd://files/file_delivery_0",
            "artifact_uri": "metkurd://artifacts/file_delivery_0"
          }
        ]
      },
      "job_id": "job_EXAMPLE_oversized_wav",
      "resource_uri": "metkurd://jobs/job_EXAMPLE_oversized_wav",
      "credits_reserved": 75
    }
  }
}
```

## SRT — embedded subtitle text

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "Hello world"
      },
      {
        "type": "text",
        "text": "srt — file_delivery_0"
      },
      {
        "type": "resource",
        "resource": {
          "text": "1\n00:00:00,000 --> 00:00:01,000\nHello world\n",
          "uri": "metkurd://artifacts/file_delivery_0",
          "mimeType": "application/x-subrip"
        }
      },
      {
        "type": "resource_link",
        "uri": "metkurd://artifacts/file_delivery_0",
        "name": "file_delivery_0",
        "title": "srt",
        "description": "Authenticated MetKurd artifact. Download: https://metkurd.test/mcp/files/file_delivery_0 (OAuth bearer token required). Metadata: metkurd://files/file_delivery_0",
        "mimeType": "application/x-subrip"
      }
    ],
    "isError": false,
    "structuredContent": {
      "status": "completed",
      "service": "captions",
      "created_at": "2026-10-05T18:28:38+03:00",
      "completed_at": "2026-10-05T18:28:38+03:00",
      "expires_at": "2026-10-12T18:28:38+03:00",
      "result": {
        "files": [
          {
            "id": "file_delivery_0",
            "kind": "srt",
            "mime_type": "application/x-subrip",
            "size_bytes": 44,
            "expires_at": "2026-10-06T18:28:38+03:00",
            "download_url": "https://metkurd.test/mcp/files/file_delivery_0",
            "resource_uri": "metkurd://files/file_delivery_0",
            "artifact_uri": "metkurd://artifacts/file_delivery_0"
          }
        ],
        "text": "Hello world",
        "srt": "1\n00:00:00,000 --> 00:00:01,000\nHello world\n"
      },
      "job_id": "job_EXAMPLE_srt",
      "resource_uri": "metkurd://jobs/job_EXAMPLE_srt",
      "credits_reserved": 75
    }
  }
}
```

## STEM — complete four-artifact fallback

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "Completed. Retrieve the available artifacts using the authenticated MetKurd resources or downloads."
      },
      {
        "type": "resource_link",
        "uri": "metkurd://artifacts/file_delivery_0",
        "name": "file_delivery_0",
        "title": "vocals",
        "description": "Authenticated MetKurd artifact. Download: https://metkurd.test/mcp/files/file_delivery_0 (OAuth bearer token required). Metadata: metkurd://files/file_delivery_0",
        "mimeType": "audio/wav"
      },
      {
        "type": "resource_link",
        "uri": "metkurd://artifacts/file_delivery_1",
        "name": "file_delivery_1",
        "title": "drums",
        "description": "Authenticated MetKurd artifact. Download: https://metkurd.test/mcp/files/file_delivery_1 (OAuth bearer token required). Metadata: metkurd://files/file_delivery_1",
        "mimeType": "audio/wav"
      },
      {
        "type": "resource_link",
        "uri": "metkurd://artifacts/file_delivery_2",
        "name": "file_delivery_2",
        "title": "bass",
        "description": "Authenticated MetKurd artifact. Download: https://metkurd.test/mcp/files/file_delivery_2 (OAuth bearer token required). Metadata: metkurd://files/file_delivery_2",
        "mimeType": "audio/wav"
      },
      {
        "type": "resource_link",
        "uri": "metkurd://artifacts/file_delivery_3",
        "name": "file_delivery_3",
        "title": "other",
        "description": "Authenticated MetKurd artifact. Download: https://metkurd.test/mcp/files/file_delivery_3 (OAuth bearer token required). Metadata: metkurd://files/file_delivery_3",
        "mimeType": "audio/wav"
      }
    ],
    "isError": false,
    "structuredContent": {
      "status": "completed",
      "service": "stem",
      "created_at": "2026-10-05T18:28:25+03:00",
      "completed_at": "2026-10-05T18:28:25+03:00",
      "expires_at": "2026-10-12T18:28:25+03:00",
      "result": {
        "files": [
          {
            "id": "file_delivery_0",
            "kind": "vocals",
            "mime_type": "audio/wav",
            "size_bytes": 262145,
            "expires_at": "2026-10-06T18:28:25+03:00",
            "download_url": "https://metkurd.test/mcp/files/file_delivery_0",
            "resource_uri": "metkurd://files/file_delivery_0",
            "artifact_uri": "metkurd://artifacts/file_delivery_0"
          },
          {
            "id": "file_delivery_1",
            "kind": "drums",
            "mime_type": "audio/wav",
            "size_bytes": 262145,
            "expires_at": "2026-10-06T18:28:25+03:00",
            "download_url": "https://metkurd.test/mcp/files/file_delivery_1",
            "resource_uri": "metkurd://files/file_delivery_1",
            "artifact_uri": "metkurd://artifacts/file_delivery_1"
          },
          {
            "id": "file_delivery_2",
            "kind": "bass",
            "mime_type": "audio/wav",
            "size_bytes": 262145,
            "expires_at": "2026-10-06T18:28:25+03:00",
            "download_url": "https://metkurd.test/mcp/files/file_delivery_2",
            "resource_uri": "metkurd://files/file_delivery_2",
            "artifact_uri": "metkurd://artifacts/file_delivery_2"
          },
          {
            "id": "file_delivery_3",
            "kind": "other",
            "mime_type": "audio/wav",
            "size_bytes": 262145,
            "expires_at": "2026-10-06T18:28:25+03:00",
            "download_url": "https://metkurd.test/mcp/files/file_delivery_3",
            "resource_uri": "metkurd://files/file_delivery_3",
            "artifact_uri": "metkurd://artifacts/file_delivery_3"
          }
        ],
        "stem_count": 4
      },
      "job_id": "job_EXAMPLE_stem_fallback",
      "resource_uri": "metkurd://jobs/job_EXAMPLE_stem_fallback",
      "credits_reserved": 75
    }
  }
}
```
