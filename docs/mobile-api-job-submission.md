# Mobile Job Submission Guide

This guide is the practical companion to [mobile-api.md](mobile-api.md). It focuses on real job creation from Postman and FlutterFlow.

Base path:

```text
/api/mobile
```

Shared notes:

- Authenticate first with `POST /api/mobile/auth/login`.
- Save the returned bearer token and send `Authorization: Bearer {token}` on every later request.
- Poll `GET /api/mobile/{app}/jobs/{jobId}` every `5` seconds after submission.
- Each poll hits the backend sync path for active RunPod jobs before the job JSON is returned.
- File-based job routes use direct `multipart/form-data` submission today. They do not accept `file_id`.
- `jobId` is always a UUID string from `ml_jobs.id`.
- `fileId` is always a numeric integer from `customer_files.id`.
- For normal output retrieval, do not guess the file id. Wait for the completed job detail response and use `result.outputs[*].download_endpoint`.

## TTS

App name:

- `METKURD - TTS`

Endpoint:

- `POST /api/mobile/tts/jobs`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`
- `Content-Type: application/json`

Request body fields:

| Key | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `tool_code` | string | yes | none | `tts` for XTTS, `ftts` for F5TTS |
| `text` | string | yes | none | source text |
| `speaker_id` | string | yes | none | must be allowed for the selected engine |
| `language` | string | XTTS only | `ar` | XTTS only |
| `split` | boolean | XTTS only | `true` | XTTS only |
| `max_words` | integer | XTTS only | `25` | XTTS only |
| `fade_ms` | integer | XTTS only | `80` | XTTS only |
| `temperature` | number | XTTS only | `0.65` | XTTS only |
| `top_k` | integer | XTTS only | `50` | XTTS only |
| `top_p` | number | XTTS only | `0.8` | XTTS only |
| `repetition_penalty` | number | XTTS only | `2.0` | XTTS only |
| `length_penalty` | number | XTTS only | `1.0` | XTTS only |
| `speed` | number | yes | `1.0` | XTTS or F5TTS |
| `use_ema` | boolean | F5TTS only | `true` | F5TTS only |
| `nfe_step` | integer | F5TTS only | `32` | F5TTS only |
| `cfg_strength` | number | F5TTS only | `2.0` | F5TTS only |
| `remove_silence` | boolean | F5TTS only | `false` | F5TTS only |

Sample Postman body for XTTS:

```json
{
  "tool_code": "tts",
  "text": "Hello from the XTTS mobile endpoint.",
  "speaker_id": "liza",
  "language": "ar",
  "split": true,
  "max_words": 20,
  "fade_ms": 60,
  "temperature": 0.7,
  "top_k": 40,
  "top_p": 0.85,
  "repetition_penalty": 2.1,
  "length_penalty": 1.2,
  "speed": 1.0
}
```

Sample Postman body for F5TTS:

```json
{
  "tool_code": "ftts",
  "text": "Hello from the F5TTS mobile endpoint.",
  "speaker_id": "mobile_f5_voice",
  "use_ema": false,
  "nfe_step": 48,
  "cfg_strength": 2.4,
  "speed": 1.1,
  "remove_silence": true
}
```

Sample success response:

```json
{
  "message": "XTTS job started.",
  "data": {
    "job": {
      "id": "fe9844d6-e1b8-4de4-9b11-2ea7df526932",
      "app": "tts",
      "status": "running",
      "job_kind": "tts",
      "tool_code": "tts",
      "tool_action": "tts.standard",
      "input": {
        "text_preview": "Hello from the XTTS mobile endpoint.",
        "speaker_id": "liza",
        "language": "ar"
      }
    },
    "next_actions": {
      "status_url": "https://example.com/api/mobile/tts/jobs/fe9844d6-e1b8-4de4-9b11-2ea7df526932",
      "jobs_url": "https://example.com/api/mobile/tts/jobs",
      "files_url": "https://example.com/api/mobile/tts/files",
      "recommended_poll_interval_seconds": 5
    }
  }
}
```

Completed-job output example:

```json
{
  "data": {
    "id": "fe9844d6-e1b8-4de4-9b11-2ea7df526932",
    "app": "tts",
    "status": "done",
    "job_kind": "tts",
    "tool_code": "tts",
    "tool_action": "tts.standard",
    "result": {
      "text_preview": "",
      "has_output": true,
      "has_error": false,
      "outputs": [
        {
          "id": 123,
          "app": "tts",
          "tool_code": "tts",
          "purpose": "render",
          "role": "audio",
          "name": "out.wav",
          "mime": "audio/wav",
          "size_bytes": 287276,
          "is_primary": true,
          "file_endpoint": "https://example.com/api/mobile/tts/files/123",
          "download_endpoint": "https://example.com/api/mobile/tts/files/123/download"
        }
      ],
      "primary_output": {
        "id": 123,
        "app": "tts",
        "tool_code": "tts",
        "purpose": "render",
        "role": "audio",
        "name": "out.wav",
        "mime": "audio/wav",
        "size_bytes": 287276,
        "is_primary": true,
        "file_endpoint": "https://example.com/api/mobile/tts/files/123",
        "download_endpoint": "https://example.com/api/mobile/tts/files/123/download"
      }
    }
  }
}
```

Sample error responses:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "tool_code": [
      "The tool code field is required."
    ]
  }
}
```

```json
{
  "message": "You reached your concurrent job limit for the current plan."
}
```

Where to use it:

- TTS create screen
- TTS quick-generate action
- Voice selection + generate flow

FlutterFlow notes:

- Use `application/json`.
- Keep `tool_code` in page state if users switch between XTTS and F5TTS.
- Save `job.id` from the response and poll `status_url`.
- Once the job is done, store `result.primary_output.id` or `result.outputs[*].id` in app state before calling the download endpoint.

## CTTS

App name:

- `METKURD - CTTS`

Endpoint:

- `POST /api/mobile/ctts/jobs`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`
- `Content-Type: multipart/form-data`

Request body fields:

| Key | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `text` | string | yes | none | source text |
| `referenceAudio` | file | yes | none | direct reference voice upload |
| `language` | string | no | `ar` | language code |
| `split` | boolean | no | `true` | text splitting |
| `max_words` | integer | no | `25` | split chunk size |
| `fade_ms` | integer | no | `80` | fade overlap |
| `temperature` | number | no | `0.65` | sampling temperature |
| `top_k` | integer | no | `50` | top-k |
| `top_p` | number | no | `0.8` | top-p |
| `repetition_penalty` | number | no | `2.0` | repetition penalty |
| `length_penalty` | number | no | `1.0` | length penalty |
| `speed` | number | no | `1.0` | playback speed |

Sample multipart form:

- `text` => `Clone this voice into a new Kurdish phrase.`
- `language` => `ar`
- `referenceAudio` => attach `voice.mp3`

Sample success response:

```json
{
  "message": "Clone XTTS job started.",
  "data": {
    "job": {
      "id": "d2bb6dc5-7d9d-4efb-a7f9-38994e9ed69a",
      "app": "ctts",
      "status": "running",
      "job_kind": "clone_tts",
      "tool_code": "clone_tts",
      "tool_action": "clone_tts.standard",
      "input": {
        "text_preview": "Clone this voice into a new Kurdish phrase.",
        "reference_audio_name": "voice.mp3",
        "language": "ar"
      }
    }
  }
}
```

Sample error responses:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "referenceAudio": [
      "The reference audio field is required."
    ]
  }
}
```

```json
{
  "message": "Clone XTTS is busy on another device."
}
```

Upload workflow note:

1. For the live job flow, submit `referenceAudio` directly in the job request.
2. `POST /api/mobile/ctts/files/upload` is optional draft storage only.
3. After submission, poll `GET /api/mobile/ctts/jobs/{jobId}`.
4. When the job completes, read `data.result.outputs[*]` from the job detail response.
5. Download with `data.result.outputs[*].download_endpoint`.

Where to use it:

- Clone voice generation form

FlutterFlow notes:

- Use multipart request bodies.
- Do not store a `file_id` for submission; send the file directly.
- Save `job.id` and poll until done.

## ASR

App name:

- `METKURD - ASR`

Endpoint:

- `POST /api/mobile/asr/jobs`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`
- `Content-Type: multipart/form-data`

Request body fields:

| Key | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `tool_code` | string | yes | none | `wasr` or `qasr` |
| `audioFile` | file | yes | none | direct audio upload |
| `language` | string | WASR only | `ckb` | `ckb`, `ar`, `en` |
| `chunkLengthS` | integer | WASR only | `30` | WASR only |
| `strideLeftS` | integer | WASR only | `5` | WASR only |
| `strideRightS` | integer | WASR only | `5` | WASR only |
| `beamSize` | integer | WASR only | `5` | WASR only |
| `modelVariant` | string | QASR only | `fine_tuned` | QASR only |

Sample multipart form for WASR:

- `tool_code` => `wasr`
- `language` => `ckb`
- `chunkLengthS` => `45`
- `strideLeftS` => `5`
- `strideRightS` => `5`
- `beamSize` => `4`
- `audioFile` => attach `speech.mp3`

Sample multipart form for QASR:

- `tool_code` => `qasr`
- `modelVariant` => `fine_tuned`
- `audioFile` => attach `speech.wav`

Sample success response:

```json
{
  "message": "WASR job started.",
  "data": {
    "job": {
      "id": "4d110f47-d5b3-4cef-bb8f-30a504919f06",
      "app": "asr",
      "status": "running",
      "job_kind": "wasr",
      "tool_action": "asr.standard",
      "input": {
        "audio_name": "speech.mp3",
        "lang": "ckb"
      }
    }
  }
}
```

Sample error responses:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "audioFile": [
      "The audio file field is required."
    ]
  }
}
```

```json
{
  "message": "You reached your concurrent job limit for the current plan."
}
```

Upload workflow note:

1. For real job creation, submit `audioFile` directly in the job request.
2. `POST /api/mobile/asr/files/upload` is optional for storing app-scoped files only.
3. Poll `GET /api/mobile/asr/jobs/{jobId}`.
4. When complete, read `data.result.outputs[*]` from the job detail response.
5. Download transcript or JSON artifacts from the returned `download_endpoint` values.

Where to use it:

- ASR upload screen
- Transcription create flow

FlutterFlow notes:

- Use multipart request bodies.
- Keep `tool_code` in state if users switch between WASR and QASR.
- Save the returned `job.id` and poll.

## STEM

App name:

- `METKURD - STEM`

Endpoint:

- `POST /api/mobile/stem/jobs`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`
- `Content-Type: multipart/form-data`

Request body fields:

| Key | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `audioFile` | file | yes | none | source audio |
| `stems` | integer | no | `4` | `2` or `4` |
| `model` | string | no | `htdemucs_ft` | model name |
| `stemCodec` | string | no | `mp3` | currently `mp3` only |
| `stemBitrate` | string | no | `192k` | currently `192k` only |

Sample multipart form:

- `stems` => `2`
- `model` => `htdemucs_ft`
- `stemCodec` => `mp3`
- `stemBitrate` => `192k`
- `audioFile` => attach `song.mp3`

Sample success response:

```json
{
  "message": "STEM job submitted.",
  "data": {
    "job": {
      "id": "e3dfd7b4-4af0-4c2a-9e74-c425dad5835e",
      "app": "stem",
      "status": "running",
      "job_kind": "stem",
      "tool_action": "stem.sep2",
      "input": {
        "audio_name": "song.mp3",
        "stems": 2
      }
    }
  }
}
```

Sample error response:

```json
{
  "message": "You reached your concurrent job limit for the current plan."
}
```

Where to use it:

- Stem separation screen

FlutterFlow notes:

- Use multipart.
- Store `stems` as an integer in page or app state.
- Poll the returned job id.

## OCR

App name:

- `METKURD - OCR`

Endpoint:

- `POST /api/mobile/ocr/jobs`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`
- `Content-Type: multipart/form-data`

Request body fields:

| Key | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `documentFile` | file | yes | none | PDF only |
| `lang` | string | no | `ckb+ara+eng` | OCR language pack |
| `pageRange` | string | no | empty | `1-3,5` style |
| `dpi` | integer | no | `200` | `72..600` |
| `psm` | integer | no | `6` | `0..13` |
| `oem` | integer | no | `3` | `0..3` |
| `normalize` | boolean | no | `false` | preprocessing |
| `grayscale` | boolean | no | `true` | preprocessing |
| `autocontrast` | boolean | no | `true` | preprocessing |
| `sharpen` | boolean | no | `true` | preprocessing |
| `binarize` | boolean | no | `false` | preprocessing |
| `clientPdfPageCount` | integer | no | null | optional client page hint |

Sample multipart form:

- `lang` => `ckb+ara+eng`
- `pageRange` => `1-2`
- `dpi` => `300`
- `psm` => `6`
- `oem` => `3`
- `clientPdfPageCount` => `4`
- `documentFile` => attach `scan.pdf`

Sample success response:

```json
{
  "message": "OCR job submitted.",
  "data": {
    "job": {
      "id": "2ea1d447-cbc4-4cd9-b7b3-8cc7b37cf8c4",
      "app": "ocr",
      "status": "running",
      "job_kind": "ocr",
      "tool_action": "ocr.standard",
      "input": {
        "file_name": "scan.pdf",
        "page_range": "1-2"
      }
    }
  }
}
```

Sample error responses:

```json
{
  "message": "You already have an OCR job in progress."
}
```

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "documentFile": [
      "The document file field is required."
    ]
  }
}
```

Where to use it:

- OCR upload and process screen

FlutterFlow notes:

- Use multipart.
- Keep preprocessing toggles as booleans.
- Poll until the OCR job leaves `queued` or `running`.
- After completion, use `result.primary_output` for the extracted text file and `result.outputs` for any additional OCR artifacts.

## TRAN

App name:

- `METKURD - TRAN`

Endpoint:

- `POST /api/mobile/tran/jobs`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`
- `Content-Type: application/json`

Request body fields:

| Key | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `text` | string | yes | none | source text |
| `sourceLang` | string | no | `ku` | supported source code |
| `targetLang` | string | no | `en` | supported target code, must differ |
| `maxNewTokens` | integer | no | `256` | `64..2048` |
| `chunkChars` | integer | no | `1200` | `200..5000` |

Sample Postman body:

```json
{
  "text": "Nav nivisina min e.",
  "sourceLang": "ku",
  "targetLang": "en",
  "maxNewTokens": 300,
  "chunkChars": 1000
}
```

Sample success response:

```json
{
  "message": "Translation job started.",
  "data": {
    "job": {
      "id": "f93e36dc-9250-4f4f-8a85-52e741d3f5f9",
      "app": "tran",
      "status": "running",
      "job_kind": "tran",
      "tool_action": "tran.standard",
      "input": {
        "text_preview": "Nav nivisina min e.",
        "source_lang": "ku",
        "target_lang": "en"
      }
    }
  }
}
```

Sample error response:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "targetLang": [
      "The target lang field and source lang must be different."
    ]
  }
}
```

Where to use it:

- Translation compose screen

FlutterFlow notes:

- Use JSON.
- Keep `sourceLang` and `targetLang` in dropdown state.
- Poll with `job.id`, then use `result.primary_output.download_endpoint` when the job finishes.

## End-to-End Test Flows

### 1. TTS

1. Login: `POST /api/mobile/auth/login`
2. Create job: `POST /api/mobile/tts/jobs`
3. Poll job detail: `GET /api/mobile/tts/jobs/{jobId}`
4. Read `data.result.primary_output` or `data.result.outputs[*]`
5. Download output from the returned `download_endpoint`

### 2. CTTS

1. Login: `POST /api/mobile/auth/login`
2. Create job with direct audio upload: `POST /api/mobile/ctts/jobs`
3. Poll job detail: `GET /api/mobile/ctts/jobs/{jobId}`
4. Read `data.result.primary_output` or `data.result.outputs[*]`
5. Download output from the returned `download_endpoint`

### 3. ASR

1. Login: `POST /api/mobile/auth/login`
2. Create job with direct audio upload: `POST /api/mobile/asr/jobs`
3. Poll job detail: `GET /api/mobile/asr/jobs/{jobId}`
4. Read `data.result.outputs[*]` for transcript artifacts
5. Download output from the returned `download_endpoint`

### 4. STEM

1. Login: `POST /api/mobile/auth/login`
2. Create job with direct audio upload: `POST /api/mobile/stem/jobs`
3. Poll job detail: `GET /api/mobile/stem/jobs/{jobId}`
4. Read `data.result.outputs[*]` for the generated stem files
5. Download output from the returned `download_endpoint`

### 5. OCR

1. Login: `POST /api/mobile/auth/login`
2. Create job with direct PDF upload: `POST /api/mobile/ocr/jobs`
3. Poll job detail: `GET /api/mobile/ocr/jobs/{jobId}`
4. Read `data.result.primary_output` or `data.result.outputs[*]`
5. Download output from the returned `download_endpoint`

### 6. TRAN

1. Login: `POST /api/mobile/auth/login`
2. Create job: `POST /api/mobile/tran/jobs`
3. Poll job detail: `GET /api/mobile/tran/jobs/{jobId}`
4. Read `data.result.primary_output`
5. Download output from the returned `download_endpoint`
