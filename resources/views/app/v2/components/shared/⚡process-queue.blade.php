<?php

use App\Support\CustomerProcessQueue;
use App\Services\Plans\PlanConcurrencyService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new class extends Component {
    #[Locked] public array $initial = [];
    #[Locked] public int $allowedSlots = 1;

    public function mount(): void
    {
        $this->initial = $this->refreshQueue();
        $this->allowedSlots = app(PlanConcurrencyService::class)->allowedConcurrentJobsForCustomer(auth('app')->user());
    }

    #[Renderless]
    public function refreshQueue(): array
    {
        abort_unless(config('metkurd_v2.enabled') && auth('app')->user()?->status == 1, 403);
        return app(CustomerProcessQueue::class)->read(auth('app')->user());
    }

    #[Renderless]
    public function refreshQueueLimit(): int
    {
        abort_unless(auth('app')->user()?->status == 1, 403);
        return app(PlanConcurrencyService::class)->allowedConcurrentJobsForCustomer(auth('app')->user());
    }
};
?>
<div class="v2-process-queue dropdown" data-process-queue data-customer="{{ auth('app')->id() }}" data-snapshot="{{ json_encode($initial) }}" wire:ignore>
    <button type="button" class="btn btn-sm v2-topbar-control v2-queue-toggle" data-queue-toggle data-state="idle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="{{ __('process_queue.title') }}">
        <i class="mdi mdi-format-list-checks" aria-hidden="true"></i>
        <span class="d-none d-md-inline ms-1">{{ __('process_queue.title') }}</span>
        <span class="ms-1" data-queue-indicator role="status">{{ __('process_queue.idle') }}</span>
        <span class="badge bg-light text-dark ms-1" data-queue-new hidden></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end v2-queue-panel p-3">
        <h2 class="h6">{{ __('process_queue.title') }}</h2>
        <p class="small text-body-secondary">{{ __('process_queue.limit') }} <span data-queue-limit>{{ $allowedSlots }}</span></p>
        <p class="small" data-queue-empty>{{ __('process_queue.empty') }}</p>
        <div data-queue-list></div>
        <p class="small mt-2 mb-0" data-queue-more hidden>{{ __('process_queue.more') }}</p>
        <p class="small text-warning mt-2 mb-0" data-queue-error hidden>{{ __('process_queue.update_error') }}</p>
    </div>
    <template data-queue-row><a class="v2-queue-job" wire:navigate><span><strong data-queue-label></strong><small data-queue-time dir="ltr"></small></span><span><span data-queue-status></span><small>{{ __('process_queue.open') }}</small></span></a></template>
    <span hidden data-queue-copy data-idle="{{ __('process_queue.idle') }}" data-active="{{ __('process_queue.processing') }}" data-ready="{{ __('process_queue.ready') }}" data-failed="{{ __('process_queue.failed') }}" data-new="{{ __('process_queue.new') }}"></span>
</div>
