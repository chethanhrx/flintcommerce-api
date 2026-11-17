<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Api\Response\WcResponse;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Middleware\MiddlewareInterface;

/**
 * Normalizes Kernel-generated 404/405 responses (which bypass the exception
 * handler) into the WC v3 error envelope.
 *
 * The Kernel emits:  {"error":{"message":"Not Found","status":404,"code":"not_found"}}
 * This middleware rewrites to: {"code":"rest_no_route","message":...,"data":{"status":404}}
 */
final class WcKernelErrorMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $status = $response->status();

        if (!in_array($status, [404, 405], true)) {
            return $response;
        }

        $body = json_decode($response->body(), true);
        if (!is_array($body) || !isset($body['error']) || !is_array($body['error'])) {
            return $response;
        }

        $normalized = $status === 405
            ? WcResponse::error(
                'rest_no_route',
                'No route was found matching the URL and request method.',
                405,
            )
            : WcResponse::error(
                'rest_no_route',
                'No route was found matching the URL and request method.',
                404,
            );

        $allow = $response->header('Allow');
        if ($allow !== null) {
            $normalized = $normalized->withHeader('Allow', $allow);
        }

        return $normalized;
    }
}
