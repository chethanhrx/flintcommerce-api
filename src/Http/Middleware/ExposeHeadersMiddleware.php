<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Middleware\MiddlewareInterface;

/**
 * Makes WC pagination headers visible to browser clients (CORS fetch()).
 */
final class ExposeHeadersMiddleware implements MiddlewareInterface
{
    public const HEADERS = 'X-WP-Total, X-WP-TotalPages, Link, X-RateLimit-Limit, X-RateLimit-Remaining, X-Request-Id, Retry-After, Allow';

    public function process(Request $request, callable $next): Response
    {
        header_remove('X-Powered-By');

        $response = $next($request);

        return $response->withHeader('Access-Control-Expose-Headers', self::HEADERS);
    }
}
