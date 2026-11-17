<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\CategoryTransformer;
use App\Domain\Repository\ProductCategoryRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class ProductCategoryController
{
    public function __construct(
        private readonly ProductCategoryRepository $categories,
        private readonly CategoryTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->normalizeFilters($query);

        $total = $this->categories->count($filters);
        $items = $this->categories->list($filters, $pagination->limit(), $pagination->offset);

        $countMap = $this->categories->countMapFor(array_map(
            static fn ($c): int => (int) $c->id,
            $items,
        ));

        $payload = array_map(
            fn ($c): array => $this->transformer->transform($c, $countMap[(int) $c->id] ?? 0),
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
        $category = $this->categories->find($id);

        if ($category === null) {
            throw new WcApiException(
                'woocommerce_rest_product_cat_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        $countMap = $this->categories->countMapFor([(int) $category->id]);

        return Response::json($this->transformer->transform($category, $countMap[(int) $category->id] ?? 0), 200);
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
        $category = $this->categories->create($normalized);

        $countMap = $this->categories->countMapFor([(int) $category->id]);

        return Response::json(
            $this->transformer->transform($category, $countMap[(int) $category->id] ?? 0),
            201,
        );
    }

    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $category = $this->categories->update($id, $normalized);

        $countMap = $this->categories->countMapFor([(int) $category->id]);

        return Response::json(
            $this->transformer->transform($category, $countMap[(int) $category->id] ?? 0),
            200,
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$category, $deleted] = $this->categories->delete($id, $force);
        $countMap = $this->categories->countMapFor([(int) $category->id]);
        $data = $this->transformer->transform($category, $countMap[(int) $category->id] ?? 0);

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
            $category = $this->categories->create($this->normalizePayload($item));
            $countMap = $this->categories->countMapFor([(int) $category->id]);
            $result['create'][] = $this->transformer->transform($category, $countMap[(int) $category->id] ?? 0);
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
            $category = $this->categories->update((int) $item['id'], $this->normalizePayload($item));
            $countMap = $this->categories->countMapFor([(int) $category->id]);
            $result['update'][] = $this->transformer->transform($category, $countMap[(int) $category->id] ?? 0);
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
            [$category, $wasDeleted] = $this->categories->delete((int) $item, true);
            $countMap = $this->categories->countMapFor([(int) $category->id]);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($category, $countMap[(int) $category->id] ?? 0),
            ];
            unset($wasDeleted);
        }

        return Response::json($result, 200);
    }

    /**
     * WC categories filter params: search, slug, include, exclude, parent, order, orderby.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    /**

     * @param array<string, mixed> $query

     * @return array<string, mixed>

     */

    private function normalizeFilters(array $query): array
    {
        $out = [];

        if (isset($query['search']) && is_string($query['search'])) {
            $out['search'] = $query['search'];
        }

        if (isset($query['slug']) && is_string($query['slug'])) {
            $out['slug'] = $query['slug'];
        }

        foreach (['include', 'exclude', 'parent'] as $key) {
            if (isset($query[$key])) {
                $out[$key] = $query[$key];
            }
        }

        if (isset($query['orderby']) && !in_array($query['orderby'], ['id', 'include', 'name', 'slug', 'menu_order', 'count'], true)) {
            throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']);
        }

        if (isset($query['order']) && !in_array(strtolower((string) $query['order']), ['asc', 'desc'], true)) {
            throw WcApiException::invalidParam(['order' => 'order must be asc or desc']);
        }

        return $out;
    }

    /**


     * @param array<string, mixed> $payload


     * @return array<string, mixed>


     */


    private function normalizePayload(array $payload): array
    {
        $errors = [];

        if (isset($payload['display'])) {
            if (!in_array($payload['display'], ['default', 'products', 'subcategories', 'both'], true)) {
                $errors['display'] = 'display is not one of default, products, subcategories, both.';
            }
        }

        if (isset($payload['menu_order']) && $payload['menu_order'] !== null) {
            if (!is_int($payload['menu_order']) && (is_string($payload['menu_order']) && preg_match('/\A-?\d+\z/', $payload['menu_order']) !== 1)) {
                $errors['menu_order'] = 'menu_order must be an integer.';
            }
        }

        if (isset($payload['parent']) && $payload['parent'] !== null) {
            if (!is_int($payload['parent']) && (is_string($payload['parent']) && preg_match('/\A\d+\z/', $payload['parent']) !== 1)) {
                $errors['parent'] = 'parent must be an integer.';
            }
        }

        if (isset($payload['slug']) && is_string($payload['slug']) && trim($payload['slug']) === '') {
            unset($payload['slug']);
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
