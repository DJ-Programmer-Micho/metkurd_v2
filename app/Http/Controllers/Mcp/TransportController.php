<?php

namespace App\Http\Controllers\Mcp;

use App\Services\Mcp\OAuth\TokenValidator;
use App\Services\Mcp\ToolCatalog;
use App\Services\Mcp\Tools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\MiddlewareRequestHandler;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class TransportController
{
    public function __invoke(Request $request)
    {
        return $this->authenticated($request, function ($principal, $authorized) {
            $builder = Server::builder()->setServerInfo('MetKurd AI', '1.0.0')->setLogger(new NullLogger)
                ->setCapabilities(new \Mcp\Schema\ServerCapabilities(tools: true, resources: true, resourcesSubscribe: false, prompts: false))
                ->setInstructions('Paid tools consume API credits. Preserve request_id for retries; never submit the same logical paid request with a new request_id because polling timed out. Use metkurd_get_job for asynchronous continuation. When completed, surface the available final artifacts to the user; prefer actual result content over raw metadata. Rendering inline media depends on the MCP host. Discover voice IDs first; never invent voice IDs, file IDs or URLs.')
                ->setSession(new \App\Services\Mcp\PrivateSessionStore(Cache::store(config('mcp.session_store')),
                    'mcp-'.$principal->connectionId.'-', config('mcp.session_seconds')));
            foreach (app(ToolCatalog::class)->definitions() as $name => $definition) {
                $builder->addTool(function (\Mcp\Server\RequestContext $context, ?string $request_id = null, ?string $text = null, ?string $voice = null,
                    ?string $language = null, ?string $model = null, ?string $job_id = null, ?int $reference_id = null,
                    ?string $reference_text = null, ?array $segments = null, ?int $file_id = null,
                    ?bool $intelligent = null, ?string $pages = null, ?array $exports = null, ?int $mode = null, ?string $purpose = null) use ($principal, $name) {
                    return app(Tools::class)->call($principal, $name, array_filter(compact('request_id', 'text', 'voice', 'language', 'model', 'job_id', 'reference_id',
                        'reference_text', 'segments', 'file_id', 'intelligent', 'pages', 'exports', 'mode', 'purpose'), fn ($value) => $value !== null), $context->getRequest()->getId());
                }, name: $definition['name'], title: $definition['title'], description: $definition['description'],
                    annotations: new ToolAnnotations(readOnlyHint: ! $definition['paid'] && $name !== 'create_upload_session', destructiveHint: false,
                        idempotentHint: true, openWorldHint: false), inputSchema: $definition['schema'], outputSchema: $definition['outputSchema']);
            }
            $builder->addResourceTemplate(function (string $job_id, \Mcp\Server\RequestContext $context) use ($principal) {
                try {
                    return app(\App\Services\Mcp\ResultDelivery::class)->metadata('metkurd://jobs/'.$job_id,
                        app(Tools::class)->job($principal, $job_id), $context->getRequest()->getId());
                } catch (\Mcp\Exception\ResourceReadException $exception) {
                    throw $exception;
                } catch (\Throwable) {
                    return json_encode(['error' => ['code' => 'job_not_found']]);
                }
            }, 'metkurd://jobs/{job_id}', 'job', mimeType: 'application/json');
            $builder->addResourceTemplate(function (string $file_id, \Mcp\Server\RequestContext $context) use ($principal) {
                try {
                    $file = app(\App\Services\Mcp\Files::class)->result($principal, $file_id);

                    return app(\App\Services\Mcp\ResultDelivery::class)->metadata('metkurd://files/'.$file_id, ['mime_type' => $file->mime, 'size_bytes' => $file->size_bytes,
                        'download_url' => config('mcp.public_url').'/files/'.$file_id,
                        'authentication' => 'OAuth bearer token required'], $context->getRequest()->getId());
                } catch (\Mcp\Exception\ResourceReadException $exception) {
                    throw $exception;
                } catch (\Throwable) {
                    return json_encode(['error' => ['code' => 'invalid_file']]);
                }
            }, 'metkurd://files/{file_id}', 'file', mimeType: 'application/json');
            $builder->addResourceTemplate(function (string $file_id, \Mcp\Server\RequestContext $context) use ($principal) {
                return app(\App\Services\Mcp\ResultDelivery::class)->resource($principal, $file_id, $context->getRequest()->getId());
            }, 'metkurd://artifacts/{file_id}', 'artifact', description: 'Authenticated artifact bytes within delivery limits. MIME comes from the owned result; oversized/binary documents use the protected download in metkurd://files/{file_id}.');

            return $builder->build()->run(new StreamableHttpTransport($authorized, logger: new NullLogger,
                middleware: [new DnsRebindingProtectionMiddleware([parse_url(config('mcp.public_url'), PHP_URL_HOST)])],
                // A client-controlled RPC ID must also fit the response ceiling,
                // including worst-case JSON escaping. Default request limit is unchanged.
                maxBodyBytes: min(config('mcp.max_body_bytes'), intdiv(max(4096, (int) config('mcp.response_bytes')) - 2048, 6))));
        });
    }

    public function authenticated(Request $request, callable $work, bool $streamed = false)
    {
        $factory = new Psr17Factory;
        $psr = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request);
        $validator = new TokenValidator;
        $metadata = new ProtectedResourceMetadata([config('mcp.issuer')], resource: config('mcp.public_url'),
            metadataPaths: ['/.well-known/oauth-protected-resource/mcp']);
        $handler = new MiddlewareRequestHandler([new AuthorizationMiddleware($validator, $metadata)],
            fn ($authorized) => $work($validator->principal, $authorized));

        return (new HttpFoundationFactory)->createResponse($handler->handle($psr), $streamed);
    }
}
