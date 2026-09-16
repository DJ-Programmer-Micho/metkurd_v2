<?php

namespace App\Services\CustomerApi\V2;

use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Services\CustomerApi\CustomerApiAccessService;

class ApiCatalog
{
    public const SERVICES = ['speech', 'voice-clone', 'transcriptions', 'captions', 'ocr', 'stem'];

    /** Project the web catalog through the same API definition used for submissions. */
    public function variants(): array
    {
        $variants = [];
        foreach (app(\App\Support\MetKurdV2ToolCatalog::class)->services() as $webService => $family) {
            foreach ($family['tools'] ?? [] as $slug => $tool) {
                if ($tool['coming_soon'] ?? false) {
                    continue;
                }
                $input = ['model' => ($tool['provider_model'] ?? '') === 'model_1' ? '1.5' : '2.0'];
                if (isset($tool['stems'])) {
                    $input['mode'] = (string) $tool['stems'];
                }
                foreach (self::SERVICES as $service) {
                    try {
                        $definition = $this->definition($service, $input);
                    } catch (ApiProblem) {
                        continue;
                    }
                    if ($definition[0] === $webService && $definition[1] === $slug) {
                        $variants[] = ['service' => $service, 'scope' => 'v2:'.$service,
                            'web_service' => $webService, 'slug' => $slug, 'action' => $definition[2],
                            'input' => $input, 'tool' => $tool];
                    }
                }
            }
        }

        return $variants;
    }

    public function scopeForAction(string $action): ?string
    {
        foreach ($this->variants() as $variant) {
            if ($variant['action'] === $action) {
                return $variant['scope'];
            }
        }

        return null;
    }

    public function definition(string $service, array $input): array
    {
        if (isset($input['model']) && ! is_scalar($input['model']) || isset($input['mode']) && ! is_scalar($input['mode'])) {
            throw new ApiProblem('invalid_request');
        }
        $model = (string) ($input['model'] ?? '2.0');
        if (in_array($service, ['speech', 'voice-clone'], true) && ! in_array($model, ['1.5', '2.0'], true)) {
            throw new ApiProblem('invalid_request');
        }
        $v = $model === '1.5' ? '1' : '2';

        return match ($service) {
            'speech' => ['text-to-speech', 'apollo-'.$v, $v === '1' ? 'xomni.generate' : 'xomni-v2.generate'],
            'voice-clone' => ['clone-text-to-speech', 'vector-'.$v, $v === '1' ? 'clone_xomni.generate' : 'vector-v2.generate'],
            'transcriptions' => ['speech-to-text', 'leo', 'leo.transcribe'],
            'captions' => ['speech-to-text', 'caption', 'caption.standard'],
            'ocr' => ['ocr', 'scanner', 'ocr.standard'],
            'stem' => match ((string) ($input['mode'] ?? '')) {
                '2' => ['stem', '2-stem', 'stem.sep2'], '4' => ['stem', '4-stem', 'stem.sep4'],
                default => throw new ApiProblem('invalid_request'),
            },
            default => throw new ApiProblem('invalid_request'),
        };
    }

    public function hasAccess(Customer $customer): bool
    {
        $config = app(CustomerApiAccessService::class)->configForCustomer($customer);

        return (int) $customer->status === 1 && $config['api_enabled'] && $config['requests_per_minute'] > 0;
    }

    public function scopes(Customer $customer): array
    {
        if (! $this->hasAccess($customer)) {
            return [];
        }

        return $this->scopesForConfiguration(app(CustomerApiAccessService::class)->allowedTools($customer));
    }

    public function keyAccessMessage(Customer $customer): ?string
    {
        if (! $this->hasAccess($customer)) {
            return __('API access is not available on your current plan.');
        }

        return $this->scopes($customer) === [] ? __('account_v2.api_scopes_missing') : null;
    }

    public function scopesForConfiguration(array $configured): array
    {
        $scopes = [];
        foreach (self::SERVICES as $service) {
            $family = match ($service) {
                'speech', 'voice-clone' => 'tts', 'transcriptions' => 'asr', 'captions' => 'caption', default => $service
            };
            if (array_intersect(['*', 'v2:*', 'v2:'.$service, $family.':*'], $configured)) {
                $scopes[] = 'v2:'.$service;
            }
        }

        return $scopes ? [...$scopes, 'v2:jobs:read', 'v2:files:download'] : [];
    }

    public function authorize(Customer $customer, CustomerApiKey $key, string $scope, ?string $action = null): void
    {
        $allowed = $this->scopes($customer);
        if (! in_array($scope, $allowed, true) || ! array_intersect(['*', 'v2:*', $scope], $key->scopes ?? [])
            || ($action && ! $customer->isAllowed($action, 'api'))) {
            throw new ApiProblem('permission_denied', 403);
        }
    }
}
