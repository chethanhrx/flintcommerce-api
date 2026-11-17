<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Http\Middleware\BotDetectionMiddleware;
use App\Http\Middleware\CacheHeadersMiddleware;
use App\Http\Middleware\CompressionMiddleware;
use FlintPHP\Framework\Http\Middleware\RateLimitMiddleware;
use FlintPHP\Framework\Security\Middleware\SecurityHeadersConfiguration;
use FlintPHP\Framework\Security\Middleware\SecurityHeadersMiddleware;

final class MiddlewareRegistrar
{
    public static function register(\FlintPHP\Framework\Foundation\Application $app): void
    {
        $container = $app->container();
        $config = $app->config();

        $security = $config->get('security') ?? [];
        $security = is_array($security) ? $security : [];

        $ratelimit = $security['ratelimit'] ?? [];
        $ratelimit = is_array($ratelimit) ? $ratelimit : [];

        $container->singleton(SecurityHeadersConfiguration::class, new SecurityHeadersConfiguration(
            contentSecurityPolicy: "default-src 'none'; frame-ancestors 'none'",
            strictTransportSecurity: 'max-age=31536000; includeSubDomains',
        ));

        $container->singleton(RateLimitMiddleware::class, fn (): RateLimitMiddleware => new RateLimitMiddleware(
            $container->get(\FlintPHP\Framework\Cache\CacheInterface::class),
            (int) ($ratelimit['max_attempts'] ?? 240),
            (int) ($ratelimit['window_seconds'] ?? 60),
        ));

        // Outermost first. WcKernelErrorMiddleware must sit innermost.
        $app->addMiddleware(SecurityHeadersMiddleware::class);
        $app->addMiddleware(BotDetectionMiddleware::class);
        $app->addMiddleware(\App\Http\Middleware\MetricsMiddleware::class);
        $app->addMiddleware(RateLimitMiddleware::class);
        $app->addMiddleware(\App\Http\Middleware\ExposeHeadersMiddleware::class);
        $app->addMiddleware(CacheHeadersMiddleware::class);
        $app->addMiddleware(CompressionMiddleware::class);
        $app->addMiddleware(\App\Http\Middleware\WcKernelErrorMiddleware::class);
    }
}