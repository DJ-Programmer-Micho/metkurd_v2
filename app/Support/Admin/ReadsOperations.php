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
    public string $financialEra = 'current';

    #[Url]
    public string $billingState = '';

    #[Url]
    public string $billingCase = '';

    #[Url]
    public string $billingRecord = '';

    #[Url]
    public string $subscriptionAccess = '';

    #[Url]
    public string $customerFilter = '';

    public string $customerSearch = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $group = '';

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

    #[Url]
    public string $apiJob = '';

    #[Url]
    public string $keyId = '';

    #[Url]
    public string $connectionId = '';

    public function isDeveloper(): bool
    {
        return in_array($this->section, AdminDeveloperWorkspace::SECTIONS, true) || ($this->section === 'review' && $this->queue !== 'payment_review');
    }

    #[Computed]
    public function developerSummary(): array
    {
        return app(AdminDeveloperWorkspace::class)->summary(['customer' => $this->customer ?: (int) $this->customerFilter, 'from' => $this->from, 'until' => $this->until]);
    }

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
        $this->reset('search', 'status', 'group', 'channel', 'service', 'failure', 'from', 'until', 'direction', 'method', 'job', 'payment', 'queue', 'billingState', 'billingCase', 'billingRecord', 'subscriptionAccess');
        $this->resetPage();
        $this->reset('apiJob', 'keyId', 'connectionId');
        $this->resetPage('eventsPage');
        $this->resetPage('allocationsPage');
        unset($this->rows, $this->context, $this->jobSummary, $this->billingEvidence, $this->developerSummary);
    }

    public function updated($property): void
    {
        if (str_starts_with($property, 'paginators.')) {
            return;
        }
        $this->resetPage();
        $this->resetPage('eventsPage');
        $this->resetPage('allocationsPage');
        if ($property === 'section') {
            $this->search = $this->status = $this->channel = $this->service = $this->failure = $this->direction = $this->method = '';
            $this->billingState = $this->billingCase = $this->billingRecord = $this->subscriptionAccess = '';
        }
        unset($this->rows, $this->overview, $this->context, $this->customerOptions, $this->jobSummary, $this->billingEvidence, $this->developerSummary);
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
    public function customerWorkspace(): array
    {
        $id = $this->customer ?: (int) $this->customerFilter;

        return $id ? app(AdminCustomerWorkspace::class)->read($id) : [];
    }

    #[Computed]
    public function jobSummary(): array
    {
        $filters = [
            'customer' => $this->customer ?: (int) $this->customerFilter,
            'from' => $this->from, 'until' => $this->until, 'service' => $this->service,
            'channel' => $this->channel, 'search' => $this->search, 'failure' => $this->failure,
        ];
        $counts = [];
        foreach (AdminOperations::JOB_GROUPS as $group) {
            $counts[$group] = app(AdminDeveloperWorkspace::class)->query('jobs', $filters + ['group' => $group])->count();
        }

        return $counts;
    }

    #[Computed]
    public function rows()
    {
        $reader = app(AdminOperations::class);
        $f = ['customer' => $this->customer ?: (int) $this->customerFilter];
        foreach (['financialEra', 'search', 'status', 'channel', 'service', 'failure', 'from', 'until', 'direction', 'method', 'job', 'payment', 'billingState', 'billingCase', 'billingRecord', 'subscriptionAccess', 'apiJob', 'keyId', 'connectionId'] as $key) {
            $f[$key] = $this->$key;
        }
        $f['queue'] = $this->section === 'review' ? $this->queue : '';
        $f['group'] = $this->section === 'jobs' ? $this->group : '';

        if ($this->isDeveloper()) {
            $workspace = app(AdminDeveloperWorkspace::class);
            $section = $this->section === 'review' ? ($this->queue === 'reservation_review' ? 'reservations' : 'jobs') : $this->section;
            $page = $workspace->query($section, $f)->paginate(AdminOperations::PAGE_SIZE);

            return $page->setCollection($workspace->rows($page->getCollection()));
        }
        if ($this->section === 'audit' && ($this->apiJob || $this->keyId || $this->connectionId)) {
            return app(AdminDeveloperWorkspace::class)->query('audit', $f)->paginate(AdminOperations::PAGE_SIZE)->through(fn ($row) => $reader->row($row));
        }

        $page = $reader->query($this->section, $f)->paginate(AdminOperations::PAGE_SIZE);
        if ($this->isBilling()) {
            return $page->setCollection(app(AdminBillingWorkspace::class)->rows($page->getCollection()));
        }

        return $page->through(fn ($row) => $reader->row($row));
    }

    public function isBilling(): bool
    {
        return in_array($this->section, AdminBillingWorkspace::SECTIONS, true) || ($this->section === 'review' && $this->queue === 'payment_review');
    }

    #[Computed]
    public function billingEvidence(): array
    {
        $section = $this->payment !== '' ? 'payments' : $this->section;
        if (($this->payment === '' && $this->billingRecord === '') || ! in_array($section, ['payments', 'subscriptions', 'storage_subscriptions'], true)) {
            return [];
        }
        $model = app(AdminOperations::class)->query($section, ['customer' => $this->customer ?: (int) $this->customerFilter,
            'financialEra' => $this->financialEra, 'payment' => $this->payment,
            'billingRecord' => $this->payment === '' ? $this->billingRecord : ''])->firstOrFail();

        return app(AdminBillingWorkspace::class)->evidence($model);
    }

    #[Computed]
    public function context(): array
    {
        $reader = app(AdminOperations::class);
        $type = $this->job !== '' ? 'jobs' : ($this->payment !== '' ? 'payments' : null);
        if (! $type) {
            return [];
        }
        $query = $reader->query($type, ['customer' => $this->customer ?: (int) $this->customerFilter, 'job' => $this->job, 'payment' => $this->payment, 'financialEra' => $this->financialEra]);
        if ($type === 'jobs') {
            $query->addSelect('updated_at');
        }
        $row = $query->firstOrFail();

        return $type === 'jobs' ? app(AdminDeveloperWorkspace::class)->rows(collect([$row]))->first() : $reader->row($row);
    }
}
