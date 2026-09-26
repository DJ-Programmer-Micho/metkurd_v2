<?php

use App\Models\CustomerMcpConnection;
use App\Services\Mcp\CustomerMcpAccessService;
use App\Services\Mcp\OAuth\Connections;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::v2.layouts.app')] class extends Component {
    #[Computed]
    public function eligible(): bool { return app(CustomerMcpAccessService::class)->eligible(auth('app')->user()); }

    #[Computed]
    public function configured(): bool { return app(CustomerMcpAccessService::class)->scopes(auth('app')->user()) !== []; }

    #[Computed]
    public function connections() {
        if (! \Illuminate\Support\Facades\Schema::hasTable('customer_mcp_connections')) return collect();
        return CustomerMcpConnection::where('customer_id', auth('app')->id())->latest()->limit(50)->get();
    }

    public function revoke(string $id): void {
        app(Connections::class)->revoke(auth('app')->user(), $id);
        unset($this->connections);
        $this->dispatch('alert', type: 'success', message: __('mcp.revoked'));
    }
};
?>
<div class="v2-api v2-mcp" x-data="{ section: 'overview' }">
    @include('app.v2.mcp.styles')
    @php($sections = ['overview', 'connect', 'connections', 'clients', 'tools', 'voices', 'uploads', 'jobs', 'credits_title', 'examples', 'security', 'troubleshooting'])
    <header class="api-header"><div><span class="api-eyebrow">{{ __('Developer portal') }}</span><h1>MetKurd MCP</h1><p>{{ __('mcp.intro') }}</p></div>
        @if($this->eligible && $this->configured && config('mcp.enabled'))<button class="btn btn-primary" @click="section = 'connect'">{{ __('mcp.connect') }}</button>@endif
    </header>
    @if(!config('mcp.enabled'))<div class="alert alert-info">{{ __('mcp.disabled') }}</div>@endif
    @if($this->eligible && !$this->configured)<div class="alert alert-warning">{{ __('mcp.configuration_unavailable') }}</div>@endif
    @if(!$this->eligible)<div class="alert alert-info"><p>{{ __('mcp.paid_only') }}</p><a wire:navigate class="btn btn-outline-primary" href="{{ route('app.v2.subscription-plans', ['locale' => app()->getLocale()]) }}">{{ __('mcp.plans') }}</a></div>@endif
    <div class="api-mobile-nav"><label for="mcp-section">{{ __('Documentation') }}</label><select id="mcp-section" class="form-select" x-model="section">@foreach($sections as $section)<option value="{{ $section }}">{{ __('mcp.'.$section) }}</option>@endforeach</select></div>
    <div class="api-grid">
        <nav class="api-nav" aria-label="{{ __('Documentation') }}">@foreach($sections as $section)<button @click="section = @js($section)" :class="{'is-active': section === @js($section)}" :aria-current="section === @js($section) ? 'page' : null">{{ __('mcp.'.$section) }}</button>@endforeach</nav>
        <main class="api-main">
            <section x-show="section === 'overview'"><h2>{{ __('mcp.overview') }}</h2><p>{{ __('mcp.intro') }}</p><p>{{ __('mcp.credits') }}</p><p>{{ __('mcp.async') }}</p></section>
            <section x-show="section === 'connect'" x-cloak>
                <h2>{{ __('mcp.choose_client') }}</h2><p>{{ __('mcp.simple_steps') }}</p>
                <p>{{ __('mcp.plan_tools') }}</p>
                <div class="row g-3">
                    @foreach(['chatgpt' => 'ChatGPT', 'claude' => 'Claude / Claude Desktop', 'claude_code' => 'Claude Code', 'codex' => 'Codex', 'generic_help' => __('mcp.generic')] as $key => $label)
                        <div class="col-12 col-lg-6"><article class="api-card h-100"><h3>{{ $label }}</h3><p>{{ __('mcp.'.$key) }}</p></article></div>
                    @endforeach
                </div>
                <p class="mt-3">{{ __('mcp.connect_help') }}</p><code dir="ltr">{{ config('mcp.public_url') }}</code>
                <details class="mt-3"><summary>{{ __('mcp.advanced') }}</summary><dl><dt>{{ __('mcp.transport') }}</dt><dd>Streamable HTTP</dd><dt>{{ __('Authentication') }}</dt><dd>OAuth / PKCE S256</dd></dl><p>{{ __('mcp.preregister') }}</p><button class="btn btn-outline-primary" @click="section = 'clients'">{{ __('mcp.official_docs') }}</button></details>
            </section>
            <section x-show="section === 'connections'" x-cloak><h2>{{ __('mcp.connections') }}</h2><div class="api-table"><table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Created') }}</th><th>{{ __('Last used') }}</th><th>{{ __('mcp.permissions') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
                @forelse($this->connections as $connection)<tr wire:key="mcp-{{ $connection->id }}"><td dir="auto">{{ $connection->name }}</td><td>{{ $connection->created_at->format('Y-m-d') }}</td><td>{{ $connection->last_used_at?->diffForHumans() ?? __('Never') }}</td><td><code dir="ltr">{{ implode(', ', $connection->scopes) }}</code></td><td>{{ $connection->revoked_at ? __('Revoked') : __('Active') }}</td><td>@if(!$connection->revoked_at)<button class="btn btn-sm btn-outline-danger" data-v2-confirm="{{ __('mcp.revoke_confirm') }}" wire:click="revoke('{{ $connection->id }}')">{{ __('Revoke') }}</button>@endif</td></tr>@empty<tr><td colspan="6">{{ __('mcp.no_connections') }}</td></tr>@endforelse
            </tbody></table></div></section>
            <section x-show="section === 'clients'" x-cloak><h2>{{ __('mcp.clients') }}</h2><p>{{ __('mcp.compatibility') }}</p>
                <h3>ChatGPT</h3><p>{{ __('mcp.chatgpt') }}</p><a href="https://developers.openai.com/plugins/deploy/connect-chatgpt" target="_blank" rel="noopener noreferrer">{{ __('mcp.official_docs') }}</a>
                <h3>Claude / Claude Desktop</h3><p>{{ __('mcp.claude') }}</p><a href="https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp" target="_blank" rel="noopener noreferrer">{{ __('mcp.official_docs') }}</a>
                <h3>Claude Code</h3><p>{{ __('mcp.claude_code') }}</p><pre dir="ltr"><code>claude mcp add --transport http metkurd {{ config('mcp.public_url') }}
/mcp</code></pre><a href="https://code.claude.com/docs/en/mcp" target="_blank" rel="noopener noreferrer">{{ __('mcp.official_docs') }}</a>
                <h3>Codex</h3><p>{{ __('mcp.codex') }}</p><pre dir="ltr"><code>codex mcp add metkurd --url {{ config('mcp.public_url') }}
codex mcp login metkurd</code></pre><a href="https://developers.openai.com/codex/mcp" target="_blank" rel="noopener noreferrer">{{ __('mcp.official_docs') }}</a>
                <h3>{{ __('mcp.generic') }}</h3><p>{{ __('mcp.generic_help') }}</p>
            </section>
            <section x-show="section === 'tools'" x-cloak><h2>{{ __('mcp.tools') }}</h2><p>{{ __('mcp.limits') }}</p>
                @foreach(app(\App\Services\Mcp\ToolCatalog::class)->definitions() as $key => $tool)<article class="api-card mb-4"><h3><code dir="ltr">{{ $tool['name'] }}</code></h3><p>{{ __('mcp.tool_'.$key) }}</p><p>{{ __('mcp.charge') }}: {{ $tool['paid'] ? __('mcp.yes') : __('mcp.no') }} · <code dir="ltr">{{ $tool['scope'] }}</code></p><h4>{{ __('mcp.arguments') }}</h4><pre dir="ltr"><code>{{ json_encode($tool['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre><p>{{ $tool['paid'] ? __('mcp.job_result') : __('mcp.read_result') }}</p><blockquote dir="auto">{{ __('mcp.example_'.$key) }}</blockquote></article>@endforeach
            </section>
            <section x-show="section === 'voices'" x-cloak><h2>{{ __('mcp.voices') }}</h2><p>{{ __('mcp.voice_help') }}</p><a wire:navigate href="{{ route('app.v2.api', ['locale' => app()->getLocale()]) }}#available-voices">{{ __('api_v2.available_voices') }}</a></section>
            <section x-show="section === 'uploads'" x-cloak><h2>{{ __('mcp.uploads') }}</h2><p>{{ __('mcp.upload_help') }}</p><p>{{ __('mcp.upload_limits') }}</p></section>
            <section x-show="section === 'jobs'" x-cloak><h2>{{ __('mcp.jobs') }}</h2><p>{{ __('mcp.async') }}</p><p>{{ __('mcp.results') }}</p></section>
            <section x-show="section === 'credits_title'" x-cloak><h2>{{ __('mcp.credits_title') }}</h2><p>{{ __('mcp.credits') }}</p><p>{{ __('mcp.limits') }}</p><p>{{ __('mcp.retry') }}</p></section>
            <section x-show="section === 'examples'" x-cloak><h2>{{ __('mcp.examples') }}</h2><p>{{ __('mcp.host_commands') }}</p>@foreach(['speak', 'list_voices', 'clone_voice', 'zeta', 'transcribe', 'ocr', 'stem', 'harakat'] as $example)<blockquote dir="auto">{{ __('mcp.example_'.$example) }}</blockquote>@endforeach</section>
            <section x-show="section === 'security'" x-cloak><h2>{{ __('mcp.security') }}</h2><p>{{ __('mcp.security_help') }}</p></section>
            <section x-show="section === 'troubleshooting'" x-cloak><h2>{{ __('mcp.troubleshooting') }}</h2><p>{{ __('mcp.trouble_help') }}</p></section>
        </main>
    </div>
</div>
