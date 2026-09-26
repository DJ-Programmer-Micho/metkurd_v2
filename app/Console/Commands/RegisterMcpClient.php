<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

class RegisterMcpClient extends Command
{
    protected $signature = 'mcp:register-client {name} {redirect_uri*} {--native : Reviewed native app; allows loopback callbacks}';

    protected $description = 'Register a reviewed public OAuth MCP client with exact HTTPS callback URLs';

    public function handle(ClientRepository $clients): int
    {
        foreach ($this->argument('redirect_uri') as $uri) {
            if (! \App\Services\Mcp\OAuth\RedirectPolicy::allows($uri, $this->option('native') ? 'native' : 'web')) {
                $this->error('Use exact public HTTPS callbacks, or reviewed loopback callbacks with --native.');

                return self::FAILURE;
            }
        }
        $client = $clients->createAuthorizationCodeGrantClient($this->argument('name'), $this->argument('redirect_uri'), false);
        $client->forceFill(['provider' => 'customers', 'mcp_application_type' => $this->option('native') ? 'native' : 'web'])->save();
        $this->info('Public client ID: '.$client->id);

        return self::SUCCESS;
    }
}
