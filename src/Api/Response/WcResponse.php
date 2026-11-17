<?php

declare(strict_types=1);

namespace App\Api\Response;

use App\Api\Exception\WcApiException;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Response;

/**
 * WooCommerce v3-compatible response factories.
 */
final class WcResponse
{
    /**
     * WC v3 error envelope:
     * {"code":"...","message":"...","data":{"status":...}}
     */
    public static function error(string $code, string $message, int $status): Response
    {
        return Response::json([
            'code' => $code,
            'message' => $message,
            'data' => ['status' => $status],
        ], $status);
    }

    public static function fromException(WcApiException $e): Response
    {
        $data = ['status' => $e->status];

        if ($e->details() !== null) {
            $data['params'] = $e->details();
        }

        return Response::json([
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
            'data' => $data,
        ], $e->status);
    }

    /**
     * Collection response with WC pagination headers attached.
     *
     * @param list<mixed> $items
     */
    public static function collection(
        array $items,
        int $status,
        Pagination $pagination,
        int $total,
        string $nextUrl,
        string $prevUrl,
    ): Response {
        return Response::json($items, $status)
            ->withHeader('X-WP-Total', (string) $total)
            ->withHeader('X-WP-TotalPages', (string) $pagination->totalPages($total))
            ->withHeader('Link', $pagination->linkHeader($nextUrl, $prevUrl));
    }

    /** 200 with no content but WC-style empty envelope, for DELETE soft-removals. */
    public static function noContent(): Response
    {
        return Response::json([], 200);
    }
}
