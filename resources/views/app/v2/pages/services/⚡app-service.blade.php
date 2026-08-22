<?php

use App\Support\MetKurdV2ToolCatalog;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::v2.layouts.app')] class extends Component {
    public string $serviceSlug;
    public array $serviceDefinition = [];

    public function mount(string $service, MetKurdV2ToolCatalog $catalog): void
    {
        abort_unless($catalog->isKnownService($service), 404);
        $this->serviceSlug = $service;
        $this->serviceDefinition = $catalog->service($service);
    }
};
?>

<section>
    <nav class="v2-breadcrumb small mb-4" aria-label="breadcrumb"><a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}">{{ __('MetKurd AI') }}</a> <span class="mx-2 text-secondary">/</span> {{ __($serviceDefinition['name']) }}</nav>
    <div class="text-center mb-5"><img class="v2-service-icon mb-3" src="{{ asset($serviceDefinition['icon_asset']) }}" alt=""><h1 class="display-6 fw-semibold mb-2">{{ __($serviceDefinition['name']) }}</h1><p class="v2-muted mb-0">{{ __('Select a workspace to continue.') }}</p></div>
    <div class="v2-tool-list d-grid gap-3">
        @foreach ($serviceDefinition['tools'] as $toolSlug => $tool)
            @include('app.v2.components.shared.tool-card', ['service' => $serviceDefinition, 'serviceSlug' => $serviceSlug, 'tool' => $tool, 'toolSlug' => $toolSlug, 'loopIndex' => $loop->iteration])
        @endforeach
    </div>
</section>
