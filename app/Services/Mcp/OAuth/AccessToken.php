<?php

namespace App\Services\Mcp\OAuth;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;

/** Standard RS256 JWT with the resource audience (RFC 8707), signed by League's JWT library. */
class AccessToken extends \Laravel\Passport\Bridge\AccessToken
{
    private CryptKeyInterface $signingKey;

    public function setPrivateKey(#[\SensitiveParameter] CryptKeyInterface $privateKey): void
    {
        $this->signingKey = $privateKey;
        parent::setPrivateKey($privateKey);
    }

    public function toString(): string
    {
        $jwt = Configuration::forAsymmetricSigner(new Sha256,
            InMemory::plainText($this->signingKey->getKeyContents(), $this->signingKey->getPassPhrase() ?? ''),
            InMemory::plainText('unused'));

        return $jwt->builder()->withHeader('kid', 'metkurd-mcp')->withHeader('typ', 'at+jwt')
            ->issuedBy(config('mcp.issuer'))->permittedFor(config('mcp.public_url'))
            ->identifiedBy($this->getIdentifier())->relatedTo($this->getUserIdentifier())
            ->issuedAt(new DateTimeImmutable)->canOnlyBeUsedAfter(new DateTimeImmutable)
            ->expiresAt($this->getExpiryDateTime())->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scopes', array_map(fn ($scope) => $scope->getIdentifier(), $this->getScopes()))
            ->getToken($jwt->signer(), $jwt->signingKey())->toString();
    }
}
