<?php

namespace App\Services\Mcp\OAuth;

use App\Models\CustomerMcpConnection;
use App\Services\Mcp\McpConnectionPrincipal;
use Illuminate\Support\Facades\DB;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\JwksProviderInterface;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use phpseclib3\Crypt\PublicKeyLoader;

class TokenValidator implements AuthorizationTokenValidatorInterface, JwksProviderInterface
{
    public ?McpConnectionPrincipal $principal = null;

    public function getJwks(string $issuer, ?string $jwksUri = null): array
    {
        $key = config('passport.public_key') ?: file_get_contents(\Laravel\Passport\Passport::keyPath('oauth-public.key'));
        $jwk = json_decode(PublicKeyLoader::loadPublicKey(str_replace('\\n', "\n", $key))->toString('JWK'), true, flags: JSON_THROW_ON_ERROR);
        $keys = $jwk['keys'] ?? [$jwk];
        $keys[0]['kid'] = 'metkurd-mcp';
        $keys[0]['alg'] = 'RS256';

        return ['keys' => $keys];
    }

    public function validate(#[\SensitiveParameter] string $accessToken): AuthorizationResult
    {
        $this->principal = null;
        try {
            $result = (new JwtTokenValidator(config('mcp.issuer'), config('mcp.public_url'), $this,
                algorithms: ['RS256'], scopeClaim: 'scopes'))->validate($accessToken);
            if (! $result->isAllowed()) {
                return AuthorizationResult::unauthorized('invalid_token', 'Invalid or expired token.');
            }
            $claims = $result->getAttributes()['oauth.claims'];
            $token = DB::table('oauth_access_tokens')->where('id', $claims['jti'] ?? '')->where('revoked', false)
                ->where('expires_at', '>', now())->first();
            if (! $token || ! is_numeric($claims['exp'] ?? null) || ! is_finite((float) $claims['exp']) || (string) $token->user_id !== ($claims['sub'] ?? null)
                || $token->client_id !== ($claims['client_id'] ?? null)
                || ! DB::table('oauth_clients')->where('id', $token->client_id)->where('revoked', false)->exists()) {
                return AuthorizationResult::unauthorized('invalid_token', 'Invalid or revoked token.');
            }
            $connection = CustomerMcpConnection::where('customer_id', $token->user_id)->where('client_id', $token->client_id)->first();
            if (! $connection) {
                return AuthorizationResult::unauthorized('invalid_token');
            }
            $principal = new McpConnectionPrincipal($connection->id, array_values(array_intersect(
                $result->getAttributes()['oauth.scopes'], json_decode($token->scopes, true) ?? [])));
            $principal->customer();
            $this->principal = $principal;
            $connection->update(['last_used_at' => now()]);

            return AuthorizationResult::allow();
        } catch (\App\Services\CustomerApi\V2\ApiProblem $e) {
            return $e->status === 401 ? AuthorizationResult::unauthorized('invalid_token')
                : AuthorizationResult::forbidden('access_denied', $e->errorCode);
        } catch (\Throwable) {
            return AuthorizationResult::unauthorized('invalid_token', 'Authorization unavailable.');
        }
    }
}
