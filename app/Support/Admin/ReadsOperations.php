<?php

namespace App\Support\Admin;

use App\Services\Admin\AdminOperations;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

trait ReadsOperations
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Locked]
    public ?int $customer = null;

    #[Url]
    public string $section = 'jobs';

    #[Url]
    public string $customerFilter = '';

    public string $customerSearch = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $channel = '';

    #[Url]
    public string $service = '';

    #[Url]
    public string $failure = '';

    #[Url]
    public string $queue = 'provider_submission_unknown';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $direction = '';

    #[Url]
    public string $method = '';

    #[Url]
    public string $job = '';

    #[Url]
    public string $payment = '';

    public function bootReadsOperations(): void
    {
        AdminAccess::authorize('admin.read');
    }

    public function mount(?int $customer = null): void
    {
        $this->customer = $customer;
        if ($customer) {
            \App\Models\Customer::findOrFail($customer);
        }
    }

    public function resetFilters(): void
    {
        // Keep the selected customer and section when clearing a scoped view.
        $this->reset('search', 'status', 'channel', 'service', 'failure', 'from', 'until', 'direction', 'method', 'job', 'payment', 'queue');
        $this->resetPage();
        unset($this->rows, $this->context);
    }

    public function updated($property): void
    {
        $this->resetPage();
        if ($property === 'section') {
            $this->search = $this->status = $this->channel = $this->service = $this->failure = $this->direction = $this->method = '';
        }
        unset($this->rows, $this->overview, $this->context, $this->customerOptions);
    }

    #[Computed]
    public function customerOptions()
    {
        return app(AdminOperations::class)->customerLookup($this->customerSearch, (int) $this->customerFilter ?: null);
    }

    #[Computed]
    public function overview(): array
    {
        $id = $this->customer ?: (int) $this->customerFilter;

        return $id ? app(AdminOperations::class)->customer($id) : [];
    }

    #[Computed]
    public function rows()
    {
        $reader = app(AdminOperations::class);
        $f = ['customer' => $this->customer ?: (int) $this->customerFilter];
        foreach (['search', 'status', 'channel', 'service', 'failure', 'from', 'until', 'direction', 'method', 'job', 'payment'] as $key) {
            $f[$key] = $this->$key;
        }
        $f['queue'] = $this->section === 'review' ? $this->queue : '';

        return $reader->query($this->section, $f)->paginate(AdminOperations::PAGE_SIZE)->through(fn ($row) => $reader->row($row));
    }

    #[Computed]
    public function context(): array
    {
        $reader = app(AdminOperations::class);
        $type = $this->job !== '' ? 'jobs' : ($this->payment !== '' ? 'payments' : null);
        if (! $type) {
            return [];
        }
        $row = $reader->query($type, ['customer' => $this->customer ?: (int) $this->customerFilter, 'job' => $this->job, 'payment' => $this->payment])->firstOrFail();

        return $reader->row($row);
    }
}
