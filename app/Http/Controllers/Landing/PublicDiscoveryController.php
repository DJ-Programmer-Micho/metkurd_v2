<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Support\Landing\PublicProductCatalog;
use App\Support\Landing\PublicWebsiteContent;

class PublicDiscoveryController extends Controller
{
    public const ROUTES = ['landing.home', 'landing.tools', 'landing.pricing', 'landing.contact',
        'landing.overview', 'landing.research-development', 'landing.kurdish-ai-challenges',
        'landing.how-built', 'landing.privacy', 'landing.terms'];

    public function sitemap(PublicProductCatalog $catalog)
    {
        $entries = [];
        foreach (['en', 'ar', 'ku'] as $locale) {
            foreach (self::ROUTES as $name) {
                $entries[] = $this->publicUrl($name, ['locale' => $locale]);
            }
            foreach (array_unique(array_column($catalog->products(), 'family')) as $slug) {
                $entries[] = $this->publicUrl('landing.tools.show', ['locale' => $locale, 'slug' => $slug]);
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($entries as $url) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return response($xml.'</urlset>')->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function llms(PublicProductCatalog $catalog, PublicWebsiteContent $copy)
    {
        $lines = ['# MetKurd AI', '', $copy->entity('en'), '', '## Current active products'];
        foreach ($catalog->products() as $product) {
            $lines[] = '- '.$product['name'].': '.$copy->text('products.'.$product['key'], [], 'en');
        }
        $lines = array_merge($lines, ['', '## Languages and limitations',
            'Primary focus: Sorani (Central Kurdish), Arabic script. Kurmanji is not currently supported.',
            'Arabic and English support depends on the selected service.',
            'AI outputs need human review. Availability and access depend on active tools, plan permissions and credits.',
            '', '## Developer access', $copy->text($catalog->apiEnabled() ? 'api_available' : 'api_unavailable', [], 'en')]);
        if ($catalog->mcpEnabled()) {
            $lines[] = $copy->text('mcp_available', [], 'en');
        }
        $lines = array_merge($lines, ['', '## Terminology',
            $copy->text('home_faq.terminology.copy', [], 'en'),
            'Source: '.PublicWebsiteContent::TERMINOLOGY_SOURCE_URL,
            '', '## Privacy', $copy->text('privacy', [], 'en'), '', '## Public pages']);
        foreach (self::ROUTES as $route) {
            $lines[] = '- '.$this->publicUrl($route, ['locale' => 'en']);
        }
        foreach (array_unique(array_column($catalog->products(), 'family')) as $slug) {
            $lines[] = '- '.$this->publicUrl('landing.tools.show', ['locale' => 'en', 'slug' => $slug]);
        }
        $lines[] = 'Localized public pages are also available under /ar and /ku.';

        return response(implode("\n", $lines)."\n")->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function publicUrl(string $route, array $parameters): string
    {
        return \App\Support\Landing\PublicSiteUrl::route($route, $parameters);
    }
}
