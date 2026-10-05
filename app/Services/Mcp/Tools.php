<?php

namespace App\Services\Mcp;

use App\Models\ApiJob;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\CustomerApi\V2\ApiJobResult;
use App\Services\CustomerApi\V2\ApiProblem;
use App\Services\CustomerApi\V2\ApiSubmission;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

class Tools
{
    public function call(McpConnectionPrincipal $principal, string $name, array $arguments, string|int $requestId = 0): CallToolResult
    {
        try {
            $definition = app(ToolCatalog::class)->definitions()[$name];
            $customer = $principal->customer();
            $principal->authorize($customer, $definition['scope']);
            $rateKey = 'customer-api-rate:'.$customer->id;
            $limit = app(CustomerApiAccessService::class)->allowedRequestsPerMinute($customer);
            if ($limit < 1 || RateLimiter::tooManyAttempts($rateKey, $limit)) {
                throw new ApiProblem('rate_limit', 429);
            }
            RateLimiter::hit($rateKey, 60);
            $data = match ($name) {
                'list_services' => ['services' => $this->availableServices($principal)],
                'list_voices' => ['voices' => collect(app(OmniSpeakerCatalog::class)->forCustomer($customer, 'en'))->pluck('speakers')->flatten(1)
                    ->map(fn ($voice) => ['id' => $voice['code'], 'name' => $voice['name']])->values()->all()],
                'get_job' => $this->job($principal, $arguments['job_id']),
                'list_recent_files' => app(Files::class)->recent($principal),
                'create_upload_session' => app(Files::class)->createUpload($principal, $arguments),
                default => $this->submit($principal, $definition['service'], $arguments),
            };

            return app(ResultDelivery::class)->tool($principal, $data, $requestId);
        } catch (ApiProblem $e) {
            return $this->error($e->errorCode);
        } catch (\Illuminate\Validation\ValidationException) {
            return $this->error('invalid_request');
        } catch (\Throwable) {
            return $this->error('service_unavailable');
        }
    }

    private function error(string $code): CallToolResult
    {
        $safe = ['paid_plan_required', 'api_access_unavailable', 'scope_not_allowed', 'connection_revoked', 'invalid_request',
            'invalid_voice', 'invalid_reference', 'invalid_file', 'insufficient_credits', 'job_not_found',
            'concurrency_limit_exceeded', 'rate_limit', 'idempotency_conflict', 'permission_denied'];
        $data = ['error' => ['code' => in_array($code, $safe, true) ? $code : 'service_unavailable']];

        return new CallToolResult([new TextContent(json_encode($data))], isError: true, structuredContent: $data);
    }

    private function availableServices(McpConnectionPrincipal $principal): array
    {
        $services = [];
        foreach (app(ApiCatalog::class)->variants() as $variant) {
            try {
                $principal->authorize($principal->customer(), $variant['scope'], $variant['action']);
                $services[] = $variant['service'];
            } catch (ApiProblem) {
                continue;
            }
        }

        return array_values(array_unique($services));
    }

    public function submit(McpConnectionPrincipal $principal, string $service, array $arguments): array
    {
        $requestId = strtolower($arguments['request_id']);
        if (! \Illuminate\Support\Str::isUuid($requestId)) {
            throw new ApiProblem('invalid_request');
        }
        unset($arguments['request_id']);
        $customer = $principal->customer();
        $catalog = app(ApiCatalog::class);
        $principal->authorize($customer, $catalog->scopeForService($service), $catalog->definition($service, $arguments)[2]);
        $canonical = function (array $values) use (&$canonical): array {
            if (! array_is_list($values)) {
                ksort($values);
            }

            return array_map(fn ($value) => is_array($value) ? $canonical($value) : $value, $values);
        };
        $requestHash = hash('sha256', json_encode([$service, $canonical($arguments)], JSON_THROW_ON_ERROR));
        $identity = 'mcp:'.hash('sha256', $principal->connectionId.':'.$requestId);
        $existing = ApiJob::where('customer_id', $customer->id)->where('idempotency_hash', hash('sha256', $identity))->first();
        if ($existing) {
            if (! hash_equals((string) data_get($existing->meta, 'mcp_request_hash'), $requestHash)) {
                throw new ApiProblem('idempotency_conflict', 409);
            }

            return $this->jobData($existing);
        }
        $fileId = $arguments['file_id'] ?? null;
        unset($arguments['file_id']);
        $request = Request::create('/mcp', 'POST', $arguments);
        $request->setUserResolver(fn () => $principal->customer());
        $request->headers->set('Idempotency-Key', $identity);
        $submit = function ($file = null) use ($request, $service, $principal, $requestHash) {
            if ($file) {
                $request->files->set('file', $file);
            }

            return app(ApiSubmission::class)->submit($request, $service, $principal,
                ['mcp_connection_id' => $principal->connectionId, 'mcp_request_hash' => $requestHash]);
        };
        [$job] = $fileId ? app(Files::class)->withInput($principal, $fileId, $service, $submit) : $submit();

        return $this->jobData($job);
    }

    public function job(McpConnectionPrincipal $principal, string $id): array
    {
        $customer = $principal->customer();
        $principal->authorize($customer, 'v2:jobs:read');
        $job = ApiJob::where('customer_id', $customer->id)->where('meta->api_version', 2)->find($id);
        if (! $job) {
            throw new ApiProblem('job_not_found', 404);
        }

        return $this->jobData($job);
    }

    private function jobData(ApiJob $job): array
    {
        $data = app(ApiJobResult::class)->persistedPayload($job);
        $data['job_id'] = $data['id'];
        unset($data['id']);
        $data['resource_uri'] = 'metkurd://jobs/'.$job->id;
        $data['credits_reserved'] = (int) $job->reserved_credits;
        if (($data['service'] ?? null) === 'stem' && $data['status'] === 'completed' && empty($data['result']['expired'])) {
            $mode = (int) data_get($job->mlJob?->input, 'stems', 0);
            if (in_array($mode, [2, 4], true)) {
                $data['result']['stem_count'] = $mode;
            }
        }
        foreach (['text', 'srt'] as $field) {
            if (isset($data['result'][$field]) && mb_strlen($data['result'][$field]) > 64000) {
                $data['result'][$field] = mb_substr($data['result'][$field], 0, 64000);
                $data['result']['truncated'] = true;
            }
        }
        if (isset($data['result']['segments'])) {
            unset($data['result']['segments']); // Caption files carry full detail without an unbounded tool response.
        }
        foreach ($data['result']['files'] ?? [] as $index => $file) {
            $data['result']['files'][$index]['resource_uri'] = 'metkurd://files/'.$file['id'];
            $data['result']['files'][$index]['artifact_uri'] = 'metkurd://artifacts/'.$file['id'];
            $data['result']['files'][$index]['download_url'] = config('mcp.public_url').'/files/'.$file['id'];
        }

        return $data;
    }
}
