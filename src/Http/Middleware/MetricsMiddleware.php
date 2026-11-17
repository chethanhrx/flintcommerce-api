<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Middleware\MiddlewareInterface;
use FlintPHP\Framework\Observability\Contract\MetricRegistryInterface;

final class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MetricRegistryInterface $registry,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $start = hrtime(true);

        // Counted before dispatch so the /metrics snapshot of this very
        // request already reflects the traffic it serves.
        $this->registry->counter('flint_requests_total')->increment();

        try {
            $response = $next($request);
        } catch (\Throwable $throwable) {
            $this->registry->counter('flint_errors_total')->increment();
            throw $throwable;
        } finally {
            $duration = (hrtime(true) - $start) / 1_000_000_000;
            $this->registry->histogram('flint_request_duration_seconds')->observe($duration);
            $this->registry->counter('flint_requests_method_total_' . strtolower($request->method()))->increment();
        }

        return $response;
    }
}
