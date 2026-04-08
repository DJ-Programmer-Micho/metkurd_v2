<?php

namespace App\Contracts\Payments;

interface AreebaGatewayInterface
{
    /**
     * @return array<int, string>
     */
    public function configurationIssues(): array;
}
