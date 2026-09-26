<?php

namespace App\Services\Mcp;

use Mcp\Server\Session\Psr16SessionStore;
use Symfony\Component\Uid\Uuid;

/** Keep SDK handshake state; never retain arbitrary request metadata in shared cache. */
class PrivateSessionStore extends Psr16SessionStore
{
    public function write(Uuid $id, string $data): bool
    {
        $state = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        // No sampling/elicitation/outbound RPC is exposed. Keep the SDK's outgoing
        // response queue until delivery; removing it would break legacy handshakes.
        \Illuminate\Support\Arr::forget($state, [\Mcp\Server\Protocol::SESSION_ACTIVE_REQUEST_META, '_mcp.responses']);

        return parent::write($id, \Illuminate\Support\Facades\Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)));
    }

    public function read(Uuid $id): string|false
    {
        $data = parent::read($id);
        if ($data === false) {
            return false;
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($data);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return false;
        }
    }
}
