<?php

namespace App\Services\Payments\Areeba;

use App\Contracts\Payments\AreebaGatewayInterface;

class AreebaHttpGateway implements AreebaGatewayInterface
{
    public function configurationIssues(): array
    {
        $issues = [];

        foreach ([
            'base_url' => 'Base URL',
            'api_key' => 'API Key',
            'username' => 'Username',
            'password' => 'Password',
        ] as $key => $label) {
            $value = config("areeba.{$key}");

            if (! is_scalar($value) || trim((string) $value) === '') {
                $issues[] = __('Missing :value', ['value' => $label]);
            }
        }

        return $issues;
    }
}
