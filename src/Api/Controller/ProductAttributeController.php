<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\ProductAttributeTransformer;
use App\Domain\Repository\ProductAttributeRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class ProductAttributeController
{
    public function __construct(
        private readonly ProductAttributeRepository $attributes,
        private readonly ProductAttributeTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->attributes->normalizeFilters($query);

        $total = $this->attributes->count($filters);
        $items = $this->attributes->list($filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($a): array => $this->transformer->transform($a),
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

    public function show(Request $request, int $id): Response
    {
        $attribute = $this->attributes->find($id);

        if ($attribute === null) {
            throw new WcApiException(
                'woocommerce_rest_product_attribute_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($attribute),
            200,
        );
    }

    public function store(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['name']) || !is_string($payload['name']) || trim($payload['name']) === '') {
            $missing['name'] = 'name is required';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizePayload($payload);
        $attribute = $this->attributes->create($normalized);

        return Response::json(
            $this->transformer->transform($attribute),
            201,
        );
    }

    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $attribute = $this->attributes->update($id, $normalized);

        return Response::json(
            $this->transformer->transform($attribute),
            200,
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$attribute, $deleted] = $this->attributes->delete($id, $force);
        $data = $this->transformer->transform($attribute);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    public function batch(Request $request): Response
    {
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
            if (!isset($item['name']) || !is_string($item['name']) || trim($item['name']) === '') {
                $missing['name'] = 'name is required';
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $attribute = $this->attributes->create($this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($attribute);
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
            $attribute = $this->attributes->update((int) $item['id'], $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($attribute);
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
            [$attribute, $wasDeleted] = $this->attributes->delete((int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($attribute),
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

        if (isset($payload['type'])) {
            if (!in_array($payload['type'], ['select', 'text'], true)) {
                $errors['type'] = 'type is not one of select, text.';
            }
        }

        if (isset($payload['order_by'])) {
            if (!in_array($payload['order_by'], ['menu_order', 'name', 'id', 'title'], true)) {
                $errors['order_by'] = 'order_by is not one of menu_order, name, id, title.';
            }
        }

        if (isset($payload['slug'])) {
            if (!is_string($payload['slug']) || trim($payload['slug']) === '') {
                $errors['slug'] = 'slug must be a non-empty string.';
            }
        }

        if (isset($payload['has_archives']) && $payload['has_archives'] !== null) {
            $payload['has_archives'] = self::booleanParam($payload['has_archives'], 'has_archives') ? 1 : 0;
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