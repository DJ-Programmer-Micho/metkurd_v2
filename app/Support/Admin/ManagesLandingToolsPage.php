<?php

namespace App\Support\Admin;

use App\Models\LandingToolPage;
use App\Models\Voice;
use App\Support\Landing\LandingDemoSampleSchema;
use App\Support\Landing\LandingMediaStorage;
use App\Support\Landing\LandingToolPageCatalog;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesLandingToolsPage
{
    use SecureAdminComponent;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    public int $perPage = 10;

    public ?int $editingToolPageId = null;

    public string $slug = '';

    public int $sortOrder = 0;

    public string $toolStatus = 'active';

    public string $demoType = '';

    public string $demoConfigJson = '';

    /** @var array<string, mixed> */
    public array $demoMeta = ['sample_text' => ''];

    /** @var array<int, array<string, mixed>> */
    public array $demoItems = [];

    public bool $removeSquareImage = false;

    public bool $removeHeroImage = false;

    public bool $removeCardImage = false;

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

    public function updatedDemoType(): void
    {
        $this->normalizeDemoTypeFromState();
        $this->syncDemoBuilderFromConfig($this->decodedDemoConfigFromJson());
    }

    public function addDemoItem(): void
    {
        $type = $this->resolvedDemoType();
        if ($type === '') {
            return;
        }

        $this->demoItems[] = $this->ensureDemoBuilderRowKey($this->newDemoItemPayload($type));
    }

    public function removeDemoItem(int $index): void
    {
        if (! array_key_exists($index, $this->demoItems)) {
            return;
        }

        unset($this->demoItems[$index]);
        $this->demoItems = array_values($this->demoItems);
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

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function demoTypeOptions(): array
    {
        return $this->demoSchema()->adminTypeOptions();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function availableDemoVoices(): array
    {
        return Voice::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['code', 'name'])
            ->mapWithKeys(fn (Voice $voice) => [
                (string) $voice->code => trim((string) $voice->name) !== ''
                    ? (string) $voice->name.' ('.(string) $voice->code.')'
                    : (string) $voice->code,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function demoGroupOptions(): array
    {
        $type = $this->resolvedDemoType();
        if (! in_array($type, ['tts', 'ctts'], true)) {
            return [];
        }

        $options = [];
        $normalized = $this->demoSchema()->normalizeConfig($type, $this->decodedDemoConfigFromJson(), $this->slug);
        $groups = is_array(data_get($normalized, 'groups')) ? (array) data_get($normalized, 'groups') : [];

        foreach ($groups as $group) {
            $key = $this->normalizeDemoGroupKey((string) data_get($group, 'key', ''));
            $label = trim((string) data_get($group, 'label', ''));

            if ($key !== '' && $label !== '') {
                $options[$key] = $label;
            }
        }

        if ($options !== []) {
            return $options;
        }

        foreach ($this->defaultDemoGroupsByType($type) as $group) {
            $key = $this->normalizeDemoGroupKey((string) data_get($group, 'key', ''));
            $label = trim((string) data_get($group, 'label', ''));

            if ($key !== '' && $label !== '') {
                $options[$key] = $label;
            }
        }

        return $options;
    }

    public function importDefaultTools(): void
    {
        $this->authorizeAdminChange('admin.catalog');

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
        $hasDemoTypeColumn = Schema::hasColumn('landing_tool_pages', 'demo_type');
        $hasDemoConfigColumn = Schema::hasColumn('landing_tool_pages', 'demo_config');
        $shadowDemo = is_array(data_get($content, '_demo')) ? (array) data_get($content, '_demo') : [];

        $this->resetValidation();
        $this->editingToolPageId = $toolPage->id;
        $this->slug = (string) $toolPage->slug;
        $this->sortOrder = (int) $toolPage->sort_order;
        $this->toolStatus = $toolPage->is_active ? 'active' : 'inactive';
        $this->squareImagePath = $toolPage->square_image_path;
        $this->heroImagePath = $toolPage->hero_image_path;
        $this->cardImagePath = $toolPage->card_image_path;
        $shadowDemoType = trim((string) data_get($shadowDemo, 'type', ''));
        $shadowDemoConfig = is_array(data_get($shadowDemo, 'config')) ? (array) data_get($shadowDemo, 'config') : [];
        $rawDemoType = $hasDemoTypeColumn
            ? trim((string) ($toolPage->demo_type ?: $shadowDemoType))
            : $shadowDemoType;
        $rawDemoConfig = $hasDemoConfigColumn
            ? ((is_array($toolPage->demo_config) && $toolPage->demo_config !== []) ? $toolPage->demo_config : $shadowDemoConfig)
            : $shadowDemoConfig;
        $normalizedDemoType = $this->demoSchema()->normalizeType($rawDemoType, (string) $toolPage->slug);
        $normalizedDemoConfig = $this->demoSchema()->normalizeConfig($normalizedDemoType, $rawDemoConfig, (string) $toolPage->slug);
        $this->demoType = (string) ($normalizedDemoType ?? '');
        $this->demoConfigJson = $normalizedDemoConfig !== []
            ? (string) json_encode($normalizedDemoConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';
        $this->removeSquareImage = false;
        $this->removeHeroImage = false;
        $this->removeCardImage = false;
        $this->squareImageUpload = null;
        $this->heroImageUpload = null;
        $this->cardImageUpload = null;
        $this->syncDemoBuilderFromConfig($normalizedDemoConfig);

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

    public function openDemoConfigModal(): void
    {
        $this->resetValidation();
        $this->normalizeDemoTypeFromState();

        if ($this->demoType === '') {
            $this->addError('demoType', __('Please choose a demo type first.'));

            return;
        }

        $this->syncDemoBuilderFromConfig($this->decodedDemoConfigFromJson());
        $this->dispatch('landing-tools:modal-hide', id: 'landingToolPageModal');
        $this->dispatch('landing-tools:modal-show', id: 'landingToolDemoModal');
    }

    public function closeDemoConfigModal(): void
    {
        $this->dispatch('landing-tools:modal-hide', id: 'landingToolDemoModal');
        $this->dispatch('landing-tools:modal-show', id: 'landingToolPageModal');
    }

    public function clearSquareImage(): void
    {
        $this->removeSquareImage = true;
        $this->squareImagePath = null;
        $this->squareImageUpload = null;
    }

    public function clearHeroImage(): void
    {
        $this->removeHeroImage = true;
        $this->heroImagePath = null;
        $this->heroImageUpload = null;
    }

    public function clearCardImage(): void
    {
        $this->removeCardImage = true;
        $this->cardImagePath = null;
        $this->cardImageUpload = null;
    }

    public function saveToolPage(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $toolPage = $this->editingToolPageId
            ? LandingToolPage::query()->findOrFail($this->editingToolPageId)
            : new LandingToolPage;

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
            'demoType' => ['nullable', 'string', 'max:80', Rule::in(array_keys($this->demoTypeOptions()))],
            'demoConfigJson' => ['nullable', 'string', 'max:65000'],
            'demoMeta.sample_text' => ['nullable', 'string', 'max:12000'],
            'demoItems' => ['nullable', 'array', 'max:40'],
            'demoItems.*.label' => ['nullable', 'string', 'max:180'],
            'demoItems.*.group_key' => ['nullable', 'string', 'max:120'],
            'demoItems.*.engine' => ['nullable', 'string', 'max:80'],
            'demoItems.*.voice_id' => ['nullable', 'string', 'max:120'],
            'demoItems.*.audio_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.audio_upload' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac,opus', 'max:51200'],
            'demoItems.*.description' => ['nullable', 'string', 'max:2000'],
            'demoItems.*.title' => ['nullable', 'string', 'max:180'],
            'demoItems.*.source_label' => ['nullable', 'string', 'max:180'],
            'demoItems.*.source_audio_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.source_audio_upload' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac,opus', 'max:51200'],
            'demoItems.*.cloned_label' => ['nullable', 'string', 'max:180'],
            'demoItems.*.cloned_audio_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.cloned_audio_upload' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac,opus', 'max:51200'],
            'demoItems.*.notes' => ['nullable', 'string', 'max:3000'],
            'demoItems.*.transcript' => ['nullable', 'string', 'max:15000'],
            'demoItems.*.language' => ['nullable', 'string', 'max:80'],
            'demoItems.*.confidence' => ['nullable', 'string', 'max:80'],
            'demoItems.*.original_audio_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.original_audio_upload' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac,opus', 'max:51200'],
            'demoItems.*.vocals_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.vocals_upload' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac,opus', 'max:51200'],
            'demoItems.*.drums_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.drums_upload' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac,opus', 'max:51200'],
            'demoItems.*.bass_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.bass_upload' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac,opus', 'max:51200'],
            'demoItems.*.other_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.other_upload' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac,opus', 'max:51200'],
            'demoItems.*.image_url' => ['nullable', 'string', 'max:2048'],
            'demoItems.*.image_upload' => ['nullable', 'image', 'max:10240'],
            'demoItems.*.extracted_text' => ['nullable', 'string', 'max:20000'],
            'demoItems.*.source_lang' => ['nullable', 'string', 'max:10'],
            'demoItems.*.target_lang' => ['nullable', 'string', 'max:10'],
            'demoItems.*.source_text' => ['nullable', 'string', 'max:20000'],
            'demoItems.*.target_text' => ['nullable', 'string', 'max:20000'],
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

        $this->normalizeDemoTypeFromState();
        $resolvedDemoType = $this->resolvedDemoType();

        $demoConfig = [];
        $rawDemoConfig = trim($this->demoConfigJson);

        if ($rawDemoConfig !== '') {
            $decoded = json_decode($rawDemoConfig, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                $this->addError('demoConfigJson', __('Demo config must be a valid JSON object or array.'));

                return;
            }

            $demoConfig = $decoded;
        }

        $mediaDisk = $this->landingMediaStorage()->diskName();
        $demoConfig = $this->buildDemoConfigPayload($demoConfig, trim((string) $this->slug), $mediaDisk);
        $this->demoConfigJson = $demoConfig !== []
            ? (string) json_encode($demoConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        $hasDemoTypeColumn = Schema::hasColumn('landing_tool_pages', 'demo_type');
        $hasDemoConfigColumn = Schema::hasColumn('landing_tool_pages', 'demo_config');

        $toolPage->slug = trim($this->slug);
        $toolPage->icon_class = null;
        if ($hasDemoTypeColumn) {
            $toolPage->demo_type = $this->emptyToNull($resolvedDemoType);
        }
        if ($hasDemoConfigColumn) {
            $toolPage->demo_config = $demoConfig;
        }
        $toolPage->sort_order = (int) $this->sortOrder;
        $toolPage->is_active = $this->toolStatus === 'active';
        $contentPayload = $this->buildLocalizedContentPayload();
        if (! $hasDemoTypeColumn || ! $hasDemoConfigColumn) {
            $contentPayload['_demo'] = [
                'type' => $this->emptyToNull($resolvedDemoType),
                'config' => $demoConfig,
            ];
        }
        $toolPage->content = $contentPayload;

        if ($this->squareImageUpload) {
            $this->deletePublicAsset($toolPage->square_image_path);
            $toolPage->square_image_path = $this->squareImageUpload->store('web-setting/tools/square', $mediaDisk);
        } elseif ($this->removeSquareImage) {
            $this->deletePublicAsset($toolPage->square_image_path);
            $toolPage->square_image_path = null;
        } else {
            $toolPage->square_image_path = $this->landingMediaStorage()->normalizeStoredPath($toolPage->square_image_path);
        }

        if ($this->heroImageUpload) {
            $this->deletePublicAsset($toolPage->hero_image_path);
            $toolPage->hero_image_path = $this->heroImageUpload->store('web-setting/tools', $mediaDisk);
        } elseif ($this->removeHeroImage) {
            $this->deletePublicAsset($toolPage->hero_image_path);
            $toolPage->hero_image_path = null;
        } else {
            $toolPage->hero_image_path = $this->landingMediaStorage()->normalizeStoredPath($toolPage->hero_image_path);
        }

        if ($this->cardImageUpload) {
            $this->deletePublicAsset($toolPage->card_image_path);
            $toolPage->card_image_path = $this->cardImageUpload->store('web-setting/tools', $mediaDisk);
        } elseif ($this->removeCardImage) {
            $this->deletePublicAsset($toolPage->card_image_path);
            $toolPage->card_image_path = null;
        } else {
            $toolPage->card_image_path = $this->landingMediaStorage()->normalizeStoredPath($toolPage->card_image_path);
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
        $this->authorizeAdminChange('admin.catalog');

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
        $this->authorizeAdminChange('admin.catalog');

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
        $this->demoType = '';
        $this->demoConfigJson = '';
        $this->removeSquareImage = false;
        $this->removeHeroImage = false;
        $this->removeCardImage = false;
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

        $this->resetDemoBuilderForm();
        $this->dispatch('landing-tools:modal-hide', id: 'landingToolDemoModal');
    }

    public function resetDeleteState(): void
    {
        $this->toolPageIdPendingDelete = null;
        $this->toolPageDeleteLabel = '';
    }

    protected function resetDemoBuilderForm(): void
    {
        $this->demoMeta = ['sample_text' => ''];
        $this->demoItems = [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function syncDemoBuilderFromConfig(array $config): void
    {
        $this->resetDemoBuilderForm();
        $this->normalizeDemoTypeFromState();

        $type = $this->resolvedDemoType();
        if ($type === '') {
            return;
        }

        $normalized = $this->demoSchema()->normalizeConfig($type, $config, $this->slug);
        if ($normalized === []) {
            $normalized = [
                'type' => $type,
                'version' => 1,
                'meta' => [],
                'items' => [],
            ];
        }

        $this->demoMeta = [
            'sample_text' => trim((string) data_get($normalized, 'meta.sample_text', '')),
        ];

        $items = is_array(data_get($normalized, 'items')) ? (array) data_get($normalized, 'items') : [];
        $groups = is_array(data_get($normalized, 'groups')) ? (array) data_get($normalized, 'groups') : [];

        if (in_array($type, ['tts', 'ctts'], true) && $groups !== []) {
            $flattened = [];

            foreach ($groups as $group) {
                $groupRow = is_array($group) ? $group : [];
                $groupKey = $this->normalizeDemoGroupKey((string) data_get($groupRow, 'key', ''));
                $groupEngine = $this->normalizeDemoEngineForType($type, (string) data_get($groupRow, 'engine', ''));
                $samples = is_array(data_get($groupRow, 'samples'))
                    ? (array) data_get($groupRow, 'samples')
                    : (is_array(data_get($groupRow, 'items')) ? (array) data_get($groupRow, 'items') : []);

                foreach ($samples as $sample) {
                    $sampleRow = is_array($sample) ? $sample : [];

                    if ($groupKey !== '' && trim((string) data_get($sampleRow, 'group_key', '')) === '') {
                        $sampleRow['group_key'] = $groupKey;
                    }

                    if ($groupEngine !== '' && trim((string) data_get($sampleRow, 'engine', '')) === '') {
                        $sampleRow['engine'] = $groupEngine;
                    }

                    $flattened[] = $sampleRow;
                }
            }

            if ($flattened !== []) {
                $items = $flattened;
            }
        }

        $this->demoItems = array_values(array_map(
            fn ($item) => $this->inflateBuilderItemPayload($type, is_array($item) ? $item : []),
            $items
        ));

        if ($this->demoItems === []) {
            $this->demoItems = $this->defaultDemoItemsForType($type);
        }

        $this->demoItems = $this->withDemoBuilderRowKeys($this->demoItems);

        $this->demoConfigJson = (string) json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodedDemoConfigFromJson(): array
    {
        $raw = trim($this->demoConfigJson);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $type = $this->resolvedDemoType();
        if ($type === '') {
            return $decoded;
        }

        return $this->demoSchema()->normalizeConfig($type, $decoded, $this->slug);
    }

    /**
     * @param  array<string, mixed>  $baseConfig
     * @return array<string, mixed>
     */
    protected function buildDemoConfigPayload(array $baseConfig, string $slug, string $mediaDisk): array
    {
        $type = (string) ($this->demoSchema()->normalizeType($this->demoType, $slug) ?? '');
        if ($type === '') {
            return [];
        }

        $base = $this->demoSchema()->normalizeConfig($type, $baseConfig, $slug);
        $assetBase = $this->demoAssetDirectory($slug).'/'.$type;
        $meta = is_array(data_get($base, 'meta')) ? (array) data_get($base, 'meta') : [];

        $sampleText = trim((string) data_get($this->demoMeta, 'sample_text', ''));
        if ($sampleText !== '') {
            $meta['sample_text'] = $sampleText;
        } else {
            unset($meta['sample_text']);
        }

        $items = [];
        $baseItems = is_array(data_get($base, 'items')) ? array_values((array) data_get($base, 'items')) : [];
        $baseGroups = is_array(data_get($base, 'groups')) ? array_values((array) data_get($base, 'groups')) : [];
        $groupedSamples = [];
        $resolvedGroups = $this->resolvedDemoGroupsForBuilder($type, $baseGroups);

        foreach ($resolvedGroups as $group) {
            $groupKey = $this->normalizeDemoGroupKey((string) data_get($group, 'key', ''));
            if ($groupKey === '') {
                continue;
            }
            $groupedSamples[$groupKey] = [];
        }

        foreach (array_values($this->demoItems) as $index => $row) {
            $item = $this->stripDemoBuilderInternalKeys(is_array($row) ? $row : []);
            $current = is_array(data_get($baseItems, (string) $index)) ? (array) data_get($baseItems, (string) $index) : [];
            $payload = $this->buildDemoItemPayloadByType(
                type: $type,
                row: $item,
                current: $current,
                directory: $assetBase,
                disk: $mediaDisk
            );

            if ($payload !== []) {
                $items[] = $payload;

                if (in_array($type, ['tts', 'ctts'], true)) {
                    $groupKey = $this->normalizeDemoGroupKey((string) data_get($payload, 'group_key', ''));

                    if ($groupKey === '') {
                        $groupKey = $this->inferDemoGroupKeyFromItem($type, $payload);
                    }

                    if ($groupKey !== '') {
                        if (! array_key_exists($groupKey, $groupedSamples)) {
                            $groupedSamples[$groupKey] = [];
                        }

                        $groupedSamples[$groupKey][] = $payload;
                    }
                }
            }
        }

        $envelope = [
            'type' => $type,
            'version' => 1,
            'meta' => $meta,
            'items' => $items,
            'groups' => [],
        ];

        if (in_array($type, ['tts', 'ctts'], true) && $resolvedGroups !== []) {
            $groups = [];

            foreach ($resolvedGroups as $group) {
                $groupRow = is_array($group) ? $group : [];
                $groupKey = $this->normalizeDemoGroupKey((string) data_get($groupRow, 'key', ''));

                if ($groupKey === '') {
                    continue;
                }

                $samples = array_values((array) ($groupedSamples[$groupKey] ?? []));
                $groupRow['samples'] = $samples;
                $groupRow['items'] = $samples;
                $groups[] = $groupRow;
            }

            foreach ($groupedSamples as $groupKey => $samples) {
                if ($samples === [] || collect($groups)->contains(fn (array $row): bool => $this->normalizeDemoGroupKey((string) data_get($row, 'key', '')) === $groupKey)) {
                    continue;
                }

                $blueprint = $this->demoGroupBlueprintByKey($type, $groupKey);

                $groups[] = array_filter([
                    'key' => $groupKey,
                    'label' => (string) data_get($blueprint, 'label', Str::headline(str_replace('_', ' ', $groupKey))),
                    'engine' => (string) data_get($blueprint, 'engine', $this->normalizeDemoEngineForType($type, (string) data_get($samples[0] ?? [], 'engine', ''))),
                    'description' => (string) data_get($blueprint, 'description', ''),
                    'limit' => (int) data_get($blueprint, 'limit', in_array($type, ['tts'], true) ? 6 : 2),
                    'random' => (bool) data_get($blueprint, 'random', $type === 'tts'),
                    'samples' => $samples,
                    'items' => $samples,
                ], fn ($value) => ! ($value === null || $value === ''));
            }

            $envelope['groups'] = $groups;
        }

        return $this->demoSchema()->normalizeConfig($type, $envelope, $slug);
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    protected function resolvedDemoGroupsForBuilder(string $type, array $groups): array
    {
        if (! in_array($type, ['tts', 'ctts'], true)) {
            return [];
        }

        $defaults = collect($this->defaultDemoGroupsByType($type))
            ->keyBy(fn (array $group): string => $this->normalizeDemoGroupKey((string) data_get($group, 'key', '')));
        $resolved = [];

        foreach ($groups as $index => $group) {
            $row = is_array($group) ? $group : [];
            $key = $this->normalizeDemoGroupKey((string) data_get($row, 'key', ''));
            $engine = $this->normalizeDemoEngineForType($type, (string) data_get($row, 'engine', ''));

            if ($key === '' && $engine !== '') {
                $key = $this->inferDemoGroupKeyFromItem($type, ['engine' => $engine]);
            }
            if ($key === '') {
                $key = $type.'_group_'.($index + 1);
            }

            /** @var array<string, mixed> $base */
            $base = (array) ($defaults->get($key, []) ?: []);
            if ($base === [] && $engine !== '') {
                $base = (array) ($defaults->first(fn (array $item): bool => $this->normalizeDemoEngineForType($type, (string) data_get($item, 'engine', '')) === $engine) ?? []);
            }

            $resolved[] = array_filter([
                'key' => $key,
                'label' => trim((string) data_get($row, 'label', (string) data_get($base, 'label', Str::headline(str_replace('_', ' ', $key))))),
                'engine' => $engine !== '' ? $engine : $this->normalizeDemoEngineForType($type, (string) data_get($base, 'engine', '')),
                'description' => trim((string) data_get($row, 'description', (string) data_get($base, 'description', ''))),
                'limit' => (int) data_get($row, 'limit', data_get($base, 'limit', $type === 'tts' ? 6 : 2)),
                'random' => $this->toBool(data_get($row, 'random', data_get($base, 'random', $type === 'tts'))),
                'samples' => [],
                'items' => [],
            ], fn ($value) => ! ($value === null || $value === ''));
        }

        if ($resolved !== []) {
            return $resolved;
        }

        return $this->defaultDemoGroupsByType($type);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function inferDemoGroupKeyFromItem(string $type, array $item): string
    {
        $groupKey = $this->normalizeDemoGroupKey((string) data_get($item, 'group_key', ''));
        if ($groupKey !== '') {
            return $groupKey;
        }

        $engine = $this->normalizeDemoEngineForType($type, (string) data_get($item, 'engine', ''));

        if ($type === 'tts') {
            return match ($engine) {
                'xtts' => 'apollo_1_0v',
                'ftts' => 'delta',
                'xomni' => 'apollo_1_5v',
                default => '',
            };
        }

        if ($type === 'ctts') {
            return match ($engine) {
                'clone_xtts' => 'vector_1_0v',
                'clone_xomni' => 'vector_1_5v',
                default => '',
            };
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function demoGroupBlueprintByKey(string $type, string $groupKey): array
    {
        $normalizedKey = $this->normalizeDemoGroupKey($groupKey);

        foreach ($this->defaultDemoGroupsByType($type) as $group) {
            if ($this->normalizeDemoGroupKey((string) data_get($group, 'key', '')) === $normalizedKey) {
                return $group;
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defaultDemoGroupsByType(string $type): array
    {
        return match ($type) {
            'tts' => [
                [
                    'key' => 'apollo_1_0v',
                    'label' => __('Apollo 1.0v'),
                    'engine' => 'xtts',
                    'description' => __('Classic Kurdish TTS voices'),
                    'limit' => 6,
                    'random' => true,
                    'samples' => [],
                    'items' => [],
                ],
                [
                    'key' => 'delta',
                    'label' => __('Delta'),
                    'engine' => 'ftts',
                    'description' => __('Fast expressive TTS voices'),
                    'limit' => 6,
                    'random' => true,
                    'samples' => [],
                    'items' => [],
                ],
                [
                    'key' => 'apollo_1_5v',
                    'label' => __('Apollo 1.5v'),
                    'engine' => 'xomni',
                    'description' => __('OmniVoice multilingual TTS'),
                    'limit' => 6,
                    'random' => true,
                    'samples' => [],
                    'items' => [],
                ],
            ],
            'ctts' => [
                [
                    'key' => 'vector_1_0v',
                    'label' => __('Vector 1.0v'),
                    'engine' => 'clone_xtts',
                    'description' => __('Classic voice cloning'),
                    'limit' => 2,
                    'random' => false,
                    'samples' => [],
                    'items' => [],
                ],
                [
                    'key' => 'vector_1_5v',
                    'label' => __('Vector 1.5v'),
                    'engine' => 'clone_xomni',
                    'description' => __('OmniVoice voice cloning'),
                    'limit' => 2,
                    'random' => false,
                    'samples' => [],
                    'items' => [],
                ],
            ],
            default => [],
        };
    }

    protected function normalizeDemoGroupKey(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->value();
    }

    protected function normalizeDemoEngineForType(string $type, string $engine): string
    {
        $value = Str::of($engine)
            ->lower()
            ->replace(['\\', '/', '.'], ['_', '_', '_'])
            ->replace('-', '_')
            ->trim()
            ->value();

        if ($value === '') {
            return '';
        }

        if ($type === 'tts') {
            return match ($value) {
                'apollo', 'apollo_classic', 'apollo_1_0v', 'tts', 'xtts' => 'xtts',
                'delta', 'ftts', 'f5tts' => 'ftts',
                'xomni', 'apollo_1_5v', 'omni', 'omnivoice' => 'xomni',
                default => $value,
            };
        }

        if ($type === 'ctts') {
            return match ($value) {
                'clone_tts', 'clone_xtts', 'vector', 'vector_classic', 'vector_1_0v', 'ctts' => 'clone_xtts',
                'clone_xomni', 'vector_1_5v', 'xomni', 'omni', 'omnivoice' => 'clone_xomni',
                default => $value,
            };
        }

        return $value;
    }

    protected function normalizeDemoTypeFromState(): void
    {
        $this->demoType = (string) ($this->demoSchema()->normalizeType($this->demoType, $this->slug) ?? '');
    }

    protected function resolvedDemoType(): string
    {
        return (string) ($this->demoSchema()->normalizeType($this->demoType, $this->slug) ?? '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defaultDemoItemsForType(string $type): array
    {
        $defaults = match ($type) {
            'translation' => [
                [
                    'title' => 'KU -> EN',
                    'source_lang' => 'ku',
                    'target_lang' => 'en',
                    'source_text' => '',
                    'target_text' => '',
                ],
                [
                    'title' => 'KU -> AR',
                    'source_lang' => 'ku',
                    'target_lang' => 'ar',
                    'source_text' => '',
                    'target_text' => '',
                ],
                [
                    'title' => 'AR -> KU',
                    'source_lang' => 'ar',
                    'target_lang' => 'ku',
                    'source_text' => '',
                    'target_text' => '',
                ],
                [
                    'title' => 'KU -> DE',
                    'source_lang' => 'ku',
                    'target_lang' => 'de',
                    'source_text' => '',
                    'target_text' => '',
                ],
            ],
            default => $this->newDemoItemPayload($type) !== [] ? [$this->newDemoItemPayload($type)] : [],
        };

        return $this->withDemoBuilderRowKeys($defaults);
    }

    /**
     * @return array<string, mixed>
     */
    protected function newDemoItemPayload(string $type): array
    {
        $payload = match ($type) {
            'tts' => [
                'label' => '',
                'group_key' => '',
                'engine' => '',
                'voice_id' => '',
                'audio_url' => '',
                'audio_upload' => null,
                'description' => '',
            ],
            'ctts' => [
                'title' => '',
                'group_key' => '',
                'engine' => '',
                'source_label' => '',
                'source_audio_url' => '',
                'source_audio_upload' => null,
                'cloned_label' => '',
                'cloned_audio_url' => '',
                'cloned_audio_upload' => null,
                'notes' => '',
            ],
            'asr' => [
                'title' => '',
                'audio_url' => '',
                'audio_upload' => null,
                'transcript' => '',
                'language' => '',
                'confidence' => '',
                'notes' => '',
            ],
            'stem' => [
                'title' => '',
                'original_audio_url' => '',
                'original_audio_upload' => null,
                'vocals_url' => '',
                'vocals_upload' => null,
                'drums_url' => '',
                'drums_upload' => null,
                'bass_url' => '',
                'bass_upload' => null,
                'other_url' => '',
                'other_upload' => null,
                'notes' => '',
            ],
            'ocr' => [
                'title' => '',
                'image_url' => '',
                'image_upload' => null,
                'extracted_text' => '',
                'notes' => '',
            ],
            'translation' => [
                'title' => '',
                'source_lang' => 'ku',
                'target_lang' => 'en',
                'source_text' => '',
                'target_text' => '',
            ],
            default => [],
        };

        return $this->ensureDemoBuilderRowKey($payload);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function inflateBuilderItemPayload(string $type, array $item): array
    {
        $row = $this->newDemoItemPayload($type);

        if ($row === []) {
            return [];
        }

        $inflated = match ($type) {
            'tts' => array_merge($row, [
                'label' => trim((string) data_get($item, 'label', '')),
                'group_key' => $this->normalizeDemoGroupKey((string) data_get($item, 'group_key', '')),
                'engine' => trim((string) data_get($item, 'engine', '')),
                'voice_id' => trim((string) data_get($item, 'voice_id', '')),
                'audio_url' => trim((string) data_get($item, 'audio', '')),
                'description' => trim((string) data_get($item, 'description', '')),
            ]),
            'ctts' => array_merge($row, [
                'title' => trim((string) data_get($item, 'title', '')),
                'group_key' => $this->normalizeDemoGroupKey((string) data_get($item, 'group_key', '')),
                'engine' => trim((string) data_get($item, 'engine', '')),
                'source_label' => trim((string) data_get($item, 'source_label', '')),
                'source_audio_url' => trim((string) data_get($item, 'source_audio', '')),
                'cloned_label' => trim((string) data_get($item, 'cloned_label', '')),
                'cloned_audio_url' => trim((string) data_get($item, 'cloned_audio', '')),
                'notes' => trim((string) data_get($item, 'notes', '')),
            ]),
            'asr' => array_merge($row, [
                'title' => trim((string) data_get($item, 'title', '')),
                'audio_url' => trim((string) data_get($item, 'audio', '')),
                'transcript' => trim((string) data_get($item, 'transcript', '')),
                'language' => trim((string) data_get($item, 'language', '')),
                'confidence' => trim((string) data_get($item, 'confidence', '')),
                'notes' => trim((string) data_get($item, 'notes', '')),
            ]),
            'stem' => array_merge($row, [
                'title' => trim((string) data_get($item, 'title', '')),
                'original_audio_url' => trim((string) data_get($item, 'original_audio', '')),
                'vocals_url' => trim((string) data_get($item, 'stems.vocals', '')),
                'drums_url' => trim((string) data_get($item, 'stems.drums', '')),
                'bass_url' => trim((string) data_get($item, 'stems.bass', '')),
                'other_url' => trim((string) data_get($item, 'stems.other', '')),
                'notes' => trim((string) data_get($item, 'notes', '')),
            ]),
            'ocr' => array_merge($row, [
                'title' => trim((string) data_get($item, 'title', '')),
                'image_url' => trim((string) data_get($item, 'image', '')),
                'extracted_text' => trim((string) data_get($item, 'extracted_text', '')),
                'notes' => trim((string) data_get($item, 'notes', '')),
            ]),
            'translation' => array_merge($row, [
                'title' => trim((string) data_get($item, 'title', '')),
                'source_lang' => Str::lower(trim((string) data_get($item, 'source_lang', 'ku'))),
                'target_lang' => Str::lower(trim((string) data_get($item, 'target_lang', 'en'))),
                'source_text' => trim((string) data_get($item, 'source_text', '')),
                'target_text' => trim((string) data_get($item, 'target_text', '')),
            ]),
            default => $row,
        };

        return $this->ensureDemoBuilderRowKey(
            $inflated,
            trim((string) data_get($item, '__row_key', ''))
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function ensureDemoBuilderRowKey(array $row, string $preferredKey = ''): array
    {
        if ($row === []) {
            return [];
        }

        $key = trim($preferredKey);
        if ($key === '') {
            $key = trim((string) data_get($row, '__row_key', ''));
        }
        if ($key === '') {
            $key = $this->newDemoBuilderRowKey();
        }

        $row['__row_key'] = $key;

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function withDemoBuilderRowKeys(array $rows): array
    {
        return array_values(array_map(
            fn ($row) => $this->ensureDemoBuilderRowKey(is_array($row) ? $row : []),
            $rows
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function stripDemoBuilderInternalKeys(array $row): array
    {
        if ($row === []) {
            return [];
        }

        unset($row['__row_key']);

        return $row;
    }

    protected function newDemoBuilderRowKey(): string
    {
        return 'demo-'.Str::lower(Str::random(14));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    protected function buildDemoItemPayloadByType(string $type, array $row, array $current, string $directory, string $disk): array
    {
        return match ($type) {
            'tts' => $this->buildTtsDemoItemPayload($row, $current, $directory, $disk),
            'ctts' => $this->buildCttsDemoItemPayload($row, $current, $directory, $disk),
            'asr' => $this->buildAsrDemoItemPayload($row, $current, $directory, $disk),
            'stem' => $this->buildStemDemoItemPayload($row, $current, $directory, $disk),
            'ocr' => $this->buildOcrDemoItemPayload($row, $current, $directory, $disk),
            'translation' => $this->buildTranslationDemoItemPayload($row),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    protected function buildTtsDemoItemPayload(array $row, array $current, string $directory, string $disk): array
    {
        $audio = $this->resolveDemoMediaValue(
            current: data_get($current, 'audio'),
            urlOrPath: trim((string) data_get($row, 'audio_url', '')),
            upload: data_get($row, 'audio_upload'),
            directory: $directory.'/audio',
            disk: $disk
        );

        $payload = [
            'label' => trim((string) data_get($row, 'label', '')),
            'group_key' => $this->normalizeDemoGroupKey((string) data_get($row, 'group_key', '')),
            'engine' => $this->normalizeDemoEngineForType('tts', (string) data_get($row, 'engine', '')),
            'voice_id' => trim((string) data_get($row, 'voice_id', '')),
            'audio' => $audio,
            'description' => trim((string) data_get($row, 'description', '')),
        ];

        $hasContent = trim((string) ($payload['label'] ?? '')) !== ''
            || trim((string) ($payload['voice_id'] ?? '')) !== ''
            || trim((string) ($payload['audio'] ?? '')) !== '';

        return $hasContent
            ? array_filter($payload, fn ($value) => ! ($value === null || $value === ''))
            : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    protected function buildCttsDemoItemPayload(array $row, array $current, string $directory, string $disk): array
    {
        $source = $this->resolveDemoMediaValue(
            current: data_get($current, 'source_audio'),
            urlOrPath: trim((string) data_get($row, 'source_audio_url', '')),
            upload: data_get($row, 'source_audio_upload'),
            directory: $directory.'/source',
            disk: $disk
        );
        $cloned = $this->resolveDemoMediaValue(
            current: data_get($current, 'cloned_audio'),
            urlOrPath: trim((string) data_get($row, 'cloned_audio_url', '')),
            upload: data_get($row, 'cloned_audio_upload'),
            directory: $directory.'/cloned',
            disk: $disk
        );

        $payload = [
            'title' => trim((string) data_get($row, 'title', '')),
            'group_key' => $this->normalizeDemoGroupKey((string) data_get($row, 'group_key', '')),
            'engine' => $this->normalizeDemoEngineForType('ctts', (string) data_get($row, 'engine', '')),
            'source_label' => trim((string) data_get($row, 'source_label', '')),
            'source_audio' => $source,
            'cloned_label' => trim((string) data_get($row, 'cloned_label', '')),
            'cloned_audio' => $cloned,
            'notes' => trim((string) data_get($row, 'notes', '')),
        ];

        $hasContent = trim((string) ($payload['title'] ?? '')) !== ''
            || trim((string) ($payload['source_label'] ?? '')) !== ''
            || trim((string) ($payload['cloned_label'] ?? '')) !== ''
            || trim((string) ($payload['source_audio'] ?? '')) !== ''
            || trim((string) ($payload['cloned_audio'] ?? '')) !== '';

        return $hasContent
            ? array_filter($payload, fn ($value) => ! ($value === null || $value === ''))
            : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    protected function buildAsrDemoItemPayload(array $row, array $current, string $directory, string $disk): array
    {
        $audio = $this->resolveDemoMediaValue(
            current: data_get($current, 'audio'),
            urlOrPath: trim((string) data_get($row, 'audio_url', '')),
            upload: data_get($row, 'audio_upload'),
            directory: $directory.'/audio',
            disk: $disk
        );

        $payload = [
            'title' => trim((string) data_get($row, 'title', '')),
            'audio' => $audio,
            'transcript' => trim((string) data_get($row, 'transcript', '')),
            'language' => Str::lower(trim((string) data_get($row, 'language', ''))),
            'confidence' => trim((string) data_get($row, 'confidence', '')),
            'notes' => trim((string) data_get($row, 'notes', '')),
        ];

        $hasContent = trim((string) ($payload['title'] ?? '')) !== ''
            || trim((string) ($payload['audio'] ?? '')) !== ''
            || trim((string) ($payload['transcript'] ?? '')) !== '';

        return $hasContent
            ? array_filter($payload, fn ($value) => ! ($value === null || $value === ''))
            : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    protected function buildStemDemoItemPayload(array $row, array $current, string $directory, string $disk): array
    {
        $original = $this->resolveDemoMediaValue(
            current: data_get($current, 'original_audio'),
            urlOrPath: trim((string) data_get($row, 'original_audio_url', '')),
            upload: data_get($row, 'original_audio_upload'),
            directory: $directory.'/mix',
            disk: $disk
        );

        $stemPayload = [];
        foreach (['vocals', 'drums', 'bass', 'other'] as $stemKey) {
            $stemPayload[$stemKey] = $this->resolveDemoMediaValue(
                current: data_get($current, "stems.{$stemKey}"),
                urlOrPath: trim((string) data_get($row, "{$stemKey}_url", '')),
                upload: data_get($row, "{$stemKey}_upload"),
                directory: $directory.'/'.$stemKey,
                disk: $disk
            );
        }
        $stemPayload = array_filter($stemPayload, fn ($value) => ! ($value === null || $value === ''));

        $payload = [
            'title' => trim((string) data_get($row, 'title', '')),
            'original_audio' => $original,
            'stems' => $stemPayload,
            'notes' => trim((string) data_get($row, 'notes', '')),
        ];

        $hasContent = trim((string) ($payload['title'] ?? '')) !== ''
            || trim((string) ($payload['original_audio'] ?? '')) !== ''
            || (is_array($payload['stems'] ?? null) && ($payload['stems'] ?? []) !== []);

        return $hasContent
            ? array_filter($payload, fn ($value) => ! ($value === null || $value === '' || $value === []))
            : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    protected function buildOcrDemoItemPayload(array $row, array $current, string $directory, string $disk): array
    {
        $image = $this->resolveDemoMediaValue(
            current: data_get($current, 'image'),
            urlOrPath: trim((string) data_get($row, 'image_url', '')),
            upload: data_get($row, 'image_upload'),
            directory: $directory.'/image',
            disk: $disk
        );

        $payload = [
            'title' => trim((string) data_get($row, 'title', '')),
            'image' => $image,
            'extracted_text' => trim((string) data_get($row, 'extracted_text', '')),
            'notes' => trim((string) data_get($row, 'notes', '')),
        ];

        $hasContent = trim((string) ($payload['title'] ?? '')) !== ''
            || trim((string) ($payload['image'] ?? '')) !== ''
            || trim((string) ($payload['extracted_text'] ?? '')) !== '';

        return $hasContent
            ? array_filter($payload, fn ($value) => ! ($value === null || $value === ''))
            : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function buildTranslationDemoItemPayload(array $row): array
    {
        $payload = [
            'title' => trim((string) data_get($row, 'title', '')),
            'source_lang' => Str::lower(trim((string) data_get($row, 'source_lang', 'ku'))),
            'target_lang' => Str::lower(trim((string) data_get($row, 'target_lang', 'en'))),
            'source_text' => trim((string) data_get($row, 'source_text', '')),
            'target_text' => trim((string) data_get($row, 'target_text', '')),
        ];

        $hasContent = trim((string) ($payload['title'] ?? '')) !== ''
            || trim((string) ($payload['source_text'] ?? '')) !== ''
            || trim((string) ($payload['target_text'] ?? '')) !== '';

        return $hasContent
            ? array_filter($payload, fn ($value) => ! ($value === null || $value === ''))
            : [];
    }

    protected function demoAssetDirectory(string $slug): string
    {
        $cleanSlug = Str::of($slug)
            ->lower()
            ->replaceMatches('/[^a-z0-9\\-]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->value();

        return 'landing/demos/'.($cleanSlug !== '' ? $cleanSlug : 'tool');
    }

    protected function resolveDemoMediaValue(mixed $current, string $urlOrPath, mixed $upload, string $directory, string $disk): ?string
    {
        if ($upload) {
            return $upload->store($directory, $disk);
        }

        $candidate = trim($urlOrPath);
        if ($candidate !== '') {
            if (Str::startsWith($candidate, ['http://', 'https://'])) {
                return $candidate;
            }

            return $this->landingMediaStorage()->normalizeStoredPath($candidate);
        }

        $currentValue = trim((string) $current);

        return $currentValue !== '' ? $currentValue : null;
    }

    protected function demoTextValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            $locale = app()->getLocale();
            $localized = trim((string) data_get($value, $locale, ''));
            if ($localized !== '') {
                return $localized;
            }

            $english = trim((string) data_get($value, 'en', ''));
            if ($english !== '') {
                return $english;
            }

            foreach ($value as $item) {
                $candidate = trim((string) $item);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
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
            $value = data_get($content, 'en.features', []);
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

    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            return in_array(Str::lower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
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
        $this->landingMediaStorage()->delete($path);
    }

    protected function toolCatalog(): LandingToolPageCatalog
    {
        return app(LandingToolPageCatalog::class);
    }

    protected function landingMediaStorage(): LandingMediaStorage
    {
        return app(LandingMediaStorage::class);
    }

    protected function demoSchema(): LandingDemoSampleSchema
    {
        return app(LandingDemoSampleSchema::class);
    }
}
