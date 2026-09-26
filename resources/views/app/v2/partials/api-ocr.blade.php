@php($ocr = $documentation->ocrContract())
<div data-api-ocr-documentation>
    <span class="badge bg-primary mb-3">{{ __('api_v2.ocr_multipart') }}</span>
    <p class="alert alert-info">{{ __('api_v2.ocr_upload_intro') }}</p>
    <p><code dir="ltr">Content-Type: multipart/form-data</code><br>{{ __('Upload one file using multipart/form-data. Do not set the multipart boundary manually.') }}</p>

    <div class="border rounded p-3 mb-4">
        <h3 class="h5">{{ __('api_v2.ocr_quick') }}</h3>
        <ol>
            <li>{{ __('api_v2.ocr_step_file') }} <code dir="ltr">document.pdf</code></li>
            <li>{{ __('Create and securely save an API key.') }} <button type="button" class="btn btn-sm btn-outline-primary" @click="section = 'keys'">{{ __('API Keys') }}</button></li>
            <li>{{ __('api_v2.ocr_step_run') }} <code dir="ltr">YOUR_API_KEY</code> / <code dir="ltr">UNIQUE_REQUEST_ID</code></li>
            <li>{{ __('api_v2.ocr_step_accept') }}</li>
            <li>{{ __('api_v2.ocr_step_poll') }} <code dir="ltr">GET /api/v2/jobs/{id}</code></li>
            <li>{{ __('api_v2.ocr_step_download') }} <code dir="ltr">result.text</code> / <code dir="ltr">result.files</code></li>
        </ol>
    </div>
    <h3 class="h5">{{ __('api_v2.ocr_local_file') }}</h3>
    <button type="button" class="btn btn-sm btn-outline-primary" @click="copy($refs.ocrQuick.textContent)">{{ __('Copy') }}</button>
    <pre dir="ltr" class="overflow-auto"><code x-ref="ocrQuick">{{ $documentation->examples($services['ocr'])['cURL'] }}</code></pre>
    <p><code dir="ltr">@./document.pdf</code> — {{ __('api_v2.ocr_local_explanation') }}</p>
    <p>{{ __('api_v2.ocr_windows') }} <code dir="ltr">curl.exe</code> / <code dir="ltr">C:\Documents\document.pdf</code></p>
    <p class="alert alert-info">{{ __('api_v2.ocr_internal_note') }}</p>

    <h3 class="h5">{{ __('api_v2.ocr_fields') }}</h3>
    <div class="api-table"><table>
        <thead><tr><th>{{ __('Field') }}</th><th>{{ __('api_v2.ocr_field_help') }}</th></tr></thead>
        <tbody>
            <tr><td><code dir="ltr">file</code></td><td>{{ __('api_v2.ocr_file_field') }}</td></tr>
            <tr><td><code dir="ltr">pages</code></td><td>{{ __('api_v2.ocr_pages_field') }} <code dir="ltr">all, 1, 1-3, 1-3,5</code></td></tr>
            <tr><td><code dir="ltr">exports[]</code></td><td>{{ __('api_v2.ocr_exports_field') }} <code dir="ltr">{{ implode(', ', $ocr['exports']) }}</code><br>{{ __('api_v2.ocr_default') }} <code dir="ltr">{{ implode(', ', $ocr['default_exports']) }}</code></td></tr>
            <tr><td><code dir="ltr">intelligent</code></td><td>{{ __('api_v2.ocr_intelligent_field') }} {{ __('Improve extracted text with AI correction.') }}</td></tr>
            <tr><td><code dir="ltr">storage_mode</code></td><td>{{ __('api_v2.ocr_storage_field') }} <code dir="ltr">temporary / permanent</code></td></tr>
        </tbody>
    </table></div>
    <h3 class="h5">{{ __('api_v2.ocr_formats') }}</h3>
    <p><code dir="ltr">{{ strtoupper(implode(', ', $ocr['extensions'])) }}</code></p>
    <p>{{ __('api_v2.ocr_limits', ['size' => $ocr['max_mib'], 'pages' => $ocr['max_pages']]) }}</p>
    <h3 class="h5">{{ __('api_v2.ocr_pages') }}</h3>
    <p>{{ __('api_v2.ocr_pages_help') }}</p>
    <h3 class="h5">{{ __('api_v2.ocr_exports') }}</h3>
    <p>{{ __('api_v2.ocr_exports_help') }}</p>
    <p>{{ __('Temporary API files expire :days days after submission and do not count toward permanent storage quota.', ['days' => $ocr['temporary_days']]) }}</p>
    <p>{{ __('Permanent API files count toward your customer storage quota and remain until deleted. Web storage behavior is unchanged. Download important temporary results before expires_at.') }}</p>

    <h3 class="h5">{{ __('api_v2.ocr_async') }}</h3>
    <p>{{ __('api_v2.ocr_async_help') }}</p>
    <p>{{ __('Poll about every 10 seconds while queued or processing. Stop when completed, failed or cancelled. Respect Retry-After on HTTP 429.') }}</p>
    <pre dir="ltr" class="overflow-auto">{{ $documentation->ocrFollowupExamples()['poll'] }}</pre>
    <p>{{ __('api_v2.ocr_download_help') }}</p>
    <pre dir="ltr" class="overflow-auto">{{ $documentation->ocrFollowupExamples()['download'] }}</pre>
</div>
