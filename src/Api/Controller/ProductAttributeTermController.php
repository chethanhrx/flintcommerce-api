<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\ProductAttributeTermTransformer;
use App\Domain\Repository\ProductAttributeRepository;
use App\Domain\Repository\ProductAttributeTermRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class ProductAttributeTermController
{
    public function __construct(
        private readonly ProductAttributeRepository $attributes,
        private readonly ProductAttributeTermRepository $terms,
        private readonly ProductAttributeTermTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    private function ensureAttributeExists(int $attributeId): void
    {
        if ($this->attributes->find($attributeId) === null) {
            throw new WcApiException(
                'woocommerce_rest_product_attribute_invalid_id',
                'Invalid attribute ID.',
                404,
            );
        }
    }

    public function index(Request $request, int $attributeId): Response
    {
        $this->ensureAttributeExists($attributeId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->terms->normalizeFilters($query);

        $total = $this->terms->count($attributeId, $filters);
        $items = $this->terms->list($attributeId, $filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($t): array => $this->transformer->transform($t),
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

    public function show(Request $request, int $attributeId, int $id): Response
    {
        $this->ensureAttributeExists($attributeId);

        $term = $this->terms->find($attributeId, $id);

        if ($term === null) {
            throw new WcApiException(
                'woocommerce_rest_product_attribute_term_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($term),
            200,
        );
    }

    public function store(Request $request, int $attributeId): Response
    {
        $this->ensureAttributeExists($attributeId);

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
        $term = $this->terms->create($attributeId, $normalized);

        return Response::json(
            $this->transformer->transform($term),
            201,
        );
    }

    public function update(Request $request, int $attributeId, int $id): Response
    {
        $this->ensureAttributeExists($attributeId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $term = $this->terms->update($attributeId, $id, $normalized);

        return Response::json(
            $this->transformer->transform($term),
            200,
        );
    }

    public function destroy(Request $request, int $attributeId, int $id): Response
    {
        $this->ensureAttributeExists($attributeId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$term, $deleted] = $this->terms->delete($attributeId, $id, $force);
        $data = $this->transformer->transform($term);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    public function batch(Request $request, int $attributeId): Response
    {
        $this->ensureAttributeExists($attributeId);

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
            $term = $this->terms->create($attributeId, $this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($term);
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
            $term = $this->terms->update($attributeId, (int) $item['id'], $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($term);
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
            [$term, $wasDeleted] = $this->terms->delete($attributeId, (int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($term),
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

        if (isset($payload['slug'])) {
            if (!is_string($payload['slug']) || trim($payload['slug']) === '') {
                $errors['slug'] = 'slug must be a non-empty string.';
            }
        }

        foreach (['menu_order'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== null) {
                if (!is_int($payload[$field]) && (is_string($payload[$field]) && preg_match('/\A-?\d+\z/', $payload[$field]) !== 1)) {
                    $errors[$field] = "$field must be an integer.";
                }
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