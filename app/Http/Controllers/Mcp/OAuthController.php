<?php

namespace App\Http\Controllers\Mcp;

use App\Models\Customer;
use App\Models\CustomerMcpConnection;
use App\Services\Mcp\CustomerMcpAccessService;
use App\Services\Mcp\OAuth\Connections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use Laravel\Passport\Http\Controllers\RetrievesAuthRequestFromSession;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class OAuthController
{
    use ConvertsPsrResponses, RetrievesAuthRequestFromSession;

    public function authorize(Request $request)
    {
        $access = app(CustomerMcpAccessService::class);
        $customer = $request->user('app');
        $locale = explode(' ', (string) $request->query('ui_locales', session('mcp_locale', app()->getLocale())))[0];
        if (in_array($locale, ['en', 'ar', 'ku'], true)) {
            app()->setLocale($locale);
            session(['mcp_locale' => $locale]);
        }
        $access->assertEligible($customer);
        try {
            $client = app(\App\Services\Mcp\OAuth\ClientIdentity::class)->resolve((string) $request->query('client_id', ''));
        } catch (\UnexpectedValueException) {
            return response()->json(['error' => 'invalid_client'], 400);
        }
        if (! $client) {
            return response()->json(['error' => 'invalid_client'], 400);
        }
        if (! \App\Services\Mcp\OAuth\RedirectPolicy::matches((string) $request->query('redirect_uri', ''), $client->redirect_uris, $client->mcp_application_type)) {
            return response()->json(['error' => 'invalid_request'], 400);
        }
        $scopes = preg_split('/\s+/', trim((string) $request->query('scope', '')));
        if ($request->query('resource') !== config('mcp.public_url') || $request->query('code_challenge_method') !== 'S256'
            || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', (string) $request->query('code_challenge'))
            || ! $scopes || array_diff($scopes, $access->scopes($customer))
            || ($client->scopes !== null && array_diff($scopes, $client->scopes))) {
            return response()->json(['error' => 'invalid_request'], 400);
        }
        // Require explicit consent, including after revocation. Passport owns the request and CSRF-bound session token.
        $request->query->set('prompt', 'consent');
        session(['mcp_client_hash' => $client->mcp_metadata_hash]);
        $factory = new Psr17Factory;
        $psr = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request);

        return app(AuthorizationController::class)->authorize($psr, $request, new Response,
            app(\Laravel\Passport\Contracts\AuthorizationViewResponse::class));
    }

    public function approve(Request $request)
    {
        $authorization = $this->getAuthRequestFromSession($request);
        $customer = $request->user('app');
        if ((string) $authorization->getUser()->getIdentifier() !== (string) $customer->id) {
            abort(403);
        }
        $clientId = $authorization->getClient()->getIdentifier();
        try {
            $current = app(\App\Services\Mcp\OAuth\ClientIdentity::class)->resolve($clientId, false);
        } catch (\UnexpectedValueException) {
            abort(403);
        }
        abort_unless($current && $current->mcp_metadata_hash === session('mcp_client_hash'), 403);
        $clientHash = $current->mcp_metadata_hash;

        return DB::transaction(function () use ($authorization, $customer, $clientId, $clientHash, $request) {
            $client = DB::table('oauth_clients')->where('id', $clientId)->where('revoked', false)->lockForUpdate()->first();
            abort_unless($client && $client->provider === 'customers' && $client->secret === null
                && $client->mcp_metadata_hash === $clientHash
                && \App\Services\Mcp\OAuth\RedirectPolicy::matches((string) $authorization->getRedirectUri(),
                    json_decode($client->redirect_uris, true), $client->mcp_application_type), 403);
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $scopes = array_map(fn ($scope) => $scope->getIdentifier(), $authorization->getScopes());
            $access = app(CustomerMcpAccessService::class);
            $access->assertEligible($customer);
            abort_if(! $scopes || array_diff($scopes, $access->scopes($customer)), 403);
            $approve = $request->input('decision') === 'approve';
            if ($approve) {
                app(Connections::class)->revokeTokens($customer->id, $clientId);
                CustomerMcpConnection::updateOrCreate(['customer_id' => $customer->id, 'client_id' => $clientId],
                    ['name' => $client->name, 'scopes' => $scopes, 'status' => 'active', 'revoked_at' => null]);
            }
            $authorization->setAuthorizationApproved($approve);
            try {
                return $this->convertResponse(app(AuthorizationServer::class)->completeAuthorizationRequest($authorization, new Response));
            } catch (OAuthServerException $e) {
                return $this->convertResponse($e->generateHttpResponse(new Response));
            }
        });
    }

    public function token(Request $request)
    {
        if ($request->input('resource') !== config('mcp.public_url')
            || ! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
            return response()->json(['error' => 'invalid_target'], 400);
        }
        $factory = new Psr17Factory;
        $psr = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request);

        try {
            $current = app(\App\Services\Mcp\OAuth\ClientIdentity::class)->resolve((string) $request->input('client_id', ''), false);
        } catch (\UnexpectedValueException) {
            return response()->json(['error' => 'invalid_client'], 401);
        }
        if (! $current) {
            return response()->json(['error' => 'invalid_client'], 401);
        }

        // Serialize token exchange/rotation with revoke and re-consent for this public client.
        return DB::transaction(function () use ($request, $psr) {
            $client = DB::table('oauth_clients')->where('id', $request->input('client_id'))->where('revoked', false)->lockForUpdate()->first();
            if (! $client || $client->provider !== 'customers' || $client->secret !== null) {
                return response()->json(['error' => 'invalid_client'], 401);
            }
            try {
                return $this->convertResponse(app(AuthorizationServer::class)->respondToAccessTokenRequest($psr, new Response));
            } catch (OAuthServerException $e) {
                return $this->convertResponse($e->generateHttpResponse(new Response));
            }
        });
    }
}
