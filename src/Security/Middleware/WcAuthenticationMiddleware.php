<?php

declare(strict_types=1);

namespace App\Security\Middleware;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Security\ApiKeyIdentity;
use App\Security\WcBasicAuthenticator;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Middleware\MiddlewareInterface;

/**
 * Route-scoped WC authentication + permission gate.
 *
 * Permission semantics (WooCommerce API key model):
 * - read       : GET / HEAD
 * - write      : POST / PUT / PATCH / DELETE
 * - read_write : everything
 */
final class WcAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly WcBasicAuthenticator $authenticator,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            $identity = $this->authenticator->authenticate($request);
        } catch (\Throwable $e) {
            return WcResponse::error(
                'woocommerce_rest_cannot_authenticate',
                $e->getMessage() !== '' ? $e->getMessage() : 'Authentication failed.',
                401,
            );
        }

        if (!$this->authorized($identity, $request->method())) {
            $code = $request->isMethod('DELETE') ? 'woocommerce_rest_cannot_delete' : 'woocommerce_rest_cannot_edit';

            return WcResponse::error(
                $code,
                $request->isMethod('GET') || $request->isMethod('HEAD')
                    ? 'Sorry, you cannot view this resource.'
                    : 'Sorry, you are not allowed to do that.',
                403,
            );
        }

        return $next($request->withAttribute(ApiKeyIdentity::ATTRIBUTE, $identity));
    }

    private function authorized(ApiKeyIdentity $identity, string $method): bool
    {
        return match (true) {
            in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) => $identity->canRead(),
            in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) => $identity->canWrite(),
            default => false,
        };
    }
}
