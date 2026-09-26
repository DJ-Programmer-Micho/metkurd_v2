<?php

namespace App\Services\Mcp\OAuth;

use Laravel\Passport\Client;
use Laravel\Passport\Passport;

class ClientRepository extends \Laravel\Passport\ClientRepository
{
    public function find(string|int $id): ?Client
    {
        // Metadata may change during consent/token handling. Never reuse Passport's
        // once() snapshot across that update or a long-lived application request.
        return Passport::client()->newQuery()->find($id);
    }
}
