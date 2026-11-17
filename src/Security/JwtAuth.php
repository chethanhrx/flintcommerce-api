<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use FlintPHP\Framework\Config\Contract\ConfigRepositoryInterface;

/**
 * JWT token manager for customer storefront authentication.
 */
final class JwtAuth
{
    public function __construct(
        private readonly ConfigRepositoryInterface $config,
    ) {
    }

    public function issue(int $customerId, int $ttl = 86400): string
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + $ttl;

        $payload = [
            'iss' => $this->config->get('app.url') ?? 'https://example.com',
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'sub' => (string) $customerId,
            'type' => 'customer',
        ];

        return JWT::encode($payload, $this->getSecret(), 'HS256');
    }

    public function validate(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->getSecret(), 'HS256'));
            $payload = (array) $decoded;

            if (!isset($payload['sub'], $payload['type']) || $payload['type'] !== 'customer') {
                return null;
            }

            return [
                'customer_id' => (int) $payload['sub'],
                'issued_at' => $payload['iat'] ?? 0,
                'expires_at' => $payload['exp'] ?? 0,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function refresh(string $token, int $ttl = 86400): ?string
    {
        $payload = $this->validate($token);
        if ($payload === null) {
            return null;
        }

        return $this->issue($payload['customer_id'], $ttl);
    }

    private function getSecret(): string
    {
        $secret = $this->config->get('security.jwt_secret');

        if (!is_string($secret) || $secret === '' || strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET is not configured or too short (min 32 chars).');
        }

        return $secret;
    }
}