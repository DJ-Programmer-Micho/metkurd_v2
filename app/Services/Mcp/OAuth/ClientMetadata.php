<?php

namespace App\Services\Mcp\OAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

/** Bounded, public-only CIMD discovery; no credentials, logos or linked documents fetched. */
class ClientMetadata
{
    public function fetch(string $id): array
    {
        $parts = parse_url($id);
        $path = rawurldecode($parts['path'] ?? '');
        if (strlen($id) > 512 || preg_match('/[^\x21-\x7e]/', $id) || ! RedirectPolicy::allows($id)
            || $path === '' || isset($parts['query']) || preg_match('~(^|/)[.]{1,2}(/|$)~', $path)) {
            throw new \UnexpectedValueException('Invalid client metadata');
        }
        $cache = Cache::store(config('mcp.session_store'));
        $key = 'mcp:cimd:'.hash('sha256', $id);
        if ($cached = $cache->get($key)) {
            return $cached;
        }
        $host = $parts['host'];
        $port = $parts['port'] ?? 443;
        $ip = app(PublicMetadataDns::class)->resolve($host);
        // Pin validated DNS to this connection; disable proxy environment and redirects.
        // CURL is required so another transport cannot silently ignore DNS pinning.
        if (! extension_loaded('curl')) {
            throw new \UnexpectedValueException('Invalid client metadata');
        }
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(5)->withOptions([
                'allow_redirects' => false, 'proxy' => '', 'verify' => true, 'decode_content' => false,
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]],
                'on_headers' => function ($response) {
                    if ((int) $response->getHeaderLine('Content-Length') > 5120) {
                        throw new \UnexpectedValueException('Invalid client metadata');
                    }
                },
                'progress' => function ($total, $downloaded) {
                    if ($downloaded > 5120) {
                        throw new \UnexpectedValueException('Invalid client metadata');
                    }
                },
            ])->get($id);
            $body = $response->body();
            if ($response->status() !== 200 || strlen($body) > 5120
                || strtolower(trim(explode(';', $response->header('Content-Type'))[0])) !== 'application/json'
                || ! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true)) {
                throw new \UnexpectedValueException('Invalid client metadata');
            }
            $metadata = $this->validate($body, $id);
            $control = strtolower($response->header('Cache-Control'));
            $ttl = 0;
            if (! preg_match('/no-store|no-cache|private/', $control) && preg_match('/(?:^|,)\s*max-age=(\d+)/', $control, $match)) {
                $ttl = max(0, min(300, (int) $match[1] - max(0, (int) $response->header('Age'))));
            }
            if ($ttl > 0) {
                $cache->put($key, $metadata, $ttl);
            }

            return $metadata;
        } catch (\Throwable) {
            // Never expose remote bodies, network addresses or metadata URLs in errors.
            throw new \UnexpectedValueException('Invalid client metadata');
        }
    }

    public function validate(string $json, string $id): array
    {
        $object = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        if (! $object instanceof \stdClass) {
            throw new \UnexpectedValueException('Invalid client metadata');
        }
        // PHP accepts duplicate members. Check decoded key identities at each object
        // depth after the native JSON parser validates syntax (including escaped keys).
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $i => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($matches[0][$i + 1] ?? null) === ':') {
                $key = json_decode($token, true, 16, JSON_THROW_ON_ERROR);
                $depth = count($stack) - 1;
                if (isset($stack[$depth][$key])) {
                    throw new \UnexpectedValueException('Invalid client metadata');
                }
                $stack[$depth][$key] = true;
            }
        }
        $data = (array) $object;
        foreach (['client_id', 'client_name', 'scope', 'application_type', 'token_endpoint_auth_method', 'jwks_uri'] as $field) {
            if (array_key_exists($field, $data) && ! is_string($data[$field])) {
                throw new \UnexpectedValueException('Invalid client metadata');
            }
        }
        foreach (['redirect_uris', 'grant_types', 'response_types', 'token_endpoint_auth_methods_supported'] as $field) {
            if (array_key_exists($field, $data) && (! is_array($data[$field]) || ! array_is_list($data[$field])
                || ! $data[$field] || count(array_filter($data[$field], 'is_string')) !== count($data[$field]))) {
                throw new \UnexpectedValueException('Invalid client metadata');
            }
        }
        if (isset($data['token_endpoint_auth_method']) && ! in_array($data['token_endpoint_auth_method'], ['none', 'private_key_jwt'], true)) {
            throw new \UnexpectedValueException('Invalid client metadata');
        }
        $redirects = $data['redirect_uris'] ?? null;
        // application_type is optional OAuth metadata. An exclusively loopback
        // callback set identifies a native client; an explicit web type never relaxes.
        $nativeOnly = is_array($redirects) && $redirects !== [];
        foreach (is_array($redirects) ? $redirects : [] as $uri) {
            $nativeOnly = $nativeOnly && is_string($uri) && str_starts_with($uri, 'http://') && RedirectPolicy::allows($uri, 'native');
        }
        $type = $data['application_type'] ?? ($nativeOnly ? 'native' : 'web');
        // SEP-3149 transition: an explicit supported-methods list takes precedence;
        // select only our advertised method (none), never downgrade a singular private_key_jwt client.
        $methods = $data['token_endpoint_auth_methods_supported'] ?? [$data['token_endpoint_auth_method'] ?? 'none'];
        $grants = $data['grant_types'] ?? ['authorization_code'];
        $responses = $data['response_types'] ?? ['code'];
        if (($data['client_id'] ?? null) !== $id || ! is_string($data['client_name'] ?? null)
            || trim($data['client_name']) === '' || mb_strlen($data['client_name']) > 200
            || ! in_array($type, ['web', 'native'], true) || ! is_array($redirects) || ! array_is_list($redirects)
            || ! $redirects || count($redirects) > 20 || ! is_array($methods) || ! array_is_list($methods)
            || ! in_array('none', $methods, true) || array_diff($methods, ['none', 'private_key_jwt'])
            || ! is_array($grants) || ! array_is_list($grants) || ! in_array('authorization_code', $grants, true)
            || array_diff($grants, ['authorization_code', 'refresh_token']) || $responses !== ['code']
            || array_intersect(['client_secret', 'client_secret_expires_at'], array_keys($data))) {
            throw new \UnexpectedValueException('Invalid client metadata');
        }
        foreach ($redirects as $uri) {
            if (! is_string($uri) || strlen($uri) > 2048 || ! RedirectPolicy::allows($uri, $type)) {
                throw new \UnexpectedValueException('Invalid client metadata');
            }
        }
        if (isset($data['scope']) && (! is_string($data['scope']) || array_diff(preg_split('/\s+/', trim($data['scope'])), array_keys(Passport::$scopes)))) {
            throw new \UnexpectedValueException('Invalid client metadata');
        }
        // No key-based authentication is used. Reject any embedded key material;
        // public jwks_uri is inert metadata, never fetched or treated as authentication.
        if (array_key_exists('jwks', $data)) {
            throw new \UnexpectedValueException('Invalid client metadata');
        }
        sort($redirects);
        sort($grants);

        return ['name' => $data['client_name'], 'redirect_uris' => $redirects, 'grant_types' => $grants,
            'application_type' => $type, 'scope' => $data['scope'] ?? null, 'auth_method' => 'none'];
    }
}
