<?php

use App\Support\MetKurdV2ToolCatalog;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::v2.layouts.app')] class extends Component {
    public array $services = [];

    public function mount(MetKurdV2ToolCatalog $catalog): void
    {
        $this->services = $catalog->services();
    }
};
?>

<section>
    <div class="v2-hero">
        <img class="v2-hero-logo" src="{{ asset('app/logo/white_logo.svg') }}" alt="{{ __('MetKurd AI') }}">
        <p class="text-primary text-uppercase fw-semibold small mb-2">{{ __('AI workspace') }}</p>
        <h1 class="display-5 fw-semibold mb-3">{{ __('Create with MetKurd AI') }}</h1>
        <p class="v2-muted mb-0">{{ __('Choose what you want to create. Each service keeps your jobs, credits, and storage in one familiar platform.') }}</p>
    </div>
    <div class="row g-4 v2-service-grid">
        @foreach ($services as $slug => $service)
            <div class="col-md-6 {{ $loop->iteration <= 2 ? 'col-xl-6' : 'col-xl-4' }}">
                @include('app.v2.components.service-card', ['service' => $service, 'slug' => $slug])
            </div>
        @endforeach
    </div>
</section>
