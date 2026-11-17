<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\OrderNoteTransformer;
use App\Domain\Repository\OrderNoteRepository;
use App\Domain\Repository\OrderRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class OrderNoteController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderNoteRepository $notes,
        private readonly OrderNoteTransformer $transformer,
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
        $filters = $this->notes->normalizeFilters($query);

        $total = $this->notes->count($orderId, $filters);
        $items = $this->notes->list($orderId, $filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($n): array => $this->transformer->transform($n),
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

        $note = $this->notes->find($orderId, $id);

        if ($note === null) {
            throw new WcApiException(
                'woocommerce_rest_order_note_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($note),
            200,
        );
    }

    public function store(Request $request, int $orderId): Response
    {
        $this->ensureOrderExists($orderId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['note']) || !is_string($payload['note']) || trim($payload['note']) === '') {
            $missing['note'] = 'note is required';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizePayload($payload);
        $note = $this->notes->create($orderId, $normalized);

        return Response::json(
            $this->transformer->transform($note),
            201,
        );
    }

    public function update(Request $request, int $orderId, int $id): Response
    {
        $this->ensureOrderExists($orderId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $note = $this->notes->update($orderId, $id, $normalized);

        return Response::json(
            $this->transformer->transform($note),
            200,
        );
    }

    public function destroy(Request $request, int $orderId, int $id): Response
    {
        $this->ensureOrderExists($orderId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$note, $deleted] = $this->notes->delete($orderId, $id, $force);
        $data = $this->transformer->transform($note);

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
            if (!isset($item['note']) || !is_string($item['note']) || trim($item['note']) === '') {
                $missing['note'] = 'note is required';
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $note = $this->notes->create($orderId, $this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($note);
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
            $note = $this->notes->update($orderId, (int) $item['id'], $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($note);
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
            [$note, $wasDeleted] = $this->notes->delete($orderId, (int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($note),
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

        if (isset($payload['customer_note']) && $payload['customer_note'] !== null) {
            $payload['customer_note'] = self::booleanParam($payload['customer_note'], 'customer_note') ? 1 : 0;
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