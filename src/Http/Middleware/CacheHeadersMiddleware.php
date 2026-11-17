<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Middleware\MiddlewareInterface;

/**
 * Adds HTTP caching headers (ETag, Cache-Control, Last-Modified) to GET responses.
 *
 * - ETag: MD5 of response body (strong validator)
 * - Cache-Control: configurable max-age (default 0 for dynamic API, 60s for GET)
 * - Last-Modified: current time (weak validator)
 * - Handles If-None-Match (304 Not Modified) and If-Modified-Since
 */
final class CacheHeadersMiddleware implements MiddlewareInterface
{
    private int $maxAge;
    private bool $enableEtag;
    private bool $enableLastModified;

    public function __construct(
        int $maxAge = 0,
        bool $enableEtag = true,
        bool $enableLastModified = true,
    ) {
        $this->maxAge = $maxAge;
        $this->enableEtag = $enableEtag;
        $this->enableLastModified = $enableLastModified;
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Only cache GET requests with 200 status
        if ($request->method() !== 'GET' || $response->status() !== 200) {
            return $response;
        }

        $body = $response->body();
        $etag = $this->enableEtag ? '"' . md5($body) . '"' : null;

        // Check If-None-Match for 304
        $ifNoneMatch = $request->header('If-None-Match');
        if ($etag !== null && $ifNoneMatch !== null && $ifNoneMatch === $etag) {
            return new Response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => $this->buildCacheControl(),
            ]);
        }

        // Check If-Modified-Since for 304
        $ifModifiedSince = $request->header('If-Modified-Since');
        if ($this->enableLastModified && $ifModifiedSince !== null) {
            $lastModified = gmdate('D, d M Y H:i:s') . ' GMT';
            if (strtotime($ifModifiedSince) >= strtotime($lastModified)) {
                return new Response('', 304, [
                    'ETag' => $etag ?? '',
                    'Last-Modified' => $lastModified,
                    'Cache-Control' => $this->buildCacheControl(),
                ]);
            }
        }

        // Add caching headers to response
        $response = $response->withHeader('Cache-Control', $this->buildCacheControl());

        if ($etag !== null) {
            $response = $response->withHeader('ETag', $etag);
        }

        if ($this->enableLastModified) {
            $response = $response->withHeader('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
        }

        return $response;
    }

    private function buildCacheControl(): string
    {
        if ($this->maxAge > 0) {
            return sprintf('public, max-age=%d', $this->maxAge);
        }

        return 'no-cache, must-revalidate';
    }
}