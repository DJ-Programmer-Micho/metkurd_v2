<?php

namespace App\Console\Commands;

use App\Models\ServicePlan;
use App\Services\Mcp\Readiness;
use App\Services\Mcp\SigningKeyReadiness;
use Illuminate\Console\Command;

class McpReadiness extends Command
{
    protected $signature = 'mcp:readiness {--signing-only : Check local signing readiness without querying plans}';

    protected $description = 'Read MCP signing and plan configuration without changing keys, access, prices or balances';

    public function handle(Readiness $readiness, SigningKeyReadiness $signing): int
    {
        $keys = $signing->inspect();
        $this->line(json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->line('Signing checks cover this process only. Repeat as the PHP-FPM identity with its effective configuration on every node; compare public fingerprints. File ownership, permissions, web-root placement and edge challenges require operator verification.');
        if ($this->option('signing-only')) {
            return $keys['ready'] ? self::SUCCESS : self::FAILURE;
        }
        $this->line('Read-only plan configuration. Current effective plan, overrides and the API wallet are checked at runtime.');
        try {
            foreach (ServicePlan::orderBy('id')->get() as $plan) {
                $this->line(json_encode($readiness->plan($plan), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
        } catch (\Throwable $exception) {
            $this->error('Read-only readiness audit unavailable ('.class_basename($exception).'). Check schema/configuration; no settings were changed.');

            return self::FAILURE;
        }

        return $keys['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
