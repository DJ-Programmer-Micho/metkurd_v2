<?php

namespace App\Support\Admin;

use App\Models\LandingToolPage;
use App\Support\Landing\LandingToolPageCatalog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesLandingToolsPage
{
    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    public int $perPage = 10;

    public ?int $editingToolPageId = null;
    public string $slug = '';
    public int $sortOrder = 0;
    public string $toolStatus = 'active';

    public ?string $squareImagePath = null;
    public ?string $heroImagePath = null;
    public ?string $cardImagePath = null;

    public $squareImageUpload = null;
    public $heroImageUpload = null;
    public $cardImageUpload = null;

    /** @var array<string, string> */
    public array $badge = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $title = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $heroText = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $summary = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $aboutTitle = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $aboutCopy = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $useCasesTitle = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $useCasesText = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $toolCardTagsText = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $metaTitle = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $metaDescription = ['en' => '', 'ar' => '', 'ku' => ''];

    public bool $appDownloadEnabled = false;
    public bool $appDownloadIosEnabled = false;
    public string $appDownloadIosUrl = '';
    public bool $appDownloadAndroidEnabled = false;
    public string $appDownloadAndroidUrl = '';
    /** @var array<string, string> */
    public array $appDownloadTitle = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $appDownloadBody = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $appDownloadIosLabel = ['en' => '', 'ar' => '', 'ku' => ''];
    /** @var array<string, string> */
    public array $appDownloadAndroidLabel = ['en' => '', 'ar' => '', 'ku' => ''];

    /**
     * @var array<int, array{
     *     icon:string,
     *     sort_order:int,
     *     title:array{en:string,ar:string,ku:string},
     *     body:array{en:string,ar:string,ku:string}
     * }>
     */
    public array $featureItems = [];

    public ?int $toolPageIdPendingDelete = null;
    public string $toolPageDeleteLabel = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function topStats(): array
    {
        return [
            'total' => (int) LandingToolPage::query()->count(),
            'active' => (int) LandingToolPage::query()->where('is_active', true)->count(),
            'inactive' => (int) LandingToolPage::query()->where('is_active', false)->count(),
            'visible' => (int) $this->toolPages->total(),
        ];
    }

    #[Computed]
    public function toolPages()
    {
        $query = LandingToolPage::query();
        $search = trim($this->search);

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('slug', 'like', "%{$search}%")
                    ->orWhere('content->en->title', 'like', "%{$search}%")
                    ->orWhere('content->ar->title', 'like', "%{$search}%")
                    ->orWhere('content->ku->title', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->paginate($this->perPage);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function featureIconOptions(): array
    {
        return [
            'bi bi-stars' => __('Stars'),
            'bi bi-soundwave' => __('Soundwave'),
            'bi bi-mic' => __('Microphone'),
            'bi bi-file-earmark-text' => __('Text File'),
            'bi bi-images' => __('Images'),
            'bi bi-disc' => __('Disc'),
            'bi bi-lightning-charge' => __('Lightning'),
            'bi bi-shield-lock' => __('Shield'),
            'bi bi-cpu' => __('CPU'),
            'bi bi-translate' => __('Translate'),
            'bi bi-cloud-check' => __('Cloud Check'),
            'bi bi-bullseye' => __('Target'),
            'bi bi-graph-up-arrow' => __('Graph Up'),
            'bi bi-robot' => __('Robot'),
            'bi bi-collection-play' => __('Collection Play'),
        ];
    }

    public function importDefaultTools(): void
    {
        $count = $this->toolCatalog()->importFallbackDefaults();

        $this->dispatch('alert', type: 'success', message: __('Imported :count default tool page(s).', ['count' => $count]));
    }

    public function openToolCreateModal(): void
    {
        $this->resetToolForm();
        $this->dispatch('landing-tools:modal-show', id: 'landingToolPageModal');
    }

    public function openToolEditModal(int $toolPageId): void
    {
        $toolPage = LandingToolPage::query()->findOrFail($toolPageId);
        $content = is_array($toolPage->content) ? $toolPage->content : [];
        $locales = ['en', 'ar', 'ku'];

        $this->resetValidation();
        $this->editingToolPageId = $toolPage->id;
        $this->slug = (string) $toolPage->slug;
        $this->sortOrder = (int) $toolPage->sort_order;
        $this->toolStatus = $toolPage->is_active ? 'active' : 'inactive';
        $this->squareImagePath = $toolPage->square_image_path;
        $this->heroImagePath = $toolPage->hero_image_path;
        $this->cardImagePath = $toolPage->card_image_path;
        $this->squareImageUpload = null;
        $this->heroImageUpload = null;
        $this->cardImageUpload = null;

        foreach ($locales as $locale) {
            $this->badge[$locale] = $this->contentValue($content, $locale, 'badge');
            $this->title[$locale] = $this->contentValue($content, $locale, 'title');
            $this->heroText[$locale] = $this->contentValue($content, $locale, 'hero_text');
            $this->summary[$locale] = $this->contentValue($content, $locale, 'summary');
            $this->aboutTitle[$locale] = $this->contentValue($content, $locale, 'about_title');
            $this->aboutCopy[$locale] = $this->contentValue($content, $locale, 'about_copy');
            $this->useCasesTitle[$locale] = $this->contentValue($content, $locale, 'use_cases_title');
            $this->useCasesText[$locale] = $this->implodeLines($this->contentArray($content, $locale, 'use_cases'));
            $this->toolCardTagsText[$locale] = $this->implodeLines($this->contentArray($content, $locale, 'capabilities'));
            $this->metaTitle[$locale] = $this->contentValue($content, $locale, 'meta_title');
            $this->metaDescription[$locale] = $this->contentValue($content, $locale, 'meta_description');
            $this->appDownloadTitle[$locale] = $this->contentValue($content, $locale, 'app_download.title');
            $this->appDownloadBody[$locale] = $this->contentValue($content, $locale, 'app_download.body');
            $this->appDownloadIosLabel[$locale] = $this->contentValue($content, $locale, 'app_download.ios.label');
            $this->appDownloadAndroidLabel[$locale] = $this->contentValue($content, $locale, 'app_download.android.label');
        }

        $this->appDownloadEnabled = $this->contentBool($content, 'en', 'app_download.enabled');
        $this->appDownloadIosEnabled = $this->contentBool($content, 'en', 'app_download.ios.enabled');
        $this->appDownloadIosUrl = $this->contentValue($content, 'en', 'app_download.ios.url');
        $this->appDownloadAndroidEnabled = $this->contentBool($content, 'en', 'app_download.android.enabled');
        $this->appDownloadAndroidUrl = $this->contentValue($content, 'en', 'app_download.android.url');

        $this->featureItems = $this->buildFeatureItemsFromContent($content);
        if ($this->featureItems === []) {
            $this->featureItems = [$this->newFeatureItem()];
        }

        $this->dispatch('landing-tools:modal-show', id: 'landingToolPageModal');
    }

    public function saveToolPage(): void
    {
        $toolPage = $this->editingToolPageId
            ? LandingToolPage::query()->findOrFail($this->editingToolPageId)
            : new LandingToolPage();

        $featureIconOptions = array_keys($this->featureIconOptions());

        $this->validate([
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('landing_tool_pages', 'slug')->ignore($toolPage->id),
            ],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:65535'],
            'toolStatus' => ['required', Rule::in(['active', 'inactive'])],
            'squareImageUpload' => ['nullable', 'image', 'max:4096', 'dimensions:ratio=1/1'],
            'heroImageUpload' => ['nullable', 'image', 'max:5120'],
            'cardImageUpload' => ['nullable', 'image', 'max:5120'],
            'title.en' => ['required', 'string', 'min:2', 'max:180'],
            'heroText.en' => ['required', 'string', 'min:5', 'max:2000'],
            'summary.en' => ['required', 'string', 'min:5', 'max:2000'],
            'aboutCopy.en' => ['required', 'string', 'min:5', 'max:3000'],
            'metaTitle.en' => ['nullable', 'string', 'max:180'],
            'metaDescription.en' => ['nullable', 'string', 'max:300'],
            'badge.*' => ['nullable', 'string', 'max:120'],
            'title.*' => ['nullable', 'string', 'max:180'],
            'heroText.*' => ['nullable', 'string', 'max:2000'],
            'summary.*' => ['nullable', 'string', 'max:2000'],
            'aboutTitle.*' => ['nullable', 'string', 'max:180'],
            'aboutCopy.*' => ['nullable', 'string', 'max:3000'],
            'useCasesTitle.*' => ['nullable', 'string', 'max:180'],
            'useCasesText.*' => ['nullable', 'string', 'max:4000'],
            'toolCardTagsText.*' => ['nullable', 'string', 'max:2000'],
            'metaTitle.*' => ['nullable', 'string', 'max:180'],
            'metaDescription.*' => ['nullable', 'string', 'max:300'],
            'appDownloadEnabled' => ['boolean'],
            'appDownloadIosEnabled' => ['boolean'],
            'appDownloadIosUrl' => [Rule::requiredIf(fn () => $this->appDownloadEnabled && $this->appDownloadIosEnabled), 'nullable', 'url', 'max:2048'],
            'appDownloadAndroidEnabled' => ['boolean'],
            'appDownloadAndroidUrl' => [Rule::requiredIf(fn () => $this->appDownloadEnabled && $this->appDownloadAndroidEnabled), 'nullable', 'url', 'max:2048'],
            'appDownloadTitle.*' => ['nullable', 'string', 'max:180'],
            'appDownloadBody.*' => ['nullable', 'string', 'max:1600'],
            'appDownloadIosLabel.*' => ['nullable', 'string', 'max:120'],
            'appDownloadAndroidLabel.*' => ['nullable', 'string', 'max:120'],
            'featureItems' => ['nullable', 'array', 'max:30'],
            'featureItems.*.icon' => ['required', 'string', Rule::in($featureIconOptions)],
            'featureItems.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'featureItems.*.title.en' => ['nullable', 'string', 'max:180'],
            'featureItems.*.title.ar' => ['nullable', 'string', 'max:180'],
            'featureItems.*.title.ku' => ['nullable', 'string', 'max:180'],
            'featureItems.*.body.en' => ['nullable', 'string', 'max:1000'],
            'featureItems.*.body.ar' => ['nullable', 'string', 'max:1000'],
            'featureItems.*.body.ku' => ['nullable', 'string', 'max:1000'],
        ], [
            'slug.regex' => __('Slug must use lowercase letters, numbers, and hyphens only.'),
            'squareImageUpload.dimensions' => __('Tool square image must use a 1:1 ratio.'),
        ]);

        $toolPage->slug = trim($this->slug);
        $toolPage->icon_class = null;
        $toolPage->sort_order = (int) $this->sortOrder;
        $toolPage->is_active = $this->toolStatus === 'active';
        $toolPage->content = $this->buildLocalizedContentPayload();

        if ($this->squareImageUpload) {
            $this->deletePublicAsset($toolPage->square_image_path);
            $toolPage->square_image_path = $this->squareImageUpload->store('landing/tools/square', 'public');
        }

        if ($this->heroImageUpload) {
            $this->deletePublicAsset($toolPage->hero_image_path);
            $toolPage->hero_image_path = $this->heroImageUpload->store('landing/tools', 'public');
        }

        if ($this->cardImageUpload) {
            $this->deletePublicAsset($toolPage->card_image_path);
            $toolPage->card_image_path = $this->cardImageUpload->store('landing/tools', 'public');
        }

        $toolPage->save();

        $this->dispatch('alert', type: 'success', message: $this->editingToolPageId
            ? __('Landing tool page updated successfully.')
            : __('Landing tool page created successfully.'));

        $this->dispatch('landing-tools:modal-hide', id: 'landingToolPageModal');
        $this->resetToolForm();
    }

    public function addFeatureItem(): void
    {
        $this->featureItems[] = $this->newFeatureItem(count($this->featureItems));
    }

    public function removeFeatureItem(int $index): void
    {
        if (! array_key_exists($index, $this->featureItems)) {
            return;
        }

        unset($this->featureItems[$index]);
        $this->featureItems = array_values($this->featureItems);
        $this->resetFeatureSortOrder();
    }

    public function moveFeatureItemUp(int $index): void
    {
        if ($index <= 0 || ! array_key_exists($index, $this->featureItems)) {
            return;
        }

        [$this->featureItems[$index - 1], $this->featureItems[$index]] = [$this->featureItems[$index], $this->featureItems[$index - 1]];
        $this->featureItems = array_values($this->featureItems);
        $this->resetFeatureSortOrder();
    }

    public function moveFeatureItemDown(int $index): void
    {
        if (! array_key_exists($index, $this->featureItems) || ! array_key_exists($index + 1, $this->featureItems)) {
            return;
        }

        [$this->featureItems[$index], $this->featureItems[$index + 1]] = [$this->featureItems[$index + 1], $this->featureItems[$index]];
        $this->featureItems = array_values($this->featureItems);
        $this->resetFeatureSortOrder();
    }

    public function toggleToolPageStatus(int $toolPageId): void
    {
        $toolPage = LandingToolPage::query()->findOrFail($toolPageId);
        $toolPage->update(['is_active' => ! $toolPage->is_active]);

        $this->dispatch('alert', type: 'success', message: $toolPage->is_active
            ? __('Tool page is now active.')
            : __('Tool page is now inactive.'));
    }

    public function confirmToolPageDelete(int $toolPageId): void
    {
        $toolPage = LandingToolPage::query()->findOrFail($toolPageId);

        $this->toolPageIdPendingDelete = $toolPage->id;
        $this->toolPageDeleteLabel = (string) $toolPage->slug;
        $this->dispatch('landing-tools:modal-show', id: 'landingToolPageDeleteModal');
    }

    public function performToolPageDelete(): void
    {
        if (! $this->toolPageIdPendingDelete) {
            return;
        }

        $toolPage = LandingToolPage::query()->findOrFail($this->toolPageIdPendingDelete);

        $this->deletePublicAsset($toolPage->square_image_path);
        $this->deletePublicAsset($toolPage->hero_image_path);
        $this->deletePublicAsset($toolPage->card_image_path);
        $toolPage->delete();

        $this->dispatch('alert', type: 'success', message: __('Landing tool page deleted successfully.'));
        $this->dispatch('landing-tools:modal-hide', id: 'landingToolPageDeleteModal');
        $this->resetDeleteState();
    }

    public function resetToolForm(): void
    {
        $this->resetValidation();

        $this->editingToolPageId = null;
        $this->slug = '';
        $this->sortOrder = 0;
        $this->toolStatus = 'active';
        $this->squareImagePath = null;
        $this->heroImagePath = null;
        $this->cardImagePath = null;
        $this->squareImageUpload = null;
        $this->heroImageUpload = null;
        $this->cardImageUpload = null;
        $this->featureItems = [$this->newFeatureItem()];
        $this->appDownloadEnabled = false;
        $this->appDownloadIosEnabled = false;
        $this->appDownloadIosUrl = '';
        $this->appDownloadAndroidEnabled = false;
        $this->appDownloadAndroidUrl = '';

        foreach (['en', 'ar', 'ku'] as $locale) {
            $this->badge[$locale] = '';
            $this->title[$locale] = '';
            $this->heroText[$locale] = '';
            $this->summary[$locale] = '';
            $this->aboutTitle[$locale] = '';
            $this->aboutCopy[$locale] = '';
            $this->useCasesTitle[$locale] = '';
            $this->useCasesText[$locale] = '';
            $this->toolCardTagsText[$locale] = '';
            $this->metaTitle[$locale] = '';
            $this->metaDescription[$locale] = '';
            $this->appDownloadTitle[$locale] = '';
            $this->appDownloadBody[$locale] = '';
            $this->appDownloadIosLabel[$locale] = '';
            $this->appDownloadAndroidLabel[$locale] = '';
        }
    }

    public function resetDeleteState(): void
    {
        $this->toolPageIdPendingDelete = null;
        $this->toolPageDeleteLabel = '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function buildLocalizedContentPayload(): array
    {
        $payload = [];
        $featuresByLocale = $this->buildLocalizedFeatureCards();

        foreach (['en', 'ar', 'ku'] as $locale) {
            $payload[$locale] = [
                'badge' => $this->emptyToNull($this->badge[$locale]) ?? '',
                'title' => $this->emptyToNull($this->title[$locale]) ?? '',
                'hero_text' => $this->emptyToNull($this->heroText[$locale]) ?? '',
                'summary' => $this->emptyToNull($this->summary[$locale]) ?? '',
                'about_title' => $this->emptyToNull($this->aboutTitle[$locale]) ?? '',
                'about_copy' => $this->emptyToNull($this->aboutCopy[$locale]) ?? '',
                'use_cases_title' => $this->emptyToNull($this->useCasesTitle[$locale]) ?? '',
                'use_cases' => $this->parseLines($this->useCasesText[$locale]),
                'features' => $featuresByLocale[$locale],
                'capabilities' => $this->parseLines($this->toolCardTagsText[$locale]),
                'meta_title' => $this->emptyToNull($this->metaTitle[$locale]) ?? '',
                'meta_description' => $this->emptyToNull($this->metaDescription[$locale]) ?? '',
                'app_download' => $this->buildLocalizedAppDownloadPayload($locale),
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildLocalizedAppDownloadPayload(string $locale): array
    {
        return [
            'enabled' => (bool) $this->appDownloadEnabled,
            'title' => $this->emptyToNull($this->appDownloadTitle[$locale]) ?? '',
            'body' => $this->emptyToNull($this->appDownloadBody[$locale]) ?? '',
            'ios' => [
                'enabled' => (bool) ($this->appDownloadEnabled && $this->appDownloadIosEnabled),
                'url' => $this->emptyToNull($this->appDownloadIosUrl) ?? '',
                'label' => $this->emptyToNull($this->appDownloadIosLabel[$locale]) ?? '',
            ],
            'android' => [
                'enabled' => (bool) ($this->appDownloadEnabled && $this->appDownloadAndroidEnabled),
                'url' => $this->emptyToNull($this->appDownloadAndroidUrl) ?? '',
                'label' => $this->emptyToNull($this->appDownloadAndroidLabel[$locale]) ?? '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    protected function contentValue(array $content, string $locale, string $key): string
    {
        $value = data_get($content, "{$locale}.{$key}");

        if ($value === null || $value === '') {
            $value = data_get($content, "en.{$key}");
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    protected function contentBool(array $content, string $locale, string $key): bool
    {
        $value = data_get($content, "{$locale}.{$key}");

        if ($value === null || $value === '') {
            $value = data_get($content, "en.{$key}");
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) ((int) $value);
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return string[]
     */
    protected function contentArray(array $content, string $locale, string $key): array
    {
        $value = data_get($content, "{$locale}.{$key}");

        if (! is_array($value) || $value === []) {
            $value = data_get($content, "en.{$key}", []);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item) => trim((string) $item), $value),
            fn (string $item) => $item !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, array{
     *     icon:string,
     *     sort_order:int,
     *     title:array{en:string,ar:string,ku:string},
     *     body:array{en:string,ar:string,ku:string}
     * }>
     */
    protected function buildFeatureItemsFromContent(array $content): array
    {
        $locales = ['en', 'ar', 'ku'];
        $localized = [];

        foreach ($locales as $locale) {
            $localized[$locale] = $this->contentFeatures($content, $locale);
        }

        $max = max(array_map(fn (array $items) => count($items), $localized));

        if ($max === 0) {
            return [];
        }

        $items = [];

        for ($index = 0; $index < $max; $index++) {
            $icon = 'bi bi-stars';
            $sortOrder = $index;
            $title = ['en' => '', 'ar' => '', 'ku' => ''];
            $body = ['en' => '', 'ar' => '', 'ku' => ''];

            foreach ($locales as $locale) {
                $feature = $localized[$locale][$index] ?? null;

                if (! is_array($feature)) {
                    continue;
                }

                $candidateIcon = trim((string) ($feature['icon'] ?? ''));
                if ($candidateIcon !== '') {
                    $icon = $this->normalizeFeatureIconOption($candidateIcon);
                }

                $sortOrder = (int) ($feature['sort_order'] ?? $sortOrder);
                $title[$locale] = trim((string) ($feature['title'] ?? ''));
                $body[$locale] = trim((string) ($feature['copy'] ?? ''));
            }

            $items[] = [
                'icon' => $icon,
                'sort_order' => $sortOrder,
                'title' => $title,
                'body' => $body,
            ];
        }

        usort($items, fn (array $a, array $b) => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        return array_values(array_map(function (array $item, int $index) {
            $item['sort_order'] = $index;
            return $item;
        }, $items, array_keys($items)));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, array{icon:string,title:string,copy:string,sort_order:int}>
     */
    protected function contentFeatures(array $content, string $locale): array
    {
        $value = data_get($content, "{$locale}.features");

        if (! is_array($value) || $value === []) {
            $value = data_get($content, "en.features", []);
        }

        $features = [];

        if (is_array($value) && $value !== []) {
            foreach ($value as $index => $feature) {
                if (is_string($feature)) {
                    $features[] = [
                        'icon' => 'bi bi-stars',
                        'title' => trim($feature),
                        'copy' => '',
                        'sort_order' => $index,
                    ];
                    continue;
                }

                if (! is_array($feature)) {
                    continue;
                }

                $features[] = [
                    'icon' => $this->normalizeFeatureIconOption((string) data_get($feature, 'icon', 'bi bi-stars')),
                    'title' => trim((string) data_get($feature, 'title', '')),
                    'copy' => trim((string) data_get($feature, 'copy', '')),
                    'sort_order' => (int) data_get($feature, 'sort_order', $index),
                ];
            }
        }

        if ($features === []) {
            $bullets = $this->contentArray($content, $locale, 'feature_bullets');
            foreach ($bullets as $index => $bullet) {
                $features[] = [
                    'icon' => 'bi bi-stars',
                    'title' => $bullet,
                    'copy' => '',
                    'sort_order' => $index,
                ];
            }
        }

        usort($features, fn (array $a, array $b) => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        return array_values($features);
    }

    /**
     * @return array<string, array<int, array{icon:string,title:string,copy:string,sort_order:int}>>
     */
    protected function buildLocalizedFeatureCards(): array
    {
        $locales = ['en', 'ar', 'ku'];
        $items = $this->normalizedFeatureItems();
        $cards = ['en' => [], 'ar' => [], 'ku' => []];

        foreach ($items as $order => $item) {
            foreach ($locales as $locale) {
                $title = trim((string) ($item['title'][$locale] ?? ''));
                $copy = trim((string) ($item['body'][$locale] ?? ''));

                if ($title === '' && $locale !== 'en') {
                    $title = trim((string) ($item['title']['en'] ?? ''));
                }

                if ($copy === '' && $locale !== 'en') {
                    $copy = trim((string) ($item['body']['en'] ?? ''));
                }

                if ($title === '' && $copy === '') {
                    continue;
                }

                $cards[$locale][] = [
                    'icon' => $this->normalizeFeatureIconOption((string) ($item['icon'] ?? 'bi bi-stars')),
                    'title' => $title,
                    'copy' => $copy,
                    'sort_order' => $order,
                ];
            }
        }

        return $cards;
    }

    /**
     * @return array<int, array{
     *     icon:string,
     *     sort_order:int,
     *     title:array{en:string,ar:string,ku:string},
     *     body:array{en:string,ar:string,ku:string}
     * }>
     */
    protected function normalizedFeatureItems(): array
    {
        $normalized = [];

        foreach ($this->featureItems as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = [
                'en' => trim((string) data_get($item, 'title.en', '')),
                'ar' => trim((string) data_get($item, 'title.ar', '')),
                'ku' => trim((string) data_get($item, 'title.ku', '')),
            ];
            $body = [
                'en' => trim((string) data_get($item, 'body.en', '')),
                'ar' => trim((string) data_get($item, 'body.ar', '')),
                'ku' => trim((string) data_get($item, 'body.ku', '')),
            ];

            $hasContent = implode('', $title) !== '' || implode('', $body) !== '';
            if (! $hasContent) {
                continue;
            }

            $normalized[] = [
                'icon' => $this->normalizeFeatureIconOption((string) data_get($item, 'icon', 'bi bi-stars')),
                'sort_order' => (int) data_get($item, 'sort_order', $index),
                'title' => $title,
                'body' => $body,
            ];
        }

        usort($normalized, fn (array $a, array $b) => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        return array_values(array_map(function (array $item, int $index) {
            $item['sort_order'] = $index;
            return $item;
        }, $normalized, array_keys($normalized)));
    }

    protected function newFeatureItem(int $sortOrder = 0): array
    {
        return [
            'icon' => 'bi bi-stars',
            'sort_order' => $sortOrder,
            'title' => ['en' => '', 'ar' => '', 'ku' => ''],
            'body' => ['en' => '', 'ar' => '', 'ku' => ''],
        ];
    }

    protected function resetFeatureSortOrder(): void
    {
        foreach ($this->featureItems as $index => $item) {
            $this->featureItems[$index]['sort_order'] = $index;
        }
    }

    protected function normalizeFeatureIconOption(string $icon): string
    {
        $icon = trim($icon);
        $options = array_keys($this->featureIconOptions());

        return in_array($icon, $options, true) ? $icon : 'bi bi-stars';
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

    protected function deletePublicAsset(?string $path): void
    {
        $path = trim((string) $path);

        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    protected function toolCatalog(): LandingToolPageCatalog
    {
        return app(LandingToolPageCatalog::class);
    }
}
