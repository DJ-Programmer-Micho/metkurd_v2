<?php

namespace App\Console\Commands;

use App\Domain\Payments\Fib\FibConfiguration;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;

class FibDebugConfig extends Command
{
    protected $signature = 'fib:debug-config
        {--profile=all : payment, subscription, or all}
        {--probe : Perform a live token/protected-endpoint probe with the resolved credentials}';

    protected $description = 'Print the resolved FIB configuration and optionally probe the current host/profile setup.';

    public function __construct(
        protected FibConfiguration $config,
        protected HttpFactory $http,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->profiles() as $profile) {
            $summary = $this->config->debugSummary($profile);

            $this->newLine();
            $this->info(strtoupper($profile) . ' PROFILE');
            $this->table(['Key', 'Value'], [
                ['environment', $summary['environment']],
                ['config_cached', $summary['config_cached'] ? 'yes' : 'no'],
                ['base_url', $summary['base_url']],
                ['base_url_source', $summary['base_url_source'] ?? 'n/a'],
                ['token_url', $summary['token_url']],
                ['client_id_present', $summary['client_id_present'] ? 'yes' : 'no'],
                ['client_id', $summary['client_id'] !== '' ? $summary['client_id'] : 'n/a'],
                ['client_id_source', $summary['client_id_source'] ?? 'n/a'],
                ['client_secret_present', $summary['client_secret_present'] ? 'yes' : 'no'],
                ['client_secret_source', $summary['client_secret_source'] ?? 'n/a'],
                ['callback_base_url', (string) $summary['callback_base_url']],
            ]);

            $this->line('Endpoints:');
            foreach ((array) $summary['paths'] as $label => $value) {
                $this->line("  - {$label}: {$value}");
            }

            $warnings = (array) ($summary['warnings'] ?? []);
            if ($warnings !== []) {
                $this->warn('Warnings:');
                foreach ($warnings as $warning) {
                    $this->line("  - {$warning}");
                }
            }

            if ($this->option('probe')) {
                $this->probe($profile);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function profiles(): array
    {
        $requested = strtolower(trim((string) $this->option('profile')));

        return match ($requested) {
            'payment', 'subscription' => [$requested],
            default => ['payment', 'subscription'],
        };
    }

    protected function probe(string $profile): void
    {
        $profileConfig = $this->config->profile($profile);

        if ($profileConfig['base_url'] === '' || $profileConfig['client_id'] === '' || $profileConfig['client_secret'] === '') {
            $this->error("Skipping {$profile} probe because the resolved base URL or credentials are missing.");

            return;
        }

        $this->comment("Probe for {$profile}:");

        $tokenResponse = $this->http
            ->baseUrl($profileConfig['base_url'])
            ->asForm()
            ->timeout((int) config('fib.http.timeout', 15))
            ->post($this->config->path('token'), [
                'grant_type' => 'client_credentials',
                'client_id' => $profileConfig['client_id'],
                'client_secret' => $profileConfig['client_secret'],
            ]);

        $tokenPayload = $tokenResponse->json() ?? ['body' => $tokenResponse->body()];

        $this->line('  - token_status: ' . $tokenResponse->status());

        if (! $tokenResponse->successful()) {
            $this->line('  - token_error: ' . $this->stringifyPayload($tokenPayload));

            return;
        }

        $token = (string) ($tokenPayload['access_token'] ?? '');
        $issuer = $this->jwtIssuer($token);
        $this->line('  - token_issuer: ' . ($issuer ?? 'n/a'));

        [$probePath, $idKey] = $profile === 'subscription'
            ? ['subscription_status', 'subscriptionId']
            : ['payment_status', 'paymentId'];

        $probeResponse = $this->http
            ->baseUrl($profileConfig['base_url'])
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) config('fib.http.timeout', 15))
            ->get($this->config->path($probePath, [$idKey => 'not-a-real-id']));

        $this->line('  - protected_status: ' . $probeResponse->status());
        $this->line('  - protected_body: ' . $this->stringifyPayload($probeResponse->json() ?? ['body' => $probeResponse->body()]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function stringifyPayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    protected function jwtIssuer(string $token): ?string
    {
        $parts = explode('.', $token);

        if (count($parts) < 2) {
            return null;
        }

        $payload = $parts[1];
        $remainder = strlen($payload) % 4;

        if ($remainder > 0) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if (! is_string($decoded)) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) && isset($claims['iss']) && is_string($claims['iss'])
            ? $claims['iss']
            : null;
    }
}
