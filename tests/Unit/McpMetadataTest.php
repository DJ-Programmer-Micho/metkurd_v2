<?php

namespace Tests\Unit;

use App\Services\Mcp\OAuth\ClientMetadata;
use App\Services\Mcp\OAuth\PublicMetadataDns;
use App\Services\Mcp\OAuth\RedirectPolicy;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpMetadataTest extends TestCase
{
    private const ID = 'https://client.example.test/oauth/client.json';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp.session_store' => 'array']);
        Http::preventStrayRequests();
    }

    private function document(): array
    {
        return ['client_id' => self::ID, 'client_name' => 'Example client', 'redirect_uris' => ['https://client.example.test/callback'],
            'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token']];
    }

    public function test_fetch_is_pinned_bounded_and_cached_only_with_explicit_freshness(): void
    {
        $this->mock(PublicMetadataDns::class)->shouldReceive('resolve')->once()->andReturn('93.184.216.34');
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(5, $options['timeout']);
            $this->assertSame(['client.example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);

            return Http::response($this->document(), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'max-age=600']);
        });
        $first = app(ClientMetadata::class)->fetch(self::ID);
        $this->assertSame($first, app(ClientMetadata::class)->fetch(self::ID));
        Http::assertSentCount(1);
        $this->travel(301)->seconds();
        $this->mock(PublicMetadataDns::class)->shouldReceive('resolve')->once()->andReturn('93.184.216.34');
        app(ClientMetadata::class)->fetch(self::ID);
        Http::assertSentCount(2);
    }

    #[DataProvider('badResponses')]
    public function test_rejects_bad_responses_without_caching(string $body, int $status, string $mime, array $headers = []): void
    {
        $this->mock(PublicMetadataDns::class)->shouldReceive('resolve')->twice()->andReturn('93.184.216.34');
        Http::fake([self::ID => Http::response($body, $status, $headers + ['Content-Type' => $mime, 'Cache-Control' => 'max-age=300'])]);
        for ($i = 0; $i < 2; $i++) {
            try {
                app(ClientMetadata::class)->fetch(self::ID);
                $this->fail('Invalid metadata was accepted');
            } catch (\UnexpectedValueException $exception) {
                $this->assertSame('Invalid client metadata', $exception->getMessage());
            }
        }
        Http::assertSentCount(2);
    }

    public static function badResponses(): array
    {
        $valid = ['client_id' => self::ID, 'client_name' => 'Client', 'redirect_uris' => ['https://client.example.test/callback']];

        return [
            'invalid JSON' => ['{', 200, 'application/json'],
            'oversized' => [str_repeat(' ', 5121), 200, 'application/json'],
            'redirect to HTTP' => ['', 302, 'application/json', ['Location' => 'http://127.0.0.1/private']],
            'HTTPS redirect also refused' => ['', 302, 'application/json', ['Location' => 'https://other.example.test/client.json']],
            'HTML MIME' => [json_encode($valid), 200, 'text/html'],
            'duplicate escaped identity' => ['{"client_id":"bad","client_\u0069d":"'.self::ID.'","client_name":"X","redirect_uris":["https://client.example.test/callback"]}', 200, 'application/json'],
            'duplicate nested keys' => [substr(json_encode($valid), 0, -1).',"extra":{"key":1,"key":2}}', 200, 'application/json'],
            'identity mismatch' => [json_encode(array_replace($valid, ['client_id' => 'https://other.example.test/client.json'])), 200, 'application/json'],
            'wildcard' => [json_encode(array_replace($valid, ['redirect_uris' => ['https://client.example.test/*']])), 200, 'application/json'],
            'web loopback' => [json_encode(array_replace($valid, ['application_type' => 'web', 'redirect_uris' => ['http://127.0.0.1/callback']])), 200, 'application/json'],
            'unallowed scopes' => [json_encode($valid + ['scope' => 'admin:*']), 200, 'application/json'],
            'private JWT never downgraded' => [json_encode($valid + ['token_endpoint_auth_method' => 'private_key_jwt']), 200, 'application/json'],
            'shared secret' => [json_encode($valid + ['client_secret' => 'bad']), 200, 'application/json'],
            'ambiguous null method' => [json_encode($valid + ['token_endpoint_auth_method' => null]), 200, 'application/json'],
            'invalid metadata array type' => [json_encode($valid + ['grant_types' => (object) ['authorization_code' => true]]), 200, 'application/json'],
            'symmetric method with public list' => [json_encode($valid + ['token_endpoint_auth_method' => 'client_secret_post', 'token_endpoint_auth_methods_supported' => ['none']]), 200, 'application/json'],
            'compressed response' => [json_encode($valid), 200, 'application/json', ['Content-Encoding' => 'gzip']],
        ];
    }

    public function test_timeout_and_no_store_fail_closed_and_never_cache(): void
    {
        $this->mock(PublicMetadataDns::class)->shouldReceive('resolve')->times(3)->andReturn('93.184.216.34');
        Http::fake([self::ID => Http::response($this->document(), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, max-age=300'])]);
        app(ClientMetadata::class)->fetch(self::ID);
        app(ClientMetadata::class)->fetch(self::ID);
        Http::assertSentCount(2);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('private network details'));
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid client metadata');
        app(ClientMetadata::class)->fetch(self::ID);
    }

    #[DataProvider('unsafeIdentities')]
    public function test_unsafe_urls_never_reach_dns_or_http(string $id): void
    {
        $this->mock(PublicMetadataDns::class)->shouldNotReceive('resolve');
        $this->expectException(\UnexpectedValueException::class);
        app(ClientMetadata::class)->fetch($id);
    }

    public static function unsafeIdentities(): array
    {
        return array_map(fn ($uri) => [$uri], ['http://client.example.test/client.json', 'https://localhost/client.json',
            'https://127.0.0.1/client.json', 'https://[::1]/client.json', 'https://user@client.example.test/client.json',
            'https://client.example.test/client.json#bad', 'https://client.example.test/a/../client.json',
            'https://client.example.test/%2e%2e/client.json', 'https://client.example.test', 'https://client.example.test/client.json?token=bad']);
    }

    #[DataProvider('privateAddresses')]
    public function test_all_dns_answers_must_be_public(string $ip): void
    {
        $dns = new class($ip) extends PublicMetadataDns
        {
            public function __construct(private string $ip) {}

            public function addresses(string $host): array
            {
                return ['93.184.216.34', $this->ip];
            }
        };
        $this->expectException(\UnexpectedValueException::class);
        $dns->resolve('looks-public.example.test');
    }

    public static function privateAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], ['127.0.0.1', '10.1.2.3', '192.168.0.1', '169.254.169.254', '100.64.1.1',
            '192.0.2.1', '198.18.0.1', '::1', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', '2001:db8::1', '64:ff9b::a00:1']);
    }

    public function test_loopback_matching_never_expands_web_redirects_or_paths(): void
    {
        foreach (['127.0.0.1', '[::1]'] as $host) {
            $this->assertTrue(RedirectPolicy::matches("http://{$host}:53217/callback", ["http://{$host}/callback"], 'native'));
            $this->assertFalse(RedirectPolicy::matches("http://{$host}:53217/other", ["http://{$host}/callback"], 'native'));
            $this->assertFalse(RedirectPolicy::matches("http://{$host}:53217/callback", ["http://{$host}/callback"], 'web'));
        }
        $this->assertTrue(RedirectPolicy::matches('http://localhost:8080/callback', ['http://localhost:8080/callback'], 'native'));
        $this->assertFalse(RedirectPolicy::matches('http://localhost:8081/callback', ['http://localhost:8080/callback'], 'native'));
        foreach (['http://example.com/callback', 'http://192.168.1.1/callback', 'http://10.0.0.1/callback', 'http://8.8.8.8/callback',
            'http://metkurd.ai/callback', 'http://sub.localhost/callback', 'http://127.0.0.1.evil.test/callback',
            'http://user@127.0.0.1/callback', 'http://127.0.0.1/callback#fragment'] as $uri) {
            $this->assertFalse(RedirectPolicy::allows($uri, 'native'), $uri);
        }
    }

    public function test_standard_native_metadata_and_auth_method_transition(): void
    {
        $metadata = $this->document();
        $metadata['redirect_uris'] = ['http://127.0.0.1/callback/server-id'];
        $metadata['token_endpoint_auth_method'] = 'private_key_jwt';
        $metadata['token_endpoint_auth_methods_supported'] = ['private_key_jwt', 'none'];
        $parsed = app(ClientMetadata::class)->validate(json_encode($metadata), self::ID);
        $this->assertSame('native', $parsed['application_type']);
        $this->assertSame('none', $parsed['auth_method']);
        $this->assertTrue(RedirectPolicy::matches('http://127.0.0.1:54213/callback/server-id', $parsed['redirect_uris'], $parsed['application_type']));
    }
}
