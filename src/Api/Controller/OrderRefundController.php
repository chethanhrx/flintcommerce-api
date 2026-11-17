<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\OrderRefundTransformer;
use App\Domain\Repository\OrderRefundRepository;
use App\Domain\Repository\OrderRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class OrderRefundController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderRefundRepository $refunds,
        private readonly OrderRefundTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    private function ensureOrderExists(int $orderId): void
    {
        if ($this->orders->find($orderId) === null) {
            throw new WcApiException(
                'woocommerce_rest_order_invalid_id',
                'Invalid ID.',
                404,
            );
        }
    }

    public function index(Request $request, int $orderId): Response
    {
        $this->ensureOrderExists($orderId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->refunds->normalizeFilters($query);

        $total = $this->refunds->count($orderId, $filters);
        $items = $this->refunds->list($orderId, $filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($r): array => $this->transformer->transform($r),
            $items,
        );

        return WcResponse::collection(
            $payload,
            200,
            $pagination,
            $total,
            $this->pageUrl($request, $pagination, $total, +1),
            $this->pageUrl($request, $pagination, $total, -1),
        );
    }

    public function show(Request $request, int $orderId, int $id): Response
    {
        $this->ensureOrderExists($orderId);

        $refund = $this->refunds->find($orderId, $id);

        if ($refund === null) {
            throw new WcApiException(
                'woocommerce_rest_order_refund_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($refund),
            200,
        );
    }

    public function store(Request $request, int $orderId): Response
    {
        $this->ensureOrderExists($orderId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['amount']) || !is_numeric($payload['amount']) || (float) $payload['amount'] < 0) {
            $missing['amount'] = 'amount must be a non-negative number';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizePayload($payload);
        $refund = $this->refunds->create($orderId, $normalized);

        return Response::json(
            $this->transformer->transform($refund),
            201,
        );
    }

    public function update(Request $request, int $orderId, int $id): Response
    {
        $this->ensureOrderExists($orderId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $refund = $this->refunds->update($orderId, $id, $normalized);

        return Response::json(
            $this->transformer->transform($refund),
            200,
        );
    }

    public function destroy(Request $request, int $orderId, int $id): Response
    {
        $this->ensureOrderExists($orderId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$refund, $deleted] = $this->refunds->delete($orderId, $id, $force);
        $data = $this->transformer->transform($refund);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    public function batch(Request $request, int $orderId): Response
    {
        $this->ensureOrderExists($orderId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $result = [
            'create' => [],
            'update' => [],
            'delete' => [],
        ];

        $created = $payload['create'] ?? [];
        if (!is_array($created)) {
            throw WcApiException::invalidParam(['create' => 'create must be an array']);
        }
        foreach (array_values($created) as $item) {
            $item = is_array($item) ? $item : [];
            $missing = [];
            if (!isset($item['amount']) || !is_numeric($item['amount']) || (float) $item['amount'] < 0) {
                $missing['amount'] = 'amount must be a non-negative number';
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $refund = $this->refunds->create($orderId, $this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($refund);
        }

        $updated = $payload['update'] ?? [];
        if (!is_array($updated)) {
            throw WcApiException::invalidParam(['update' => 'update must be an array']);
        }
        foreach (array_values($updated) as $i => $item) {
            $item = is_array($item) ? $item : [];
            if (!isset($item['id']) || preg_match('/\A\d+\z/', (string) $item['id']) !== 1) {
                throw WcApiException::invalidParam(["update[$i]" => 'update requires a valid id']);
            }
            $refund = $this->refunds->update($orderId, (int) $item['id'], $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($refund);
        }

        $deleted = $payload['delete'] ?? [];
        if (!is_array($deleted)) {
            throw WcApiException::invalidParam(['delete' => 'delete must be an array']);
        }
        foreach (array_values($deleted) as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? null;
            }
            if (!is_numeric($item) || preg_match('/\A\d+\z/', (string) $item) !== 1) {
                throw WcApiException::invalidParam(['delete' => 'delete must contain valid ids']);
            }
            [$refund, $wasDeleted] = $this->refunds->delete($orderId, (int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($refund),
            ];
            unset($wasDeleted);
        }

        return Response::json($result, 200);
    }

    /**


     * @param array<string, mixed> $payload


     * @return array<string, mixed>


     */


    private function normalizePayload(array $payload): array
    {
        $errors = [];

        if (isset($payload['amount']) && $payload['amount'] !== null) {
            if (!is_numeric($payload['amount']) || (float) $payload['amount'] < 0) {
                $errors['amount'] = 'amount must be a non-negative number.';
            }
        }

        if (isset($payload['status'])) {
            if (!in_array($payload['status'], ['pending', 'completed', 'failed'], true)) {
                $errors['status'] = 'status is not one of pending, completed, failed.';
            }
        }

        if ($errors !== []) {
            throw WcApiException::invalidParam($errors);
        }

        return $payload;
    }

    private static function booleanParam(mixed $raw, string $key): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $v = is_string($raw) ? strtolower(trim($raw)) : $raw;

        return match ($v) {
            1, '1', 'true', 'yes' => true,
            0, '0', 'false', 'no' => false,
            default => throw WcApiException::invalidParam([$key => "$key must be boolean"]),
        };
    }

    private function pageUrl(Request $request, Pagination $pagination, int $total, int $delta): string
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $targetOffset = $pagination->offset + $delta * $pagination->perPage;

        if ($targetOffset < 0 || $targetOffset >= $total) {
            return '';
        }

        $page = intdiv($targetOffset, $pagination->perPage) + 1;
        unset($query['offset']);
        $query['page'] = (string) $page;

        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $this->baseUrl . $request->path() . ($parts === [] ? '' : '?' . implode('&', $parts));
    }
}