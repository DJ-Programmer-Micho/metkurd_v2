<?php

use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\CreditWallet;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\CustomerApiKeyService;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\CustomerApi\V2\ApiDocumentation;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::v2.layouts.app')] class extends Component {
    public string $keyName = '';

    #[Computed]
    public function keys() { return CustomerApiKey::where('customer_id', auth('app')->id())->latest()->get(['id', 'name', 'key_prefix', 'status', 'created_at', 'last_used_at']); }

    #[Computed]
    public function access(): array { return app(CustomerApiAccessService::class)->configForCustomer(auth('app')->user()); }

    public function createKey(): void
    {
        $this->validate(['keyName' => 'required|string|max:100']);
        $issued = DB::transaction(function () {
            $customer = Customer::lockForUpdate()->findOrFail(auth('app')->id());
            $service = app(CustomerApiKeyService::class);
            if (app(ApiCatalog::class)->scopes($customer) === []) {
                $this->addError('keyName', __('API access is not available on your current plan.'));
                return null;
            }
            if ($service->activeKeysCount($customer) >= (int) config('customer_api.max_keys', 5)) {
                $this->addError('keyName', __('You have reached the maximum number of active API keys.'));
                return null;
            }
            return $service->issueV2($customer, $this->keyName);
        }, 3);
        if (! $issued) return;
        $this->keyName = '';
        unset($this->keys);
        // The secret is delivered once as a client event; never a public Livewire property.
        $this->dispatch('api-key-created', secret: $issued['plain_text_key']);
    }

    public function revokeKey(int $id): void
    {
        $key = CustomerApiKey::where('customer_id', auth('app')->id())->findOrFail($id);
        app(CustomerApiKeyService::class)->revoke($key);
        unset($this->keys);
        $this->dispatch('alert', type: 'success', message: __('API key revoked.'));
    }
};
?>
<div class="v2-api" x-data="{ section: 'overview', language: 'cURL', secret: '', copied: false, async copy(value) { try { await navigator.clipboard.writeText(value); this.copied = true; setTimeout(() => this.copied = false, 2000); } catch { $dispatch('alert', {type: 'warning', message: @js(__('Copy failed. Select and copy the text manually.'))}); } } }"
     @api-key-created.window="secret = $event.detail.secret" x-on:livewire:navigating.window="secret = ''">
    @php
        $documentation = app(ApiDocumentation::class);
        $services = $documentation->services();
        $sections = ['overview' => __('Overview'), 'authentication' => __('Authentication'), 'keys' => __('API Keys'), 'quickstart' => __('Quickstart')];
        foreach ($services as $id => $service) $sections[$id] = $service['name'];
        $sections += ['jobs' => __('Jobs'), 'errors' => __('Errors'), 'idempotency' => __('Idempotency'), 'limits' => __('Limits'), 'retention' => __('Result retention')];
    @endphp
    <header class="api-header"><div><span class="api-eyebrow">{{ __('Developer portal') }}</span><h1>MetKurd API <small>V2</small></h1><p>{{ __('Build with speech, text and audio services through one asynchronous API.') }}</p></div><div class="api-header-actions"><button class="btn btn-primary" @click="section = 'keys'">{{ __('API Keys') }}</button><button class="btn btn-outline-secondary" @click="section = 'limits'">{{ __('Usage and limits') }}</button></div></header>
    @if(!config('customer_api.v2_enabled'))<div class="alert alert-info">{{ __('API V2 is not enabled in this environment yet.') }}</div>@endif
    <div class="api-mobile-nav"><label for="api-section">{{ __('Documentation') }}</label><select id="api-section" class="form-select" x-model="section">@foreach($sections as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></div>
    <div class="api-grid">
        <nav class="api-nav" aria-label="{{ __('Documentation') }}">@foreach($sections as $id => $label)<button @click="section = @js($id)" :class="{'is-active': section === @js($id)}" :aria-current="section === @js($id) ? 'page' : null">{{ $label }}</button>@endforeach</nav>
        <main class="api-main">
            <section x-show="section === 'overview'"><h2>{{ __('Overview') }}</h2><p>{{ __('Build with speech, text and audio services through one asynchronous API.') }}</p><div class="api-service-grid">@foreach($services as $id => $service)<button class="api-service" @click="section = @js($id)"><strong>{{ $service['name'] }}</strong><span>{{ __($service['description']) }}</span></button>@endforeach</div><p class="mt-4">{{ __('Submit a request, keep the job ID, then check its status. Processing continues without an open connection.') }}</p><code dir="ltr">{{ url('/api/v2') }}</code></section>
            <section x-cloak x-show="section === 'authentication'"><h2>{{ __('Authentication') }}</h2><p>{{ __('Send your API key in the Authorization header on every request, including file downloads.') }}</p><pre dir="ltr">Authorization: Bearer YOUR_API_KEY</pre><p>{{ __('Keep keys on your server. Never embed them in public browser code or mobile applications.') }}</p><p>{{ __('Keys use your current plan permissions. Revoking a key immediately prevents new requests with it.') }}</p></section>
            <section x-cloak x-show="section === 'keys'"><h2>{{ __('API Keys') }}</h2><p>{{ __('The full secret is shown only once. Copy it now and store it securely.') }}</p>
                <form wire:submit="createKey" class="api-key-form"><label for="api-key-name">{{ __('Key name') }}</label><input id="api-key-name" dir="auto" class="form-control" wire:model="keyName" maxlength="100" autocomplete="off"><button class="btn btn-primary" wire:loading.attr="disabled" wire:target="createKey">{{ __('Create API key') }}</button>@error('keyName')<span class="text-danger">{{ $message }}</span>@enderror</form>
                <div class="api-new-key" x-cloak x-show="secret"><p>{{ __('The full secret is shown only once. Copy it now and store it securely.') }}</p><code dir="ltr" x-text="secret"></code><div><button class="btn btn-sm btn-primary" @click="copy(secret)">{{ __('Copy') }}</button><button class="btn btn-sm btn-outline-secondary" @click="secret = ''">{{ __('Dismiss secret') }}</button></div></div>
                <div class="api-table"><table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Prefix') }}</th><th>{{ __('Created') }}</th><th>{{ __('Last used') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>@forelse($this->keys as $key)<tr wire:key="api-key-{{ $key->id }}"><td dir="auto">{{ $key->name }}</td><td><code dir="ltr">{{ $key->key_prefix }}…</code></td><td>{{ $key->created_at->format('Y-m-d') }}</td><td>{{ $key->last_used_at?->diffForHumans() ?? __('Never') }}</td><td>{{ $key->status === 'active' ? __('Active') : __('Revoked') }}</td><td>@if($key->status === 'active')<button class="btn btn-sm btn-outline-danger" data-v2-confirm="{{ __('Revoke this API key? Applications using it will lose access immediately.') }}" wire:click="revokeKey({{ $key->id }})" @click="secret = ''">{{ __('Revoke') }}</button>@endif</td></tr>@empty<tr><td colspan="6">{{ __('No API keys yet. Create one to get started.') }}</td></tr>@endforelse</tbody></table></div>
            </section>
            <section x-cloak x-show="section === 'quickstart'"><h2>{{ __('Quickstart') }}</h2><ol><li>{{ __('Create and securely save an API key.') }}</li><li>{{ __('Fetch available voices and replace VOICE_ID in the Apollo example.') }} <code dir="ltr">GET /api/v2/voices</code></li><li>{{ __('Choose a new Idempotency-Key for each new request. Keep the same key when retrying that request.') }}</li><li>{{ __('Send the request, then use its ID to check the shared jobs endpoint.') }}</li></ol><button class="btn btn-primary" @click="section = 'apollo'">{{ __('Open Apollo example') }}</button></section>
            @foreach($services as $id => $service)
            <section x-cloak x-show="section === @js($id)"><h2>{{ $service['name'] }}</h2><p>{{ __($service['description']) }}</p><div class="api-endpoint" dir="ltr"><b>POST</b> /api/v2/{{ $service['endpoint'] }}</div><p>{{ $service['file'] ? __('Upload one file using multipart/form-data. Do not set the multipart boundary manually.') : __('Send a JSON request with Content-Type: application/json.') }}</p>
                <div class="api-table"><table><thead><tr><th>{{ __('Field') }}</th><th>{{ __('Example') }}</th></tr></thead><tbody>@foreach($service['data'] as $field => $value)<tr><td><code dir="ltr">{{ $field }}</code></td><td><code dir="ltr">{{ $value }}</code></td></tr>@endforeach @if($service['file'])<tr><td><code dir="ltr">file</code></td><td>{{ __('Required upload') }}</td></tr>@endif</tbody></table></div>
                @if(in_array($id, ['apollo', 'vector']))<p>{{ __('Supported models: 1.5 and 2.0. The default is 2.0. Text is limited to 400 characters.') }}</p>@endif
                @if($id === 'vector')<p>{{ __('Upload a reference up to 20 MiB, or use reference_id for an active saved reference you own. Use exactly one. Processing uses up to the first 20 seconds.') }}</p><p>{{ __('Optional reference_text describes the reference audio and accepts up to 4000 characters.') }}</p>@endif
                @if($id === 'ocr')<p>{{ __('PDF, JPEG, PNG, WebP, BMP, GIF and TIFF are supported. Use all or a range such as 1-3,5. The server counts PDF pages. Export choices are txt, docx, markdown, html and zip.') }}</p>@endif
                <p>{{ __('Supported language values are ckb, ar and en; the default is ckb. Intelligent processing defaults to false and applies to Leo, Caption and OCR.') }}</p><p>{{ __('All submissions require Idempotency-Key. Optional storage_mode accepts temporary or permanent; temporary is the default.') }}</p><p>{{ __('A new submission returns HTTP 202. Repeating the same request returns HTTP 200 with the same job ID.') }}</p>
            </section>
            @endforeach
            <section x-cloak x-show="section === 'jobs'"><h2>{{ __('Jobs') }}</h2><p>{{ __('Submit a request, keep the job ID, then check its status. Processing continues without an open connection.') }}</p><div class="api-endpoint" dir="ltr">GET /api/v2/jobs/{id}</div><p>{{ __('Poll about every 10 seconds while queued or processing. Stop when completed, failed or cancelled. Respect Retry-After on HTTP 429.') }}</p><p>{{ __('Completed responses include private download links in result.files. Authenticate each download. Transcript, subtitle and OCR text appear when available.') }}</p><p>{{ __('File expiry does not remove the job record. An expired result returns expired: true and an empty files list.') }}</p></section>
            <section x-cloak x-show="section === 'errors'"><h2>{{ __('Errors') }}</h2><p>{{ __('Errors use a stable code and a safe message. Validation errors may include field names. Never retry a paid request with a new key just because a response was lost.') }}</p><div class="api-table"><table><thead><tr><th>{{ __('Code') }}</th><th>HTTP</th></tr></thead><tbody>@foreach(['invalid_request' => '400 / 422', 'authentication_failed' => '401', 'permission_denied' => '403', 'job_not_found' => '404', 'file_not_found' => '404', 'idempotency_conflict' => '409', 'invalid_file' => '422', 'unsupported_format' => '422', 'insufficient_credits' => '422', 'rate_limit_exceeded' => '429', 'concurrency_limit_exceeded' => '429', 'server_error' => '500'] as $code => $status)<tr><td><code dir="ltr">{{ $code }}</code></td><td>{{ $status }}</td></tr>@endforeach</tbody></table></div><p>{{ __('A failed job reports processing_failed in its job response.') }} <code dir="ltr">storage_limit_exceeded</code>: {{ __('Storage quota exceeded.') }}</p></section>
            <section x-cloak x-show="section === 'idempotency'"><h2>{{ __('Idempotency') }}</h2><p>{{ __('Choose a new Idempotency-Key for each new request. Keep the same key when retrying that request.') }}</p><p>{{ __('Keys are scoped to your account, accept 1 to 128 characters, and remain associated with the job. The same key and payload return the same job. A changed payload returns HTTP 409.') }}</p><p>{{ __('If a submission outcome is uncertain, the job remains processing for reconciliation. Do not create another paid request; contact support if it remains unresolved.') }}</p></section>
            <section x-cloak x-show="section === 'limits'"><h2>{{ __('Usage and limits') }}</h2><dl><dt>{{ __('Requests per minute') }}</dt><dd>{{ $this->access['requests_per_minute'] }}</dd><dt>{{ __('Concurrent jobs') }}</dt><dd>{{ $this->access['concurrent_jobs'] }}</dd><dt>{{ __('Active API keys') }}</dt><dd>{{ config('customer_api.max_keys', 5) }}</dd><dt>{{ __('API credit balance') }}</dt><dd>{{ number_format((int) CreditWallet::where('customer_id', auth('app')->id())->where('wallet_type', 'api')->value('balance_credits')) }}</dd></dl><p>{{ __('Submission, status and download requests share your plan rate limit. API requests use your API credits separately from web credits.') }}</p><p>{{ __('Audio and documents accept up to 100 MiB; reference audio accepts up to 20 MiB. Infrastructure upload limits may be lower. Audio minutes and PDF pages are measured on the server.') }}</p><p>{{ __('PDFs accept up to 3888 pages. Supported audio formats are WAV, MP3, M4A, AAC, OGG, WebM and FLAC; Vector excludes FLAC.') }}</p></section>
            <section x-cloak x-show="section === 'retention'"><h2>{{ __('Result retention') }}</h2><p>{{ __('Temporary API files expire :days days after submission and do not count toward permanent storage quota.', ['days' => config('customer_api.temporary_file_ttl_days', 7)]) }}</p><p>{{ __('Permanent API files count toward your customer storage quota and remain until deleted. Web storage behavior is unchanged. Download important temporary results before expires_at.') }}</p><p>{{ __('Expiry stops API access immediately; physical cleanup follows the configured cleanup schedule.') }}</p></section>
        </main>
        <aside class="api-code-panel"><div class="api-code-heading"><strong>{{ __('Code example') }}</strong><span role="status" x-show="copied" x-cloak>{{ __('Copied') }}</span></div>
            @foreach($services as $id => $service)<div x-cloak x-show="section === @js($id)"><p class="api-code-note">{{ __('Examples use cURL, PHP cURL, Python requests or Node.js 20+. Replace placeholders before running.') }}</p><label class="visually-hidden" for="api-code-{{ $id }}">{{ __('Example language') }}</label><select id="api-code-{{ $id }}" class="form-select mb-3" x-model="language"><option>cURL</option><option>PHP</option><option>Python</option><option>JavaScript</option></select>@foreach($documentation->examples($service) as $language => $example)<div x-show="language === @js($language)"><button class="api-copy" @click="copy($refs['code-{{ $id }}-{{ $loop->index }}'].textContent)">{{ __('Copy') }}</button><pre dir="ltr"><code x-ref="code-{{ $id }}-{{ $loop->index }}">{{ $example }}</code></pre></div>@endforeach</div>@endforeach
            <div x-show="!{{ \Illuminate\Support\Js::from(array_keys($services)) }}.includes(section)"><p>{{ __('Check a job') }}</p><button class="api-copy" @click="copy($refs.jobExample.textContent)">{{ __('Copy') }}</button><pre dir="ltr"><code x-ref="jobExample">curl '{{ url('/api/v2/jobs/job_YOUR_JOB_ID') }}' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json'</code></pre><p>{{ __('Accepted response') }}</p><pre dir="ltr">{
  "id": "job_YOUR_JOB_ID",
  "status": "queued",
  "service": "speech",
  "created_at": "2026-09-06T12:00:00Z",
  "completed_at": null,
  "expires_at": "2026-09-13T12:00:00Z",
  "result": null
}</pre><p>{{ __('Error response') }}</p><pre dir="ltr">{
  "error": {
    "code": "invalid_request",
    "message": "Check the supplied fields.",
    "fields": ["text"]
  }
}</pre></div>
        </aside>
    </div>
    <style>
        .v2-api{--api-border:rgba(128,140,160,.2);padding:24px;max-width:1800px;margin:auto}.api-header{display:flex;gap:24px;align-items:center;justify-content:space-between;margin-bottom:28px}.api-header h1{font-size:30px;font-weight:700;margin:6px 0 10px}.api-header h1 small{font-size:13px;padding:5px 8px;border:1px solid var(--api-border);border-radius:7px;vertical-align:middle}.api-eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--bs-secondary-color)}.api-header p{margin:0;max-width:620px;color:var(--bs-secondary-color)}.api-header-actions{display:flex;gap:10px;flex-shrink:0}.api-grid{display:grid;grid-template-columns:180px minmax(280px,1fr) minmax(280px,.8fr);border:1px solid var(--api-border);border-radius:16px;overflow:hidden;background:var(--bs-body-bg)}.api-nav{padding:16px 10px;border-inline-end:1px solid var(--api-border)}.api-nav button{display:block;width:100%;border:0;background:transparent;color:inherit;text-align:start;border-radius:8px;padding:9px 12px;font-size:13px}.api-nav button:hover,.api-nav button.is-active{background:rgba(105,108,255,.1);color:var(--bs-primary)}.api-main{padding:28px;min-width:0}.api-main h2{font-size:23px;font-weight:650;margin-bottom:18px}.api-main p,.api-main li{line-height:1.75;font-size:14px}.api-main li{margin-bottom:12px}.api-main pre{padding:16px;background:rgba(128,140,160,.08);border-radius:8px}.api-main code{display:inline-block;max-width:100%;overflow-wrap:anywhere}.api-endpoint{border:1px solid var(--api-border);border-radius:9px;padding:13px;margin:20px 0;font:13px monospace;overflow:auto;text-align:left}.api-endpoint b{color:#28a875;margin-inline-end:8px}.api-service-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.api-service{border:1px solid var(--api-border);border-radius:12px;padding:18px;background:transparent;color:inherit;text-align:start}.api-service strong,.api-service span{display:block}.api-service span{font-size:12px;color:var(--bs-secondary-color);margin-top:7px;line-height:1.6}.api-service:hover{border-color:var(--bs-primary)}.api-code-panel{background:#151c2b;color:#d6e2f1;padding:22px;min-width:0;border-inline-start:1px solid var(--api-border)}.api-code-heading{display:flex;justify-content:space-between;margin-bottom:20px}.api-code-note{font-size:12px;line-height:1.6;color:#aab9cd}.api-code-panel pre{font:12px/1.8 ui-monospace,monospace;text-align:left;overflow-x:auto;max-width:100%;padding:16px 0;color:#d6e2f1;white-space:pre}.api-code-panel code{color:inherit}.api-copy{border:1px solid #455269;background:transparent;color:#d6e2f1;border-radius:6px;padding:4px 12px;font-size:12px}.api-code-panel p{font-size:13px;margin-top:20px}.api-table{overflow:auto;margin:22px 0}.api-table table{width:100%;font-size:12px}.api-table th,.api-table td{padding:12px 8px;border-bottom:1px solid var(--api-border);text-align:start}.api-key-form{display:flex;flex-wrap:wrap;gap:10px}.api-key-form label,.api-key-form .text-danger{width:100%}.api-key-form input{flex:1;min-width:160px}.api-new-key{padding:16px;margin-top:18px;background:rgba(40,168,117,.08);border:1px solid var(--api-border);border-radius:10px}.api-new-key code{display:block;overflow-wrap:anywhere;margin-bottom:12px;text-align:left}.api-new-key button{margin-inline-end:8px}.api-mobile-nav{display:none}.api-main dt{font-size:13px;color:var(--bs-secondary-color)}.api-main dd{font-size:22px;margin-bottom:18px}@media(max-width:1200px){.api-grid{grid-template-columns:155px minmax(0,1fr)}.api-code-panel{grid-column:2;border-inline-start:0;border-top:1px solid var(--api-border)}.api-nav{grid-row:span 2}}@media(max-width:760px){.v2-api{padding:14px}.api-header{align-items:flex-start;flex-direction:column;gap:16px}.api-header h1{font-size:26px}.api-grid{display:block}.api-nav{display:none}.api-main,.api-code-panel{padding:20px}.api-mobile-nav{display:block;margin-bottom:15px}.api-mobile-nav label{font-size:12px;margin-bottom:6px}.api-service-grid{grid-template-columns:1fr}.api-header-actions{flex-wrap:wrap}}
    </style>
</div>
