<?php

namespace App\Services\Providers;

use Illuminate\Support\Facades\Http;

class RunPodProvider
{
    public function run(string $endpointId, array $input, ?int $timeout = null): array
    {
        $base = rtrim(config('runpod.base_url'), '/');
        $key  = config('runpod.api_key');
        $timeout = $timeout ?? (int) config('runpod.timeout', 60);

        return Http::withToken($key)
            ->timeout($timeout)
            ->post("{$base}/v2/{$endpointId}/run", ['input' => $input])
            ->throw()
            ->json();
    }

    public function status(string $endpointId, string $jobId, ?int $timeout = null): array
    {
        $base = rtrim(config('runpod.base_url'), '/');
        $key  = config('runpod.api_key');
        $timeout = $timeout ?? (int) config('runpod.timeout', 60);

        return Http::withToken($key)
            ->timeout($timeout)
            ->get("{$base}/v2/{$endpointId}/status/{$jobId}")
            ->throw()
            ->json();
    }
}