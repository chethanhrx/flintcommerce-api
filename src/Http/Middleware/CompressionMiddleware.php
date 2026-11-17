<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Middleware\MiddlewareInterface;

/**
 * Gzip compression for responses > 1KB.
 *
 * Checks Accept-Encoding header and compresses if supported.
 * Adds Content-Encoding: gzip header when compressed.
 */
final class CompressionMiddleware implements MiddlewareInterface
{
    private int $minLength;
    private int $level;

    public function __construct(
        int $minLength = 1024,
        int $level = 6,
    ) {
        $this->minLength = $minLength;
        $this->level = max(1, min(9, $level));
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $acceptEncoding = $request->header('Accept-Encoding') ?? '';
        if (!str_contains($acceptEncoding, 'gzip')) {
            return $response;
        }

        $body = $response->body();
        if (strlen($body) < $this->minLength) {
            return $response;
        }

        // Don't compress if already compressed
        if ($response->header('Content-Encoding') !== null) {
            return $response;
        }

        $compressed = gzencode($body, $this->level);
        if ($compressed === false) {
            return $response;
        }

        return $response
            ->withBody($compressed)
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Vary', 'Accept-Encoding');
    }
}