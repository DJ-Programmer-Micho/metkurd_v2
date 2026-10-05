<?php

namespace Tests\Unit;

use App\Services\Mcp\SigningKeyReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use phpseclib3\Crypt\RSA;
use Tests\TestCase;

class McpSigningKeyReadinessTest extends TestCase
{
    private string $directory;

    private ?string $originalKeyPath;

    private string $privatePem;

    private string $publicPem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKeyPath = Passport::$keyPath;
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mcp-keys-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        Passport::loadKeysFrom($this->directory);
        static $key;
        $key ??= RSA::createKey(2048);
        $this->privatePem = $key->toString('PKCS8');
        $this->publicPem = $key->getPublicKey()->toString('PKCS8');
        config(['passport.private_key' => null, 'passport.public_key' => null,
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->app->forgetInstance(AuthorizationServer::class);
        DB::listen(fn () => throw new \RuntimeException('Signing readiness must not query a database'));
    }

    protected function tearDown(): void
    {
        Passport::$keyPath = $this->originalKeyPath;
        foreach (['oauth-private.key', 'oauth-public.key'] as $file) {
            if (is_file($this->directory.DIRECTORY_SEPARATOR.$file)) {
                unlink($this->directory.DIRECTORY_SEPARATOR.$file);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_missing_keys_fail_without_creating_files_or_leaking_paths(): void
    {
        $result = app(SigningKeyReadiness::class)->inspect();
        $this->assertFalse($result['ready']);
        $this->assertNull($result['pair_matches']);
        $this->assertSame('not_checked', $result['authorization_server']);
        $this->assertSame('missing_unreadable_or_invalid', $result['private_key']['status']);
        $this->assertSame('missing_unreadable_or_invalid', $result['public_key']['status']);
        $this->assertSame(['.', '..'], scandir($this->directory));
        $this->assertStringNotContainsString($this->directory, json_encode($result));
    }

    public function test_matching_inline_keys_instantiate_the_real_authorization_server(): void
    {
        config(['passport.private_key' => str_replace("\n", '\\n', $this->privatePem),
            'passport.public_key' => str_replace("\n", '\\n', $this->publicPem)]);
        $result = app(SigningKeyReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertSame('ready', $result['authorization_server']);
        $this->assertTrue($result['pair_matches']);
        $this->assertSame('configuration', $result['private_key']['source']);
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $this->publicPem));
        $this->assertSame(hash('sha256', $der), $result['public_key']['public_key_sha256']);
        $this->assertSame($result['private_key']['public_key_sha256'], $result['public_key']['public_key_sha256']);
        $this->assertStringNotContainsString('BEGIN', json_encode($result));
        $this->assertStringNotContainsString($this->privatePem, json_encode($result));
    }

    public function test_files_are_read_without_modification_and_config_overrides_them(): void
    {
        foreach (['private' => $this->privatePem, 'public' => $this->publicPem] as $type => $pem) {
            file_put_contents($this->directory.'/oauth-'.$type.'.key', $pem);
            chmod($this->directory.'/oauth-'.$type.'.key', 0640);
        }
        $result = app(SigningKeyReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertSame('passport_file', $result['private_key']['source']);
        $this->assertSame($this->privatePem, file_get_contents($this->directory.'/oauth-private.key'));
        $this->assertSame($this->publicPem, file_get_contents($this->directory.'/oauth-public.key'));
        config(['passport.private_key' => 'PRIVATE INVALID CONFIG']);
        $result = app(SigningKeyReadiness::class)->inspect();
        $this->assertFalse($result['ready']);
        $this->assertSame('configuration', $result['private_key']['source']);
        $this->assertStringNotContainsString('PRIVATE INVALID CONFIG', json_encode($result));
    }

    public function test_mismatched_keys_fail_and_do_not_construct_the_server(): void
    {
        $other = RSA::createKey(2048);
        config(['passport.private_key' => $this->privatePem, 'passport.public_key' => $other->getPublicKey()->toString('PKCS8')]);
        $result = app(SigningKeyReadiness::class)->inspect();
        $this->assertFalse($result['ready']);
        $this->assertFalse($result['pair_matches']);
        $this->assertSame('not_checked', $result['authorization_server']);
        $this->assertFalse($this->app->resolved(AuthorizationServer::class));
    }

    public function test_incomplete_pair_and_wrong_key_roles_fail(): void
    {
        config(['passport.private_key' => $this->privatePem]);
        $this->assertFalse(app(SigningKeyReadiness::class)->inspect()['ready']);
        config(['passport.private_key' => $this->publicPem, 'passport.public_key' => $this->publicPem]);
        $result = app(SigningKeyReadiness::class)->inspect();
        $this->assertFalse($result['ready']);
        $this->assertSame('missing_unreadable_or_invalid', $result['private_key']['status']);
    }

    public function test_non_rsa_keys_are_not_ready_for_mcp_rs256(): void
    {
        $key = \phpseclib3\Crypt\EC::createKey('secp256r1');
        config(['passport.private_key' => $key->toString('PKCS8'), 'passport.public_key' => $key->getPublicKey()->toString('PKCS8')]);
        $this->assertFalse(app(SigningKeyReadiness::class)->inspect()['ready']);
    }

    public function test_signing_only_command_reports_failure_without_exception_details(): void
    {
        config(['passport.private_key' => $this->privatePem, 'passport.public_key' => $this->publicPem]);
        $this->app->bind(AuthorizationServer::class, fn () => throw new \LogicException('SECRET PEM OR CONFIG DETAILS'));
        $this->assertSame(1, Artisan::call('mcp:readiness', ['--signing-only' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"authorization_server":"unavailable"', $output);
        $this->assertStringContainsString('"ready":false', $output);
        $this->assertStringNotContainsString('SECRET', $output);
        $this->assertStringNotContainsString('BEGIN', $output);
        $this->assertStringNotContainsString('Read-only plan configuration.', $output);
    }

    public function test_signing_only_command_succeeds_without_database_queries(): void
    {
        config(['passport.private_key' => $this->privatePem, 'passport.public_key' => $this->publicPem]);
        $this->assertSame(0, Artisan::call('mcp:readiness', ['--signing-only' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"scope":"current_process_only"', $output);
        $this->assertStringContainsString('"ready":true', $output);
        $this->assertStringContainsString('PHP-FPM identity', $output);
    }
}
