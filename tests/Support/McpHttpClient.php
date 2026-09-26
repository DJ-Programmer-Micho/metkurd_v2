<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/** Official client -> actual Laravel HTTP kernel; never contacts the network. */
class McpHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $http = Request::create((string) $request->getUri(), $request->getMethod(), server: ['HTTPS' => 'on'], content: (string) $request->getBody());
        foreach ($request->getHeaders() as $name => $values) {
            $http->headers->set($name, $values);
        }
        $response = app(Kernel::class)->handle($http);
        app(Kernel::class)->terminate($http, $response);
        $factory = new Psr17Factory;

        $psr = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createResponse($response);
        $psr->getBody()->rewind();

        return $psr;
    }
}
