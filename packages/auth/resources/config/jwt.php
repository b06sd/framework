<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// Access tokens (JWTs) from your identity provider, checked by the RequireJwt middleware. Trunk only
// verifies them; the provider issues them. `trunk build` checks these settings.
return static fn(Runtime $runtime): array => [
    // Exactly the "iss" of the provider's tokens, e.g. https://your-tenant.eu.auth0.com/
    'issuer' => $runtime->variable('JWT_ISSUER', ''),
    // Your API's identifier at the provider: tokens must carry it in "aud".
    'audience' => $runtime->variable('JWT_AUDIENCE', ''),
    // Where the provider publishes its public keys. Empty means <issuer>/.well-known/jwks.json.
    'jwks_url' => $runtime->variable('JWT_JWKS_URL', ''),
    // The only signature algorithms accepted. RS256 is the common one; ES256, PS256, EdDSA also work.
    // HS256/384/512 use the shared secret JWT_SECRET instead of a key set ("none" is never accepted).
    'algorithms' => ['RS256'],
    'secret' => $runtime->secret('JWT_SECRET', ''),
    // Seconds of clock difference tolerated for "exp" and "nbf".
    'leeway' => 30,
    // How long the key set is kept before it is fetched again (keys the provider rotates in are
    // fetched as soon as a token uses them, at most once a minute).
    'cache_ttl' => 3600,
    'cache' => $runtime->basePath . '/storage/cache',
    'user' => [
        // The claim that names the user, and how to find them: `id` (your users' id, as Supabase puts
        // in "sub"), `identifier` (the login identifier, usually with 'claim' => 'email'; then only a
        // token with "email_verified": true matches) or `none` (no local user: read $auth->claims()).
        // For anything else, bind your own Trunk\Auth\Jwt\JwtUserResolver.
        'claim' => 'sub',
        'match' => 'id',
    ],
    // Claims whose values become the token's abilities ($auth->tokenCan('read:orders'), Gate).
    'scopes' => ['scope', 'scp', 'permissions'],
];
