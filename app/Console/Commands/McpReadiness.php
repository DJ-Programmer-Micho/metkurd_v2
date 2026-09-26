<?php

namespace App\Console\Commands;

use App\Models\ServicePlan;
use App\Services\Mcp\Readiness;
use Illuminate\Console\Command;

class McpReadiness extends Command
{
    protected $signature = 'mcp:readiness';

    protected $description = 'Read plan-level MCP configuration without changing access, prices or balances';

    public function handle(Readiness $readiness): int
    {
        $this->line('Read-only plan configuration. Current effective plan, overrides and the API wallet are checked at runtime.');
        try {
            foreach (ServicePlan::orderBy('id')->get() as $plan) {
                $this->line(json_encode($readiness->plan($plan), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
        } catch (\Throwable $exception) {
            $this->error('Read-only readiness audit unavailable ('.class_basename($exception).'). Check schema/configuration; no settings were changed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
