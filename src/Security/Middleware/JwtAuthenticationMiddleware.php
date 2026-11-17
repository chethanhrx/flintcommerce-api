<?php

declare(strict_types=1);

namespace App\Security\Middleware;

use App\Api\Exception\WcApiException;
use App\Security\JwtAuth;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Middleware\MiddlewareInterface;

/**
 * JWT authentication middleware for customer storefront access.
 *
 * Expects: Authorization: Bearer <token>
 */
final class JwtAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JwtAuth $jwt,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $header = $request->header('Authorization');

        if ($header === null || trim($header) === '') {
            throw new WcApiException(
                'woocommerce_rest_auth_missing_token',
                'Missing Bearer token.',
                401,
            );
        }

        if (!preg_match('/\ABearer\s+(.+)\z/i', trim($header), $m)) {
            throw new WcApiException(
                'woocommerce_rest_auth_missing_token',
                'Invalid Authorization header format. Use Bearer <token>.',
                401,
            );
        }

        $token = trim($m[1]);
        $payload = $this->jwt->validate($token);

        if ($payload === null) {
            throw new WcApiException(
                'woocommerce_rest_auth_invalid_token',
                'Invalid or expired token.',
                401,
            );
        }

        // Attach customer ID to request attributes for controller access
        $request = $request->withAttribute('customer_id', $payload['customer_id']);

        return $next($request);
    }
}