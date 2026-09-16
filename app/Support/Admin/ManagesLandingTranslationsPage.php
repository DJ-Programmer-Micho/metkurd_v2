<?php

namespace App\Support\Admin;

use App\Support\Landing\LandingTranslationManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesLandingTranslationsPage
{
    use SecureAdminComponent;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'section', keep: true)]
    public string $sectionFilter = 'all';

    /**
     * @var array<string, array{key:string,section:string,default:string,en:string,ar:string,ku:string}>
     */
    public array $catalog = [];

    /**
     * @var array<string, array{key:string,en:string,ar:string,ku:string}>
     */
    public array $translations = [];

    public function mount(): void
    {
        $this->reloadTranslationCatalog();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->sectionFilter = 'all';
    }

    public function saveTranslations(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $rules = [];

        foreach ($this->translations as $id => $row) {
            foreach (['en', 'ar', 'ku'] as $locale) {
                $rules["translations.{$id}.{$locale}"] = ['nullable', 'string', 'max:8000'];
            }
        }

        $this->validate($rules);

        $payload = [];

        foreach ($this->translations as $row) {
            $key = (string) ($row['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $payload[$key] = [
                'en' => (string) ($row['en'] ?? ''),
                'ar' => (string) ($row['ar'] ?? ''),
                'ku' => (string) ($row['ku'] ?? ''),
            ];
        }

        $this->translationManager()->saveEditableEntries($payload);
        app(\App\Services\Admin\AdminAudit::class)->record('landing.translations.saved', 'landing_translations', 'en-ar-ku', requested: ['keys' => array_keys($payload)]);
        $this->reloadTranslationCatalog();

        $this->dispatch('alert', type: 'success', message: __('Landing translations saved successfully.'));
    }

    #[Computed]
    public function sectionOptions(): array
    {
        $sections = collect($this->catalog)
            ->pluck('section')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $ordered = [];
        $known = $this->sectionLabelMap();

        foreach (array_keys($known) as $sectionKey) {
            if (in_array($sectionKey, $sections, true)) {
                $ordered[] = $sectionKey;
            }
        }

        $remaining = array_values(array_diff($sections, $ordered));
        sort($remaining, SORT_NATURAL);

        return array_values(array_merge($ordered, $remaining));
    }

    #[Computed]
    public function sectionLabels(): array
    {
        return $this->sectionLabelMap();
    }

    public function sectionLabel(string $section): string
    {
        $labels = $this->sectionLabelMap();

        if (isset($labels[$section])) {
            return $labels[$section];
        }

        return ucfirst(str_replace('_', ' ', $section));
    }

    #[Computed]
    public function filteredRows(): array
    {
        $needle = mb_strtolower(trim($this->search));

        return collect($this->catalog)
            ->filter(function (array $row) use ($needle) {
                if ($this->sectionFilter !== 'all' && $row['section'] !== $this->sectionFilter) {
                    return false;
                }

                if ($needle === '') {
                    return true;
                }

                $haystack = implode(' ', [
                    (string) ($row['key'] ?? ''),
                    $row['section'],
                    (string) ($this->translations[$row['id']]['en'] ?? ''),
                    (string) ($this->translations[$row['id']]['ar'] ?? ''),
                    (string) ($this->translations[$row['id']]['ku'] ?? ''),
                ]);

                return mb_stripos($haystack, $needle) !== false;
            })
            ->sortBy('key', SORT_NATURAL)
            ->values()
            ->all();
    }

    protected function reloadTranslationCatalog(): void
    {
        $entries = $this->translationManager()->editableEntries();

        $this->catalog = collect($entries)
            ->mapWithKeys(function (array $row, string $key) {
                $id = $this->rowId($key);

                return [
                    $id => [
                        'id' => $id,
                        'key' => $key,
                        'section' => (string) ($row['section'] ?? 'general'),
                        'default' => (string) ($row['default'] ?? ''),
                        'en' => (string) ($row['en'] ?? ''),
                        'ar' => (string) ($row['ar'] ?? ''),
                        'ku' => (string) ($row['ku'] ?? ''),
                    ],
                ];
            })
            ->all();

        $this->translations = collect($this->catalog)
            ->mapWithKeys(fn (array $row, string $id) => [
                $id => [
                    'key' => (string) ($row['key'] ?? ''),
                    'en' => (string) ($row['en'] ?? ''),
                    'ar' => (string) ($row['ar'] ?? ''),
                    'ku' => (string) ($row['ku'] ?? ''),
                ],
            ])
            ->all();
    }

    protected function rowId(string $key): string
    {
        return 'k_'.substr(sha1($key), 0, 20);
    }

    protected function translationManager(): LandingTranslationManager
    {
        return app(LandingTranslationManager::class);
    }

    /**
     * @return array<string, string>
     */
    protected function sectionLabelMap(): array
    {
        return [
            'general_brand' => 'General Brand',
            'homepage' => 'Homepage',
            'tools' => 'Tools',
            'faq' => 'FAQ',
            'pricing' => 'Pricing',
            'trust_privacy' => 'Trust & Privacy',
            'voice_cloning_safety' => 'Voice Cloning Safety',
            'research_development' => 'Research & Development',
            'kurdish_ai_challenges' => 'Kurdish AI Challenges',
            'how_built' => 'How MetKurd AI Was Built',
            'overview' => 'MetKurd AI Overview',
            'seo_metadata' => 'SEO Metadata',
            'schema_content' => 'Schema Content',
            'breadcrumbs' => 'Breadcrumbs',
            'buttons_cta' => 'Buttons / CTA',
            'contact' => 'Contact',
        ];
    }
}
