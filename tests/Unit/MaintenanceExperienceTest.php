<?php

namespace Tests\Unit;

use App\Support\MaintenanceResponse;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MaintenanceExperienceTest extends TestCase
{
    private string $storage;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/metkurd-maintenance-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->storage.'/framework/views', 0755, true);
        $this->app->useStoragePath($this->storage);
        config(['app.maintenance.driver' => 'file', 'view.compiled' => $this->storage.'/framework/views']);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_plain_down_is_branded_before_auth_and_preserves_retry_headers(): void
    {
        $this->assertSame(0, Artisan::call('down', ['--retry' => 60]));
        // Any attempted service lookup fails instead of touching a real DB/cache/session.
        foreach (['db', 'redis', 'auth'] as $service) {
            $this->app->bind($service, fn () => throw new \RuntimeException('Unavailable service: '.$service));
        }
        foreach (['/', '/en', '/ar/app-v2/ocr/scanner', '/ku/app/dashboard', '/en/adm/home'] as $url) {
            $this->get($url)->assertStatus(503)->assertHeader('Retry-After', '60')
                ->assertSee('MetKurd AI')->assertSee('noindex,nofollow', false);
        }
        $this->getJson('/api/v2/services')->assertStatus(503)->assertHeader('Retry-After', '60')
            ->assertExactJson(json_decode(MaintenanceResponse::JSON, true));
        $this->get('/api/v1/jobs')->assertStatus(503)->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        foreach (['/mcp', '/mcp/files/file_test', '/oauth/token', '/.well-known/oauth-authorization-server'] as $url) {
            $this->get($url)->assertStatus(503)->assertExactJson(json_decode(MaintenanceResponse::JSON, true));
        }
        Artisan::call('up');
        \Illuminate\Support\Facades\Route::get('/maintenance-test-ready', fn () => 'Application available');
        $this->get('/maintenance-test-ready')->assertOk()->assertSee('Application available');
    }

    public function test_plain_down_secret_still_uses_the_native_bypass_response(): void
    {
        Artisan::call('down', ['--secret' => 'test-maintenance-secret']);
        $this->get('/en/adm/home')->assertStatus(503);
        $this->get('/test-maintenance-secret')->assertRedirect('/')->assertCookie('laravel_maintenance');
    }

    public function test_prerender_is_self_contained_and_uses_the_real_down_and_up_commands(): void
    {
        $this->assertSame(0, Artisan::call('down', ['--retry' => 60, '--render' => 'errors.503']));
        $data = $this->app->maintenanceMode()->data();
        $this->assertStringContainsString('data:image/png;base64,', $data['template']);
        $this->assertStringContainsString('window.location.pathname', $data['template']);
        $this->assertDoesNotMatchRegularExpression('/(?:src|href)=["\x27]https?:|@vite|wire:|fetch\(|setInterval\(/', $data['template']);
        $this->assertSame(0, Artisan::call('up'));
        $this->assertFalse($this->app->maintenanceMode()->active());
        $this->assertFileDoesNotExist($this->storage.'/framework/maintenance.php');
    }

    public function test_native_prerender_http_negotiation_bypass_and_recovery_without_laravel(): void
    {
        Artisan::call('down', ['--retry' => 60, '--render' => 'errors.503', '--secret' => 'isolated-secret']);
        $url = $this->startServer();
        foreach (['/', '/en/app-v2/ocr/scanner', '/ar/app-v2/ocr/scanner', '/ku/app-v2/ocr/scanner', '/en/adm/home'] as $path) {
            [$headers, $body] = $this->http($url.$path);
            $this->assertStringContainsString('503', $headers[0]);
            $this->assertContains('Retry-After: 60', $headers);
            $this->assertStringContainsString('MetKurd AI', $body);
            $this->assertStringNotContainsString('NORMAL APPLICATION', $body);
        }
        foreach ([['/api/v1/jobs', ''], ['/api/v2/services', 'Accept: application/json'], ['/en/app-v2', 'Accept: application/problem+json'], ['/livewire/update', "X-Requested-With: XMLHttpRequest\r\nAccept: */*"]] as [$path, $header]) {
            [$headers, $body] = $this->http($url.$path, $header);
            $this->assertStringContainsString('503', $headers[0]);
            $this->assertContains('Retry-After: 60', $headers);
            $this->assertContains('Content-Type: application/json; charset=UTF-8', $headers);
            $this->assertSame(MaintenanceResponse::JSON, $body);
        }
        [, $body] = $this->http($url.'/isolated-secret');
        $this->assertSame('NORMAL APPLICATION', $body); // Native stub delegates to Laravel for cookie issuance.
        $cookie = MaintenanceModeBypassCookie::create('isolated-secret')->getValue();
        [, $body] = $this->http($url.'/en/adm/home', 'Cookie: laravel_maintenance='.$cookie);
        $this->assertSame('NORMAL APPLICATION', $body);
        [$headers] = $this->http($url.'/en/adm/home', 'Cookie: laravel_maintenance=invalid');
        $this->assertStringContainsString('503', $headers[0]);
        $expired = time() - 60;
        $cookie = base64_encode(json_encode(['expires_at' => $expired, 'mac' => hash_hmac('sha256', $expired, 'isolated-secret')]));
        [$headers] = $this->http($url.'/en/adm/home', 'Cookie: laravel_maintenance='.$cookie);
        $this->assertStringContainsString('503', $headers[0]);
        Artisan::call('up');
        [$headers, $body] = $this->http($url.'/en/app-v2/ocr/scanner');
        $this->assertStringContainsString('200', $headers[0]);
        $this->assertSame('NORMAL APPLICATION', $body);
    }

    public function test_native_redirect_and_explicit_exclusion_are_not_reinterpreted(): void
    {
        \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::except('/isolated-probe');
        try {
            Artisan::call('down', ['--render' => 'errors.503', '--redirect' => '/maintenance']);
            $url = $this->startServer();
            [$headers] = $this->http($url.'/api/v2/services');
            $this->assertStringContainsString('302', $headers[0]);
            $this->assertContains('Location: /maintenance', $headers);
            [, $body] = $this->http($url.'/isolated-probe');
            $this->assertSame('NORMAL APPLICATION', $body);
            [$headers] = $this->http($url.'/maintenance');
            $this->assertStringContainsString('503', $headers[0]);
        } finally {
            \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::flushState();
        }
    }

    private function startServer(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $support = var_export(base_path('app/Support/MaintenanceResponse.php'), true);
        $maintenance = var_export($this->storage.'/framework/maintenance.php', true);
        $router = $this->storage.'/router.php';
        // No Composer, framework, DB, Redis or other services exist in this server.
        file_put_contents($router, '<?php require '.$support.'; if (is_file('.$maintenance.')) { \App\Support\MaintenanceResponse::prerendered('.$maintenance.'); } echo "NORMAL APPLICATION";');
        $this->server = new Process([PHP_BINARY, '-S', $address, $router], base_path());
        $this->server->start();
        for ($i = 0; $i < 100; $i++) {
            if ($connection = @stream_socket_client('tcp://'.$address, $error, $message, .1)) {
                fclose($connection);

                return 'http://'.$address;
            }
            usleep(20000);
        }
        $this->fail('Isolated HTTP server did not start: '.$this->server->getErrorOutput());
    }

    private function http(string $url, string $header = ''): array
    {
        $body = file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true, 'header' => $header, 'follow_location' => 0]]));

        return [$http_response_header, $body];
    }
}
