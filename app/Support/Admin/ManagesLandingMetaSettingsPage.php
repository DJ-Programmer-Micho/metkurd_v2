<?php

namespace App\Support\Admin;

use App\Support\Landing\SiteMetaSettingsRepository;
use Illuminate\Validation\ValidationException;

trait ManagesLandingMetaSettingsPage
{
    use SecureAdminComponent;

    public string $defaultMetaTitle = '';

    public string $defaultMetaDescription = '';

    public string $defaultOgTitle = '';

    public string $defaultOgDescription = '';

    public string $defaultTwitterTitle = '';

    public string $defaultTwitterDescription = '';

    public ?string $faviconPath = null;

    public ?string $appIcon192Path = null;

    public ?string $appIcon512Path = null;

    public ?string $appleTouchIconPath = null;

    public ?string $ogImagePath = null;

    public ?string $twitterImagePath = null;

    public $faviconUpload = null;

    public $appIcon192Upload = null;

    public $appIcon512Upload = null;

    public $appleTouchIconUpload = null;

    public $ogImageUpload = null;

    public $twitterImageUpload = null;

    public function mount(): void
    {
        $this->reloadMetaSettings();
    }

    public function saveMetaSettings(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $this->validate([
            'defaultMetaTitle' => ['nullable', 'string', 'max:180'],
            'defaultMetaDescription' => ['nullable', 'string', 'max:320'],
            'defaultOgTitle' => ['nullable', 'string', 'max:180'],
            'defaultOgDescription' => ['nullable', 'string', 'max:320'],
            'defaultTwitterTitle' => ['nullable', 'string', 'max:180'],
            'defaultTwitterDescription' => ['nullable', 'string', 'max:320'],
            'faviconUpload' => ['nullable', 'file', 'mimes:ico,png,webp,svg,jpg,jpeg', 'max:2048'],
            'appIcon192Upload' => ['nullable', 'image', 'max:2048'],
            'appIcon512Upload' => ['nullable', 'image', 'max:2048'],
            'appleTouchIconUpload' => ['nullable', 'image', 'max:2048'],
            'ogImageUpload' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'twitterImageUpload' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $this->validateSocialPreviewUpload('ogImageUpload', __('Open Graph image'));
        $this->validateSocialPreviewUpload('twitterImageUpload', __('Twitter image'));

        $repository = $this->metaRepository();

        $repository->save([
            'default_meta_title' => $this->defaultMetaTitle,
            'default_meta_description' => $this->defaultMetaDescription,
            'default_og_title' => $this->defaultOgTitle,
            'default_og_description' => $this->defaultOgDescription,
            'default_twitter_title' => $this->defaultTwitterTitle,
            'default_twitter_description' => $this->defaultTwitterDescription,
            'favicon_upload' => $this->faviconUpload,
            'app_icon_192_upload' => $this->appIcon192Upload,
            'app_icon_512_upload' => $this->appIcon512Upload,
            'apple_touch_icon_upload' => $this->appleTouchIconUpload,
            'og_image_upload' => $this->ogImageUpload,
            'twitter_image_upload' => $this->twitterImageUpload,
        ]);
        $repository->refreshRuntimeCache();

        $this->reloadMetaSettings();
        $this->dispatch('alert', type: 'success', message: __('Global meta settings saved successfully.'));
    }

    protected function reloadMetaSettings(): void
    {
        $settings = $this->metaRepository()->adminSettings();

        $this->defaultMetaTitle = (string) ($settings['default_meta_title'] ?? '');
        $this->defaultMetaDescription = (string) ($settings['default_meta_description'] ?? '');
        $this->defaultOgTitle = (string) ($settings['default_og_title'] ?? '');
        $this->defaultOgDescription = (string) ($settings['default_og_description'] ?? '');
        $this->defaultTwitterTitle = (string) ($settings['default_twitter_title'] ?? '');
        $this->defaultTwitterDescription = (string) ($settings['default_twitter_description'] ?? '');

        $this->faviconPath = $settings['favicon_path'] ?? null;
        $this->appIcon192Path = $settings['app_icon_192_path'] ?? null;
        $this->appIcon512Path = $settings['app_icon_512_path'] ?? null;
        $this->appleTouchIconPath = $settings['apple_touch_icon_path'] ?? null;
        $this->ogImagePath = $settings['og_image_path'] ?? null;
        $this->twitterImagePath = $settings['twitter_image_path'] ?? null;

        $this->faviconUpload = null;
        $this->appIcon192Upload = null;
        $this->appIcon512Upload = null;
        $this->appleTouchIconUpload = null;
        $this->ogImageUpload = null;
        $this->twitterImageUpload = null;
    }

    protected function metaAssetUrl(?string $path): ?string
    {
        return $this->metaRepository()->publicUrl($path);
    }

    protected function metaRepository(): SiteMetaSettingsRepository
    {
        return app(SiteMetaSettingsRepository::class);
    }

    protected function validateSocialPreviewUpload(string $field, string $label): void
    {
        $upload = $this->{$field} ?? null;

        if (! is_object($upload)) {
            return;
        }

        $imageSize = null;
        if (method_exists($upload, 'dimensions')) {
            $imageSize = $upload->dimensions();
        } elseif (method_exists($upload, 'getRealPath')) {
            $realPath = $upload->getRealPath();
            if (is_string($realPath) && trim($realPath) !== '') {
                $imageSize = @getimagesize($realPath);
            }
        }

        if (! is_array($imageSize)) {
            throw ValidationException::withMessages([
                $field => __(
                    ':label could not be processed. Please re-upload a PNG, JPG, or WEBP image.',
                    ['label' => $label]
                ),
            ]);
        }

        $width = (int) ($imageSize[0] ?? 0);
        $height = (int) ($imageSize[1] ?? 0);

        if ($width < 600 || $height < 315) {
            throw ValidationException::withMessages([
                $field => __(
                    ':label is too small. Use at least 600x315 (1200x630 recommended).',
                    ['label' => $label]
                ),
            ]);
        }

        $ratio = $height > 0 ? ($width / $height) : 0.0;

        if ($width <= $height || $ratio < 1.4 || $ratio > 2.2) {
            throw ValidationException::withMessages([
                $field => __(
                    ':label must be a wide social image. Use around 1200x630 (1.91:1).',
                    ['label' => $label]
                ),
            ]);
        }

        if (($width <= 512 && $height <= 512) || abs($ratio - 1.0) <= 0.1) {
            throw ValidationException::withMessages([
                $field => __(
                    ':label looks like an icon. Upload a social preview image (1200x630 recommended).',
                    ['label' => $label]
                ),
            ]);
        }
    }
}
