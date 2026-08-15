<?php

namespace App\Support;

use Illuminate\Support\Arr;

class MetKurdV2ToolCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function services(): array
    {
        return config('metkurd_v2.services', []);
    }

    /** @return array<string, mixed>|null */
    public function service(string $service): ?array
    {
        $definition = Arr::get($this->services(), $service);

        return is_array($definition) ? $definition : null;
    }

    /** @return array<string, mixed>|null */
    public function tool(string $service, string $tool): ?array
    {
        $definition = Arr::get($this->service($service) ?? [], "tools.{$tool}");

        return is_array($definition) ? $definition : null;
    }

    public function isKnownService(string $service): bool
    {
        return $this->service($service) !== null;
    }

    public function isKnownTool(string $service, string $tool): bool
    {
        return $this->tool($service, $tool) !== null;
    }
}
