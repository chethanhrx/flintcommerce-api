<?php

declare(strict_types=1);

namespace App\Security;

use FlintPHP\Framework\Foundation\Application;
use FlintPHP\Framework\Security\Http\TrustedProxyConfiguration;

/**
 * Trusted proxy wiring. Only loopback + private ranges behind the LB are
 * trusted; spoofed X-Forwarded-For from clients is ignored.
 */
final class TrustedProxies
{
    public static function configuration(Application $app): ?TrustedProxyConfiguration
    {
        $trusted = $app->config()->get('security.trusted_proxies') ?? ['127.0.0.1', '::1'];

        if (!is_array($trusted) || $trusted === []) {
            return null;
        }

        return new TrustedProxyConfiguration($trusted);
    }
}
