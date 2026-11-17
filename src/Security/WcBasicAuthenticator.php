<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Repository\ApiKeyRepository;
use FlintPHP\Framework\Authentication\AuthenticatorInterface;
use FlintPHP\Framework\Authentication\Exception\InvalidCredentialsException;
use FlintPHP\Framework\Authentication\Exception\MissingCredentialsException;
use FlintPHP\Framework\Http\Request;

/**
 * WooCommerce v3-style HTTP Basic authentication (HTTPS only).
 *
 * - consumer_key + consumer_secret via "Authorization: Basic base64(ck:cs)"
 * - stored consumer_key is hashed; secret compared timing-safe
 * - plain-HTTP Basic auth is rejected (WC requires OAuth 1.0a over HTTP)
 */
final class WcBasicAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly bool $requireHttps = true,
    ) {
    }

    public function authenticate(Request $request): ApiKeyIdentity
    {
        $header = $request->header('Authorization');

        if ($header === null || trim($header) === '') {
            throw new MissingCredentialsException('Consumer key is missing.');
        }

        if (!preg_match('/\ABasic\s+(.+)\z/i', trim($header), $m)) {
            throw new MissingCredentialsException('Consumer key is missing.');
        }

        $decoded = base64_decode(trim($m[1]), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            throw new InvalidCredentialsException('Consumer key or secret is invalid.');
        }

        $colon = strpos($decoded, ':');
        if ($colon === false) {
            throw new InvalidCredentialsException('Consumer key or secret is invalid.');
        }
        $consumerKey = substr($decoded, 0, $colon);
        $consumerSecret = substr($decoded, $colon + 1);

        if (!str_starts_with($consumerKey, 'ck_')) {
            throw new InvalidCredentialsException('Consumer key is invalid.');
        }

        if (!str_starts_with($consumerSecret, 'cs_')) {
            throw new InvalidCredentialsException('Consumer secret is invalid.');
        }

        if ($this->requireHttps && !$this->isHttps($request)) {
            throw new InvalidCredentialsException(
                'Consumer key/secret must be transmitted over HTTPS or signed with OAuth 1.0a.'
            );
        }

        $row = $this->keys->authenticate($consumerKey, $consumerSecret);

        if ($row === null) {
            throw new InvalidCredentialsException('Consumer key/secret pair is invalid.');
        }

        return new ApiKeyIdentity(
            keyId: $row->keyId,
            consumerKey: $consumerKey,
            permissions: $row->permissions,
            userId: $row->userId,
        );
    }

    /**
     * HTTPS detection: direct TLS indicator or trusted proxy TLS termination.
     * X-Forwarded-Proto is only honoured when REMOTE_ADDR is the loopback of a
     * known proxy — full TrustedProxy integration lands in the hardening phase.
     */
    private function isHttps(Request $request): bool
    {
        if (($request->server('HTTPS') ?? '') !== '') {
            return true;
        }

        $remoteAddr = (string) ($request->server('REMOTE_ADDR') ?? '');
        if ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1') {
            $proto = (string) ($request->server('HTTP_X_FORWARDED_PROTO') ?? '');
            return $proto === 'https';
        }

        return false;
    }
}
