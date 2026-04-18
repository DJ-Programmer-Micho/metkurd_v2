# Mobile API Guide

This guide documents the Laravel mobile API for the six METKURD FlutterFlow apps:

- `tts` => `METKURD - TTS`
- `ctts` => `METKURD - CTTS`
- `asr` => `METKURD - ASR`
- `stem` => `METKURD - STEM`
- `ocr` => `METKURD - OCR`
- `tran` => `METKURD - TRAN`

Base path:

```text
/api/mobile
```

General rules:

- Mobile authentication uses Laravel Sanctum bearer tokens.
- Mobile apps support sign-in only. Registration stays website-only.
- Payments, billing changes, password changes, email changes, and advanced account-security management are not exposed here.
- Job and file routes are always app-scoped.
- `GET /api/mobile/{app}/jobs/{jobId}` is the polling endpoint after submission.
- `jobId` is the UUID string from `ml_jobs.id`.
- `fileId` is the numeric integer from `customer_files.id`.
- Completed job detail responses include explicit output file references with the correct `fileId` values and download endpoints.
- Unless noted otherwise, send `Accept: application/json`.

## Authentication

### POST /api/mobile/auth/login

Purpose:

- Sign in an existing customer with email and password.
- Issue a long-lived mobile bearer token.

Used by:

- All mobile apps

Auth:

- No

Request headers:

- `Accept: application/json`
- `Content-Type: application/json`

Request body:

- `email`: string, required, valid email
- `password`: string, required
- `device_name`: string, optional, max `120`
- `app_slug`: string, optional, one of `tts`, `ctts`, `asr`, `stem`, `ocr`, `tran`

Example request:

```json
{
  "email": "user@example.com",
  "password": "secret",
  "device_name": "iPhone 15 Pro",
  "app_slug": "tts"
}
```

Example success response:

```json
{
  "token_type": "Bearer",
  "token": "1|plain-text-token",
  "expires_at": "2026-07-17T10:30:00+00:00",
  "abilities": [
    "mobile",
    "mobile:tts"
  ],
  "user": {
    "id": 14,
    "uid": "f4d2b8f7-2f49-4a0b-933a-f99a6dbd2012",
    "username": "metuser",
    "email": "user@example.com",
    "display_name": "Met User",
    "avatar_url": "https://example.com/admin/images/users/user-dummy-img.jpg",
    "verification_complete": true,
    "service_plan": {
      "code": "premium",
      "name": "Premium",
      "is_paid": true
    },
    "storage": {
      "quota_mb": 10240,
      "used_bytes": 1048576,
      "over_quota": false,
      "upload_blocked": false
    },
    "accessible_apps": [
      {
        "slug": "tts",
        "name": "METKURD - TTS",
        "tool_codes": [
          "tts",
          "ftts"
        ],
        "job_submission_enabled": true,
        "upload_enabled": false
      }
    ],
    "token": {
      "abilities": [
        "mobile",
        "mobile:tts"
      ],
      "expires_at": "2026-07-17T10:30:00+00:00"
    }
  }
}
```

Example error response:

```json
{
  "message": "These credentials do not match our records.",
  "errors": {
    "email": [
      "These credentials do not match our records."
    ]
  }
}
```

Notes:

- Only existing website accounts can sign in.
- Inactive accounts return `423 Locked`.
- Accounts that have not completed website verification return `403 Forbidden`.
- If `app_slug` is provided, the token is scoped to that app only.

### POST /api/mobile/auth/social/{provider}

Purpose:

- Sign in an existing customer with a native mobile provider access token.

Used by:

- All mobile apps

Auth:

- No

Route parameter:

- `provider`: required, `google` or `github`

Request headers:

- `Accept: application/json`
- `Content-Type: application/json`

Request body:

- `access_token`: string, required
- `device_name`: string, optional, max `120`
- `app_slug`: string, optional, one of `tts`, `ctts`, `asr`, `stem`, `ocr`, `tran`

Example request:

```json
{
  "access_token": "provider-access-token",
  "device_name": "Pixel 9",
  "app_slug": "asr"
}
```

Example success response:

```json
{
  "token_type": "Bearer",
  "token": "2|plain-text-token",
  "expires_at": "2026-07-17T10:30:00+00:00",
  "abilities": [
    "mobile",
    "mobile:asr"
  ],
  "user": {
    "id": 14,
    "uid": "f4d2b8f7-2f49-4a0b-933a-f99a6dbd2012",
    "username": "metuser",
    "email": "user@example.com",
    "display_name": "Met User",
    "avatar_url": "https://example.com/admin/images/users/user-dummy-img.jpg",
    "verification_complete": true
  }
}
```

Notes:

- The backend does not create new customers here.
- The social identity must match an existing website account.

### GET /api/mobile/auth/me

Purpose:

- Return the current authenticated customer payload for mobile bootstrap.

Used by:

- All mobile apps

Auth:

- Yes

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

Notes:

- Use this as the source of truth for current plan, storage state, app access, and token expiry.

### POST /api/mobile/auth/logout

Purpose:

- Revoke the current bearer token.

Used by:

- All mobile apps

Auth:

- Yes

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

Example success response:

```json
{
  "message": "Logged out successfully."
}
```

### GET /api/mobile/auth/apps

Purpose:

- Return the mobile apps the customer is allowed to use.

Used by:

- All mobile apps

Auth:

- Yes

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

Example success response:

```json
{
  "data": [
    {
      "slug": "tts",
      "name": "METKURD - TTS",
      "tool_codes": [
        "tts",
        "ftts"
      ],
      "job_submission_enabled": true,
      "upload_enabled": false
    },
    {
      "slug": "asr",
      "name": "METKURD - ASR",
      "tool_codes": [
        "asr",
        "wasr",
        "qasr"
      ],
      "job_submission_enabled": true,
      "upload_enabled": true
    }
  ]
}
```

## Shared Job Endpoints

### GET /api/mobile/{app}/jobs

Purpose:

- List the current customer's jobs for one mobile app only.

Used by:

- All mobile apps

Auth:

- Yes

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

Query parameters:

- `per_page`: optional integer, clamped to `1..50`, default `15`

Notes:

- Results are newest first.
- Each app only sees its own scoped tool/job data.

### GET /api/mobile/{app}/jobs/{jobId}

Purpose:

- Return one job in the requested app scope.

Used by:

- All mobile apps

Auth:

- Yes

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

Notes:

- Use this endpoint to poll job status after creation.
- The job must belong to the authenticated customer and app scope.
- For active RunPod jobs, the backend performs a status sync before returning the response.
- When a job is `done`, `result.outputs` and `result.primary_output` give you the real output file references for mobile download.
- Do not pass a `jobId` into `/files/{fileId}` routes. The identifiers are different types and are not interchangeable.

Shared job response shape:

```json
{
  "data": {
    "id": "0f228962-b4fc-4b9d-bb1d-28f4f2c9ee91",
    "app": "tran",
    "status": "running",
    "job_kind": "tran",
    "tool_code": "tran",
    "tool_action": "tran.standard",
    "summary": "Nav nivisina min e.",
    "credits_charged": 21,
    "storage_in_bytes": 20,
    "storage_out_bytes": 0,
    "provider": "runpod",
    "created_at": "2026-04-18T08:30:00+00:00",
    "updated_at": "2026-04-18T08:30:00+00:00",
    "started_at": "2026-04-18T08:30:00+00:00",
    "finished_at": null,
    "input": {
      "text_preview": "Nav nivisina min e.",
      "source_lang": "ku",
      "target_lang": "en"
    },
    "result": {
      "text_preview": "",
      "has_output": false,
      "has_error": false,
      "outputs": [],
      "primary_output": null
    }
  }
}
```

Completed-job output example:

```json
{
  "data": {
    "id": "34e81622-7fbc-4cb2-b045-33f54193ffdc",
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

## Job Submission Endpoints

All job creation routes return:

- `201 Created` on success
- `message`
- `data.job`
- `data.next_actions.status_url`
- `data.next_actions.jobs_url`
- `data.next_actions.files_url`
- `data.next_actions.recommended_poll_interval_seconds`

Common success response shape:

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
      "summary": "Hello from the XTTS mobile endpoint.",
      "credits_charged": 34,
      "storage_in_bytes": 0,
      "storage_out_bytes": 0,
      "provider": "runpod",
      "created_at": "2026-04-18T08:30:00+00:00",
      "updated_at": "2026-04-18T08:30:00+00:00",
      "started_at": "2026-04-18T08:30:00+00:00",
      "finished_at": null,
      "input": {
        "text_preview": "Hello from the XTTS mobile endpoint.",
        "speaker_id": "liza",
        "language": "ar"
      },
      "result": {
        "text_preview": "",
        "has_output": false,
        "has_error": false
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

### POST /api/mobile/tts/jobs

Purpose:

- Create an XTTS or F5TTS speech-generation job.

Used by:

- `METKURD - TTS`

When to use:

- Submit plain text for speech synthesis.

Auth:

- Yes

Request type:

- `application/json`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`
- `Content-Type: application/json`

Request body fields:

| Key | Type | Required | Allowed values | Default | Description |
| --- | --- | --- | --- | --- | --- |
| `tool_code` | string | yes | `tts`, `ftts` | none | Selects XTTS or F5TTS mode. |
| `text` | string | yes | `1..400` chars unless plan entitlement overrides | none | Source text to synthesize. |
| `speaker_id` | string | yes | must be available for the user's plan and selected engine | none | Voice code. |
| `language` | string | XTTS only | any string up to `8` chars | `ar` | XTTS language code. |
| `split` | boolean | XTTS only | `true`, `false` | `true` | XTTS text splitting. |
| `max_words` | integer | XTTS only | `5..80` | `25` | XTTS split chunk size. |
| `fade_ms` | integer | XTTS only | `0..1000` | `80` | XTTS fade overlap. |
| `temperature` | number | XTTS only | `0..2.5` | `0.65` | XTTS sampling temperature. |
| `top_k` | integer | XTTS only | `0..100` | `50` | XTTS sampling top-k. |
| `top_p` | number | XTTS only | `0..1` | `0.8` | XTTS sampling top-p. |
| `repetition_penalty` | number | XTTS only | `1..8` | `2.0` | XTTS repetition penalty. |
| `length_penalty` | number | XTTS only | `-5..6` | `1.0` | XTTS length penalty. |
| `speed` | number | XTTS or F5TTS | XTTS: `0.5..2.0`, F5TTS: `0.1+` | `1.0` | Playback speed. |
| `use_ema` | boolean | F5TTS only | `true`, `false` | tool meta or `true` | F5TTS EMA toggle. |
| `nfe_step` | integer | F5TTS only | `1+` | tool meta or `32` | F5TTS NFE steps. |
| `cfg_strength` | number | F5TTS only | `0+` | tool meta or `2.0` | F5TTS CFG strength. |
| `remove_silence` | boolean | F5TTS only | `true`, `false` | tool meta or `false` | Remove silence from output. |

Validation:

- `tool_code` is required and must be `tts` or `ftts`.
- `text` is trimmed and must not be blank.
- `speaker_id` must exist in the backend plan-scoped voice catalog for the selected engine.
- XTTS fields are validated only when `tool_code=tts`.
- F5TTS fields are validated only when `tool_code=ftts`.
- The selected action must be allowed by the customer's real backend entitlement state.
- Concurrency is checked before creation.

Example XTTS request:

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

Example F5TTS request:

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

Example errors:

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

```json
{
  "message": "Your plan does not allow XTTS."
}
```

Notes:

- No file upload is used here.
- Credits are charged server-side before provider start and refunded on start failure.
- After the job finishes, poll the job detail endpoint and use `result.primary_output.download_endpoint`.

### POST /api/mobile/ctts/jobs

Purpose:

- Create a Clone XTTS job with direct reference-audio upload.

Used by:

- `METKURD - CTTS`

When to use:

- Submit text plus a short reference voice sample.

Auth:

- Yes

Request type:

- `multipart/form-data`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

File handling:

- Submit the reference audio directly in the job request as `referenceAudio`.
- The current production job flow does not accept `file_id`.
- `POST /api/mobile/ctts/files/upload` is still available for app-scoped storage drafts, but the real job contract is direct multipart upload.

Request body fields:

| Key | Type | Required | Allowed values | Default | Description |
| --- | --- | --- | --- | --- | --- |
| `text` | string | yes | `1..400` chars unless plan entitlement overrides | none | Text to synthesize. |
| `referenceAudio` | file | yes | audio/wav, audio/x-wav, audio/mpeg, audio/mp3, audio/mp4, audio/x-m4a, audio/aac, audio/ogg, audio/webm | none | Voice reference sample, max `20480 KB`. |
| `language` | string | no | max `8` chars | `ar` | Language code. |
| `split` | boolean | no | `true`, `false` | `true` | Text splitting toggle. |
| `max_words` | integer | no | `5..80` | `25` | Split chunk size. |
| `fade_ms` | integer | no | `0..1000` | `80` | Fade overlap. |
| `temperature` | number | no | `0..2.5` | `0.65` | Sampling temperature. |
| `top_k` | integer | no | `0..100` | `50` | Sampling top-k. |
| `top_p` | number | no | `0..1` | `0.8` | Sampling top-p. |
| `repetition_penalty` | number | no | `1..8` | `2.0` | Repetition penalty. |
| `length_penalty` | number | no | `-5..6` | `1.0` | Length penalty. |
| `speed` | number | no | `0.5..2.0` | `1.0` | Playback speed. |

Validation:

- `referenceAudio` is required and must pass the exact audio MIME list above.
- The customer's plan must allow `clone_tts.standard`.
- Plan concurrency and clone-lock checks both apply.
- Duplicate provider fulfillment is still protected server-side.

Example curl request:

```bash
curl -X POST "https://example.com/api/mobile/ctts/jobs" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|plain-text-token" \
  -F "text=Clone this voice into a new Kurdish phrase." \
  -F "language=ar" \
  -F "referenceAudio=@voice.mp3"
```

Example error:

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

Notes:

- The reference file is stored in customer storage and linked to the job input.
- The backend generates a temporary S3 URL for the provider.
- When the job finishes, use `result.outputs` from the job detail response for the generated audio. The input reference file and the output render have different file ids.

### POST /api/mobile/asr/jobs

Purpose:

- Create a WASR or QASR transcription job.

Used by:

- `METKURD - ASR`

When to use:

- Submit source audio for transcription.

Auth:

- Yes

Request type:

- `multipart/form-data`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

File handling:

- Submit the source audio directly as `audioFile`.
- The live job flow does not currently accept `file_id`.

Request body fields:

| Key | Type | Required | Allowed values | Default | Description |
| --- | --- | --- | --- | --- | --- |
| `tool_code` | string | yes | `wasr`, `qasr` | none | Selects WASR or QASR flow. |
| `audioFile` | file | yes | audio/wav, audio/x-wav, audio/mpeg, audio/mp3, audio/mp4, audio/x-m4a, audio/aac, audio/ogg, audio/webm, audio/flac, audio/x-flac | none | Source audio, max `102400 KB`. |
| `language` | string | WASR only | `ckb`, `ar`, `en` | `ckb` | WASR language code. |
| `chunkLengthS` | integer | WASR only | `5..120` | `30` | WASR chunk length. |
| `strideLeftS` | integer | WASR only | `0..30` | `5` | WASR left stride. |
| `strideRightS` | integer | WASR only | `0..30` | `5` | WASR right stride. |
| `beamSize` | integer | WASR only | `1..20` | `5` | WASR beam search size. |
| `modelVariant` | string | QASR only | `fine_tuned` | `fine_tuned` | QASR model variant. |

Validation:

- `tool_code` is required and must be `wasr` or `qasr`.
- `audioFile` is required for both modes.
- Audio duration is probed server-side and pricing is based on billable minutes.
- The customer's plan must allow the chosen action.
- ASR lock rules prevent duplicate active processing of the same input.

Example WASR request:

```bash
curl -X POST "https://example.com/api/mobile/asr/jobs" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|plain-text-token" \
  -F "tool_code=wasr" \
  -F "language=ckb" \
  -F "chunkLengthS=45" \
  -F "strideLeftS=5" \
  -F "strideRightS=5" \
  -F "beamSize=4" \
  -F "audioFile=@speech.mp3"
```

Example QASR request:

```bash
curl -X POST "https://example.com/api/mobile/asr/jobs" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|plain-text-token" \
  -F "tool_code=qasr" \
  -F "modelVariant=fine_tuned" \
  -F "audioFile=@speech.wav"
```

Example errors:

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

Notes:

- A successful submission stores the audio input in customer storage and creates a live-locked job.
- Poll `GET /api/mobile/asr/jobs/{jobId}` until the status becomes terminal.
- On completed jobs, use the job detail response for output references such as transcript text and transcript JSON.

### POST /api/mobile/stem/jobs

Purpose:

- Create a STEM source-separation job.

Used by:

- `METKURD - STEM`

When to use:

- Split a source track into `2` or `4` stems.

Auth:

- Yes

Request type:

- `multipart/form-data`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

Request body fields:

| Key | Type | Required | Allowed values | Default | Description |
| --- | --- | --- | --- | --- | --- |
| `audioFile` | file | yes | same audio MIME list as ASR | none | Source audio, max `102400 KB`. |
| `stems` | integer | no | `2`, `4` | `4` | Determines `stem.sep2` or `stem.sep4`. |
| `model` | string | no | any string up to `100` chars | `htdemucs_ft` | Separation model name. |
| `stemCodec` | string | no | `mp3` | `mp3` | Output codec. |
| `stemBitrate` | string | no | `192k` | `192k` | Output bitrate. |

Validation:

- `audioFile` is required.
- `stems` must be `2` or `4`.
- `stemCodec` must be `mp3`.
- `stemBitrate` must be `192k`.
- The customer's plan must allow `stem.sep2` or `stem.sep4`.
- Stem lock rules enforce same-file conflict protection and concurrency control.

Example request:

```bash
curl -X POST "https://example.com/api/mobile/stem/jobs" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|plain-text-token" \
  -F "stems=2" \
  -F "model=htdemucs_ft" \
  -F "stemCodec=mp3" \
  -F "stemBitrate=192k" \
  -F "audioFile=@song.mp3"
```

Example error:

```json
{
  "message": "You reached your concurrent job limit for the current plan."
}
```

Notes:

- Credits are calculated from the selected separation mode.
- The response `tool_action` will be `stem.sep2` or `stem.sep4`.
- On completed jobs, `result.outputs` includes the generated stem tracks and related artifacts with correct numeric file ids.

### POST /api/mobile/ocr/jobs

Purpose:

- Create an OCR job from a PDF document.

Used by:

- `METKURD - OCR`

When to use:

- Upload and process a PDF for OCR extraction.

Auth:

- Yes

Request type:

- `multipart/form-data`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`

Request body fields:

| Key | Type | Required | Allowed values | Default | Description |
| --- | --- | --- | --- | --- | --- |
| `documentFile` | file | yes | PDF only | none | Source PDF, max `204800 KB`. |
| `lang` | string | no | max `50` chars | `ckb+ara+eng` | OCR language pack string. |
| `pageRange` | string | no | max `255` chars | empty | Range like `1-3,5`. |
| `dpi` | integer | no | `72..600` | `200` | OCR DPI. |
| `psm` | integer | no | `0..13` | `6` | Tesseract page segmentation mode. |
| `oem` | integer | no | `0..3` | `3` | Tesseract OCR engine mode. |
| `normalize` | boolean | no | `true`, `false` | `false` | Normalize image. |
| `grayscale` | boolean | no | `true`, `false` | `true` | Convert to grayscale. |
| `autocontrast` | boolean | no | `true`, `false` | `true` | Auto contrast. |
| `sharpen` | boolean | no | `true`, `false` | `true` | Sharpen page images. |
| `binarize` | boolean | no | `true`, `false` | `false` | Binarize page images. |
| `clientPdfPageCount` | integer | no | `1+` | null | Optional client-side page count hint for billing preview. |

Validation:

- `documentFile` is required and must be `pdf`.
- Only one active OCR job is allowed per customer at a time.
- OCR pricing is based on estimated pages.
- The customer's plan must allow `ocr.standard`.

Example request:

```bash
curl -X POST "https://example.com/api/mobile/ocr/jobs" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|plain-text-token" \
  -F "lang=ckb+ara+eng" \
  -F "pageRange=1-2" \
  -F "dpi=300" \
  -F "psm=6" \
  -F "oem=3" \
  -F "clientPdfPageCount=4" \
  -F "documentFile=@scan.pdf"
```

Example error:

```json
{
  "message": "You already have an OCR job in progress."
}
```

Notes:

- `pageRange` affects page estimation and provider payload generation.
- The uploaded PDF is stored first, then a temporary URL is handed to the OCR provider.
- On completed jobs, use `result.primary_output` for the extracted text and `result.outputs` for additional artifacts like OCR JSON.

### POST /api/mobile/tran/jobs

Purpose:

- Create a MET Translation text-translation job.

Used by:

- `METKURD - TRAN`

When to use:

- Translate source text between supported languages.

Auth:

- Yes

Request type:

- `application/json`

Headers:

- `Accept: application/json`
- `Authorization: Bearer {token}`
- `Content-Type: application/json`

Request body fields:

| Key | Type | Required | Allowed values | Default | Description |
| --- | --- | --- | --- | --- | --- |
| `text` | string | yes | `1..2400` chars unless plan entitlement overrides | none | Source text to translate. |
| `sourceLang` | string | no | supported translation language code | `ku` | Source language. |
| `targetLang` | string | no | supported translation language code, must differ from `sourceLang` | `en` | Target language. |
| `maxNewTokens` | integer | no | `64..2048` | `256` | Generation cap. |
| `chunkChars` | integer | no | `200..5000` | `1200` | Chunk size for large text. |

Supported language codes:

- `af`, `am`, `ar`, `as`, `be`, `bg`, `bn`, `ca`, `cs`, `da`, `de`, `el`, `en`, `es`, `et`, `eu`, `fa`, `fi`, `fr`, `gl`, `gu`, `ha`, `he`, `hi`, `hr`, `hu`, `id`, `ig`, `is`, `it`, `ja`, `ka`, `kk`, `km`, `kn`, `ko`, `ku`, `lo`, `lt`, `lv`, `ml`, `mr`, `ms`, `my`, `nb`, `ne`, `nl`, `or`, `pa`, `pl`, `pt`, `ro`, `ru`, `si`, `sk`, `sl`, `sr`, `sv`, `sw`, `ta`, `te`, `th`, `tr`, `uk`, `ur`, `vi`, `yo`, `zh`, `zu`

Validation:

- `text` is trimmed and must not be blank.
- `sourceLang` and `targetLang` must be supported codes.
- `targetLang` must differ from `sourceLang`.
- The customer's plan must allow `tran.standard`.
- Concurrency is checked before job creation.

Example request:

```json
{
  "text": "Nav nivisina min e.",
  "sourceLang": "ku",
  "targetLang": "en",
  "maxNewTokens": 300,
  "chunkChars": 1000
}
```

Example error:

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

Notes:

- The source text is persisted to storage as `source.txt`.
- Poll the job detail endpoint until the provider finishes.
- On completed jobs, the translated output file is returned in `result.primary_output`.

## File Upload / Download Endpoints

Identifier contract:

- `jobId` is a UUID string from `ml_jobs.id`.
- `fileId` is a numeric integer from `customer_files.id`.
- Mobile clients should discover downloadable output files from completed job detail responses, then call the matching `/files/{fileId}/download` endpoint.
- `GET /api/mobile/{app}/files` remains useful for browsing app-scoped files, but it is not required for the normal post-completion download flow.

### GET /api/mobile/{app}/files

Purpose:

- List the authenticated customer's files for one app scope.

Auth:

- Yes

### POST /api/mobile/{app}/files/upload

Purpose:

- Store an app-scoped file outside the live job-create flow.

Used by:

- `ctts`, `asr`, `stem`, `ocr`

Auth:

- Yes

Request type:

- `multipart/form-data`

Request body:

- `file`: required upload

Validation:

- `ctts`: audio file, max `51200 KB`
- `asr`: audio file, max `102400 KB`
- `stem`: audio file, max `102400 KB`
- `ocr`: PDF, max `204800 KB`
- `tts` and `tran`: disabled, returns `405`

Notes:

- This route stores app-scoped files and returns a file record.
- The real job-creation endpoints above still expect direct job payloads and do not currently accept `file_id`.

### GET /api/mobile/{app}/files/{fileId}

Purpose:

- Return one app-scoped file record.

Auth:

- Yes

### GET /api/mobile/{app}/files/{fileId}/download

Purpose:

- Create a temporary download URL for an app-scoped file.

Auth:

- Yes

Example response:

```json
{
  "data": {
    "file": {
      "id": 81,
      "app": "ocr",
      "tool_code": "ocr",
      "purpose": "input_document",
      "disk": "s3",
      "name": "scan.pdf",
      "path": "renders/customer-folder/ocr/job-id/input.pdf",
      "mime": "application/pdf",
      "size_bytes": 48012,
      "created_at": "2026-04-18T07:15:00+00:00",
      "download_endpoint": "https://example.com/api/mobile/ocr/files/81/download"
    },
    "download_url": "https://signed-storage-url.example.com/temporary",
    "expires_at": "2026-04-18T09:00:00+00:00"
  }
}
```

Recommended output retrieval flow:

1. Submit a job with `POST /api/mobile/{app}/jobs`
2. Poll `GET /api/mobile/{app}/jobs/{jobId}`
3. When `status=done`, read `data.result.outputs[*].id`
4. Download with `data.result.outputs[*].download_endpoint`

## Error Format

Common patterns:

- `401` => unauthenticated token
- `403` => app access denied or action not allowed by plan
- `405` => upload disabled for that app
- `409` => concurrency conflict, OCR active-job conflict, storage quota block, or same-file lock conflict
- `422` => validation failure or not enough credits
- `423` => inactive account
- `429` => rate limited

Validation example:

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

Forbidden example:

```json
{
  "message": "Your current subscription does not allow this mobile app."
}
```

Storage quota example:

```json
{
  "message": "Your account is over quota. Delete files or upgrade your storage plan to continue."
}
```

## Auth / Token Lifecycle

- Tokens are Laravel Sanctum personal access tokens.
- Default token lifetime is controlled by `MOBILE_API_TOKEN_EXPIRATION_DAYS`.
- Current default is `90` days.
- `POST /api/mobile/auth/logout` revokes the current token only.
- Store the bearer token in secure storage on device.
- When a token expires or is revoked, the API returns `401`.

## App-Specific Notes

- `tts` supports two submission modes through one endpoint: `tool_code=tts` and `tool_code=ftts`.
- `ctts` uses direct multipart reference-audio upload in the job request.
- `asr` supports two submission modes through one endpoint: `tool_code=wasr` and `tool_code=qasr`.
- `stem`, `ocr`, and `ctts` use direct multipart job submission, not `file_id`.
- `tran` is JSON-only and stores the submitted source text in app-scoped storage.
- All job creation, billing, entitlement, concurrency, storage, and provider-start behavior stays server-side and reuses the website business logic.
- For hands-on Postman and FlutterFlow examples, see [docs/mobile-api-job-submission.md](mobile-api-job-submission.md).
