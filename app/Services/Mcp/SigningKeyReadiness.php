<?php

namespace App\Services\Mcp;

use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;

/** Local, read-only diagnostics. Never return PEM, paths or exception messages. */
class SigningKeyReadiness
{
    public function inspect(): array
    {
        $private = $this->inspectKey('private');
        $public = $this->inspectKey('public');
        $matches = $private['status'] === 'ready' && $public['status'] === 'ready'
            ? hash_equals($private['public_key_sha256'], $public['public_key_sha256']) : null;
        $server = 'not_checked';
        if ($matches === true) {
            try {
                app(AuthorizationServer::class);
                $server = 'ready';
            } catch (\Throwable) {
                $server = 'unavailable';
            }
        }

        return ['check' => 'oauth_signing_keys', 'scope' => 'current_process_only',
            'private_key' => $private, 'public_key' => $public, 'pair_matches' => $matches,
            'authorization_server' => $server, 'ready' => $matches === true && $server === 'ready'];
    }

    private function inspectKey(string $type): array
    {
        $configured = config("passport.{$type}_key");
        $result = ['source' => $configured ? 'configuration' : 'passport_file',
            'status' => 'missing_unreadable_or_invalid', 'public_key_sha256' => null];
        try {
            // Match Passport's config precedence and escaped-newline handling.
            // CryptKey accepts PEM or local files; it does not fetch remote URLs.
            $key = str_replace('\\n', "\n", $configured ?? '');
            $key = $key ?: 'file://'.Passport::keyPath('oauth-'.$type.'.key');
            $contents = (new CryptKey($key, null, false))->getKeyContents();
            $parsed = $type === 'private' ? @openssl_pkey_get_private($contents) : @openssl_pkey_get_public($contents);
            $details = $parsed === false ? false : openssl_pkey_get_details($parsed);
            // MCP verifies RS256: an otherwise parseable EC key is not ready.
            if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
                return $result;
            }
            $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']), true);
            if ($der === false) {
                return $result;
            }
            $result['status'] = 'ready';
            // SHA-256 of DER SubjectPublicKeyInfo, including for the private key.
            $result['public_key_sha256'] = hash('sha256', $der);
        } catch (\Throwable) {
            // File paths and parser exception text can contain sensitive material.
        }

        return $result;
    }
}
