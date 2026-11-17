<?php

declare(strict_types=1);

namespace App\Http;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use FlintPHP\Framework\Http\Exception\HttpException;
use FlintPHP\Framework\Http\Exception\ExceptionHandlerInterface;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Observability\Contract\LoggerInterface;
use Throwable;

/**
 * Exception boundary emitting WooCommerce v3-shaped JSON errors:
 * {"code":"...","message":"...","data":{"status":...}}
 *
 * Logging rules match the framework handler: 5xx/unexpected → error with
 * context; 4xx → info; headers and bodies are never logged.
 */
final class WcJsonExceptionHandler implements ExceptionHandlerInterface
{
    private const DEFAULT_ERROR_CODES = [
        400 => 'rest_invalid_param',
        401 => 'rest_cannot_view',
        403 => 'rest_forbidden',
        404 => 'rest_no_route',
        405 => 'rest_no_route',
        413 => 'rest_request_entity_too_large',
        422 => 'rest_invalid_param',
        429 => 'rest_too_many_requests',
        500 => 'internal_server_error',
        503 => 'rest_service_unavailable',
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Throwable $exception, Request $request): Response
    {
        if ($exception instanceof WcApiException) {
            $this->log($exception, $request, $exception->status);

            return WcResponse::fromException($exception);
        }

        if ($exception instanceof HttpException) {
            $status = $exception->status();
            $this->log($exception, $request, $status);

            $message = $exception->getMessage();
            if ($message === '') {
                $message = sprintf('HTTP %d', $status);
            }

            return WcResponse::error(
                self::DEFAULT_ERROR_CODES[$status] ?? 'internal_server_error',
                $message,
                $status,
            );
        }

        $this->logUnexpected($exception, $request);

        return WcResponse::error('internal_server_error', 'Internal server error.', 500);
    }

    private function log(Throwable $exception, Request $request, int $status): void
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'exception' => $exception::class,
            'status' => $status,
            'method' => $request->method(),
            'path' => $request->path(),
        ];

        if ($status >= 500) {
            $this->logger->error($exception->getMessage() !== '' ? $exception->getMessage() : 'HTTP error', $context);
        } else {
            $this->logger->info($exception->getMessage() !== '' ? $exception->getMessage() : 'HTTP client error', $context);
        }
    }

    private function logUnexpected(Throwable $exception, Request $request): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->error($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class, [
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'method' => $request->method(),
            'path' => $request->path(),
        ]);
    }
}
