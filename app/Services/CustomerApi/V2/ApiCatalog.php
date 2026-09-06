<?php

namespace App\Services\CustomerApi\V2;

use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Services\CustomerApi\CustomerApiAccessService;

class ApiCatalog
{
    public const SERVICES = ['speech', 'voice-clone', 'transcriptions', 'captions', 'ocr', 'stem'];

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
        $configured = app(CustomerApiAccessService::class)->allowedTools($customer);
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
