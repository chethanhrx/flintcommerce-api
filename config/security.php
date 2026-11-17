<?php

declare(strict_types=1);

/**
 * Security configuration (namespaced as `security.*`).
 */

use FlintPHP\Framework\Config\Env;

$env = Env::load(dirname(__DIR__) . '/.env');
$isLocal = ($env['APP_ENV'] ?? 'production') !== 'production';

$jwtSecret = $env['JWT_SECRET'] ?? null;
if (!$isLocal && ($jwtSecret === null || $jwtSecret === '' || strlen($jwtSecret) < 32)) {
    throw new \RuntimeException('JWT_SECRET must be set to at least 32 characters in production.');
}

return [
    'ratelimit' => [
        'max_attempts' => (int) ($env['RATE_LIMIT_MAX_ATTEMPTS'] ?? 240),
        'window_seconds' => (int) ($env['RATE_LIMIT_WINDOW_SECONDS'] ?? 60),
    ],
    'trusted_proxies' => ['127.0.0.1', '::1'],
    'auth_require_https' => !$isLocal,
    'jwt_secret' => $jwtSecret ?? bin2hex(random_bytes(32)),
    'cors' => [
        'origins' => $isLocal ? ['*'] : array_filter(explode(',', $env['CORS_ORIGINS'] ?? '')),
    ],
];
