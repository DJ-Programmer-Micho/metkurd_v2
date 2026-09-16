<?php

namespace App\Support\Admin;

use App\Models\LandingSocialLink;
use App\Support\Landing\LandingSettingsRepository;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesLandingContactSettingsPage
{
    use SecureAdminComponent;

    public string $supportEmail = '';

    public string $supportLinesText = '';

    public string $companyLinesText = '';

    #[Url(as: 'q', keep: true)]
    public string $socialSearch = '';

    #[Url(as: 'status', keep: true)]
    public string $socialStatusFilter = 'all';

    public ?int $editingSocialLinkId = null;

    public string $socialPlatform = '';

    public string $socialUrl = '';

    public string $socialIconClass = '';

    public int $socialSortOrder = 0;

    public string $socialStatus = 'active';

    public ?int $socialLinkIdPendingDelete = null;

    public string $socialLinkDeleteLabel = '';

    public function mount(): void
    {
        $this->reloadContactSettings();
    }

    public function updatingSocialSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSocialStatusFilter(): void
    {
        $this->resetPage();
    }

    public function resetSocialFilters(): void
    {
        $this->socialSearch = '';
        $this->socialStatusFilter = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function socialLinks()
    {
        $query = LandingSocialLink::query();
        $search = trim($this->socialSearch);

        if ($this->socialStatusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->socialStatusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('platform', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('icon_class', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('platform')
            ->paginate(12);
    }

    public function saveContactSettings(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $this->validate([
            'supportEmail' => ['nullable', 'email', 'max:190'],
            'supportLinesText' => ['nullable', 'string', 'max:4000'],
            'companyLinesText' => ['nullable', 'string', 'max:4000'],
        ]);

        $supportLines = $this->parseLines($this->supportLinesText);
        $companyLines = $this->parseLines($this->companyLinesText);

        if (trim($this->supportEmail) !== '' && ! in_array(trim($this->supportEmail), $supportLines, true)) {
            array_unshift($supportLines, trim($this->supportEmail));
        }

        $this->settingsRepository()->saveContactSettings([
            'support_email' => trim($this->supportEmail),
            'support_lines' => $supportLines,
            'company_lines' => $companyLines,
        ]);

        $this->reloadContactSettings();
        $this->dispatch('alert', type: 'success', message: __('Landing contact/company settings saved successfully.'));
    }

    public function openSocialCreateModal(): void
    {
        $this->resetSocialForm();
        $this->dispatch('landing-contact:modal-show', id: 'landingSocialLinkModal');
    }

    public function openSocialEditModal(int $socialLinkId): void
    {
        $link = LandingSocialLink::query()->findOrFail($socialLinkId);

        $this->resetValidation();
        $this->editingSocialLinkId = $link->id;
        $this->socialPlatform = (string) $link->platform;
        $this->socialUrl = (string) $link->url;
        $this->socialIconClass = (string) ($link->icon_class ?: '');
        $this->socialSortOrder = (int) $link->sort_order;
        $this->socialStatus = $link->is_active ? 'active' : 'inactive';

        $this->dispatch('landing-contact:modal-show', id: 'landingSocialLinkModal');
    }

    public function saveSocialLink(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $this->validate([
            'socialPlatform' => ['required', 'string', 'min:2', 'max:80'],
            'socialUrl' => ['required', 'url', 'max:2048'],
            'socialIconClass' => ['nullable', 'string', 'max:120'],
            'socialSortOrder' => ['required', 'integer', 'min:0', 'max:65535'],
            'socialStatus' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $social = $this->editingSocialLinkId
            ? LandingSocialLink::query()->findOrFail($this->editingSocialLinkId)
            : new LandingSocialLink;

        $social->fill([
            'platform' => trim($this->socialPlatform),
            'url' => trim($this->socialUrl),
            'icon_class' => $this->emptyToNull($this->socialIconClass)
                ?: $this->settingsRepository()->defaultIconForPlatform(trim($this->socialPlatform)),
            'sort_order' => (int) $this->socialSortOrder,
            'is_active' => $this->socialStatus === 'active',
        ]);

        $social->save();

        $this->dispatch('alert', type: 'success', message: $this->editingSocialLinkId
            ? __('Social link updated successfully.')
            : __('Social link created successfully.'));

        $this->dispatch('landing-contact:modal-hide', id: 'landingSocialLinkModal');
        $this->resetSocialForm();
    }

    public function toggleSocialStatus(int $socialLinkId): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $social = LandingSocialLink::query()->findOrFail($socialLinkId);
        $social->update(['is_active' => ! $social->is_active]);

        $this->dispatch('alert', type: 'success', message: $social->is_active
            ? __('Social link is now active.')
            : __('Social link is now inactive.'));
    }

    public function confirmSocialDelete(int $socialLinkId): void
    {
        $social = LandingSocialLink::query()->findOrFail($socialLinkId);

        $this->socialLinkIdPendingDelete = $social->id;
        $this->socialLinkDeleteLabel = (string) $social->platform;
        $this->dispatch('landing-contact:modal-show', id: 'landingSocialDeleteModal');
    }

    public function performSocialDelete(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        if (! $this->socialLinkIdPendingDelete) {
            return;
        }

        LandingSocialLink::query()->findOrFail($this->socialLinkIdPendingDelete)->delete();

        $this->dispatch('alert', type: 'success', message: __('Social link deleted successfully.'));
        $this->dispatch('landing-contact:modal-hide', id: 'landingSocialDeleteModal');
        $this->resetSocialDeleteState();
    }

    public function resetSocialForm(): void
    {
        $this->resetValidation();
        $this->editingSocialLinkId = null;
        $this->socialPlatform = '';
        $this->socialUrl = '';
        $this->socialIconClass = '';
        $this->socialSortOrder = 0;
        $this->socialStatus = 'active';
    }

    public function resetSocialDeleteState(): void
    {
        $this->socialLinkIdPendingDelete = null;
        $this->socialLinkDeleteLabel = '';
    }

    protected function reloadContactSettings(): void
    {
        $repository = $this->settingsRepository();

        $this->supportEmail = $repository->contactSupportEmail();
        $this->supportLinesText = $this->implodeLines($repository->contactSupportLines());
        $this->companyLinesText = $this->implodeLines($repository->contactCompanyLines());
    }

    /**
     * @return string[]
     */
    protected function parseLines(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\\r\\n|\\r|\\n/', (string) $value) ?: []),
            fn (string $line) => $line !== ''
        ));
    }

    /**
     * @param  string[]  $lines
     */
    protected function implodeLines(array $lines): string
    {
        return implode(PHP_EOL, $lines);
    }

    protected function emptyToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function settingsRepository(): LandingSettingsRepository
    {
        return app(LandingSettingsRepository::class);
    }
}
