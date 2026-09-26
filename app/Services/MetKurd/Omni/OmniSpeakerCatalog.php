<?php

namespace App\Services\MetKurd\Omni;

use App\Models\Customer;
use App\Models\Voice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Resolves the existing OMNI Voice/plan-access catalogue for any UI surface. */
class OmniSpeakerCatalog
{
    /** @return array<string, array{label:string,speakers:array<int,array<string,mixed>}> */
    public function forCustomer(?Customer $customer, string $locale): array
    {
        $planId = $this->planId($customer);
        if ($planId <= 0) {
            return [];
        }

        return Cache::remember(
            $this->cacheKey($planId, $locale),
            now()->addSeconds(max(60, (int) config('metkurd_v2.cache.speaker_catalog_ttl_seconds', 600))),
            fn (): array => $this->resolveForPlan($planId, $locale),
        );
    }

    public function forgetPlan(int $planId, string $locale): void
    {
        Cache::forget($this->cacheKey($planId, $locale));
    }

    public function cacheKey(int $planId, string $locale): string
    {
        $version = max(1, (int) Cache::get('omni-speaker-catalog:version', 1));

        return "metkurd:v2:omni-speakers:plan:{$planId}:locale:".strtolower($locale).":v{$version}";
    }

    /** @return array<string, array{label:string,speakers:array<int,array<string,mixed>}> */
    private function resolveForPlan(int $planId, string $locale): array
    {
        $groups = collect($this->groupOrder())
            ->mapWithKeys(fn (string $label, string $key): array => [$key => ['label' => $label, 'speakers' => []]])
            ->all();

        Voice::query()
            ->leftJoin('plan_voice_access as pva', function ($join) use ($planId): void {
                $join->on('pva.voice_id', '=', 'voices.id')
                    ->where('pva.service_plan_id', '=', $planId);
            })
            ->where('voices.meta->engine', 'xomni')
            ->where('voices.is_active', true)
            ->where(fn ($query) => $query->where('voices.is_public', true)->orWhere('pva.is_active', true))
            ->orderBy('voices.sort_order')
            ->orderBy('voices.id')
            ->get(['voices.code', 'voices.name', 'voices.meta'])
            ->each(function (Voice $voice) use (&$groups, $locale): void {
                $meta = (array) $voice->meta;
                $reference = $this->referencePath((string) data_get($meta, 'ref_audio', data_get($meta, 'runpod_ref_audio', '')));
                // Keep discovery aligned with Zeta's existing reference safety boundary.
                if ($reference === '' || str_contains($reference, '..') || preg_match('~^(?:/|[a-z]+:)~i', $reference) || str_contains($reference, "\0")) {
                    return;
                }

                $group = $this->groupFor((string) $voice->code, (string) $voice->name, $meta);
                $groups[$group]['speakers'][] = [
                    'code' => (string) $voice->code,
                    'name' => $this->displayName((string) $voice->code, (string) $voice->name),
                    'subtitle' => $this->subtitle($meta),
                    'style' => $this->styleLabel($meta),
                    'ref_audio' => $reference,
                    'ref_text' => (string) data_get($meta, 'ref_text', ''),
                    'avatar_url' => trim((string) data_get($meta, 'avatar_path', data_get($meta, 'avatar', ''))) !== ''
                        ? route('app.xomni.speaker.avatar', ['locale' => $locale, 'voiceCode' => $voice->code])
                        : null,
                    'preview_url' => trim((string) data_get($meta, 'preview_audio', data_get($meta, 'preview_audio_path', ''))) !== ''
                        ? route('app.xomni.speaker.preview', ['locale' => $locale, 'voiceCode' => $voice->code])
                        : null,
                    'initials' => $this->initials((string) $voice->name),
                ];
            });

        return collect($groups)
            ->filter(fn (array $group): bool => $group['speakers'] !== [])
            ->all();
    }

    private function planId(?Customer $customer): int
    {
        if (! $customer) {
            return 0;
        }

        $planId = method_exists($customer, 'currentServicePlanId')
            ? (int) ($customer->currentServicePlanId() ?? 0)
            : 0;

        return $planId > 0 ? $planId : (int) ($customer->service_plan_id ?? 0);
    }

    /** @return array<string,string> */
    private function groupOrder(): array
    {
        return [
            'male_1' => __('Male 1'), 'male_2' => __('Male 2'), 'male_3' => __('Male 3'),
            'female_1' => __('Female 1'), 'female_2' => __('Female 2'), 'female_3' => __('Female 3'),
            'custom' => __('Custom'),
        ];
    }

    private function groupFor(string $code, string $name, array $meta): string
    {
        $group = Str::of((string) data_get($meta, 'speaker_group'))->lower()->trim()->replace(['-', ' '], '_')->value();
        if (array_key_exists($group, $this->groupOrder())) {
            return $group;
        }

        $gender = Str::lower(trim((string) data_get($meta, 'gender')));
        if (in_array($gender, ['male', 'female', 'custom'], true)) {
            return $gender === 'custom' ? 'custom' : "{$gender}_1";
        }

        $value = Str::of("{$code} {$name}")->lower();
        foreach (['female 3' => 'female_3', 'liza' => 'female_2', 'patty' => 'female_1', 'male 3' => 'male_3', 'shabo' => 'male_2', 'hyder' => 'male_1', 'male' => 'male_1', 'female' => 'female_1'] as $needle => $resolved) {
            if ($value->contains($needle)) {
                return $resolved;
            }
        }

        return 'custom';
    }

    private function referencePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('~^(?:.*/)?(?:runpod-volume/)?ref_voices/~i', '', $path);

        return trim((string) $path);
    }

    private function displayName(string $code, string $name): string
    {
        $name = trim($name);

        return $name === '' || strcasecmp($name, $code) === 0 ? __('Voice') : $name;
    }

    private function subtitle(array $meta): ?string
    {
        foreach (['subtitle', 'style', 'accent', 'tone', 'description'] as $key) {
            $value = trim((string) data_get($meta, $key));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function styleLabel(array $meta): ?string
    {
        $style = trim((string) data_get($meta, 'style'));

        return $style === '' ? null : Str::headline($style);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Str::upper(Str::substr((string) ($parts[0] ?? 'V'), 0, 1).Str::substr((string) ($parts[1] ?? ''), 0, 1));
    }
}
