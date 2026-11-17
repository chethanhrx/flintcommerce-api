<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\ProductTransformer;
use App\Domain\Repository\ProductRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class ProductController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->products->normalizeFilters($query);

        $total = $this->products->count($filters);
        $items = $this->products->list($filters, $pagination->limit(), $pagination->offset);

        $categoryMap = $this->products->categoryMapFor(array_map(
            static fn ($p): int => (int) $p->id,
            $items,
        ));

        $payload = array_map(
            fn ($p): array => $this->transformer->transform($p, $categoryMap[(int) $p->id] ?? []),
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
        $product = $this->products->find($id);

        if ($product === null) {
            throw new WcApiException(
                'woocommerce_rest_product_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        $categoryMap = $this->products->categoryMapFor([(int) $product->id]);

        return Response::json(
            $this->transformer->transform($product, $categoryMap[(int) $product->id] ?? []),
            200,
        );
    }

    /**
     * POST/PUT/PATCH /products/{id} — WC update semantics: partial merge,
     * 404 on unknown id, rest_invalid_param on bad values, 200 with resource.
     */
    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $product = $this->products->update($id, $normalized);

        $categoryMap = $this->products->categoryMapFor([(int) $product->id]);

        return Response::json(
            $this->transformer->transform($product, $categoryMap[(int) $product->id] ?? []),
            200,
        );
    }

    /**
     * DELETE /products/{id}?force=true — permanent delete returns
     * {deleted: true, previous: {...}}; force=false moves to trash and
     * returns the trashed resource.
     */
    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$product, $deleted, $categoryMap] = $this->products->delete($id, $force);
        $data = $this->transformer->transform($product, $categoryMap[(int) $product->id] ?? []);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    /**
     * POST /products/batch — {create: [...], update: [...], delete: [...]}.
     * Each op runs in its own transaction; one bad item invalidates that op
     * only. Response always contains all three keys (WC parity).
     *
     * @return Response
     */
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
            $product = $this->products->create($this->normalizePayload($item));
            $categoryMap = $this->products->categoryMapFor([(int) $product->id]);
            $result['create'][] = $this->transformer->transform($product, $categoryMap[(int) $product->id] ?? []);
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
            $id = (int) $item['id'];
            $product = $this->products->update($id, $this->normalizePayload($item));
            $categoryMap = $this->products->categoryMapFor([(int) $product->id]);
            $result['update'][] = $this->transformer->transform($product, $categoryMap[(int) $product->id] ?? []);
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
            [$product, $wasDeleted, $categoryMap] = $this->products->delete((int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($product, $categoryMap[(int) $product->id] ?? []),
            ];
            unset($wasDeleted);
        }

        return Response::json($result, 200);
    }

    /**
     * POST /products — WC create semantics: name required, rest_missing_callback_param
     * on absent required fields, rest_invalid_param on bad values, 201 with full resource.
     */
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
        $product = $this->products->create($normalized);

        $categoryMap = $this->products->categoryMapFor([(int) $product->id]);

        return Response::json(
            $this->transformer->transform($product, $categoryMap[(int) $product->id] ?? []),
            201,
        );
    }

    /**
     * Validate + normalize write payload (WC field rules, create + update).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    /**

     * @param array<string, mixed> $payload

     * @return array<string, mixed>

     */

    private function normalizePayload(array $payload): array
    {
        $errors = [];

        if (isset($payload['status'])) {
            if (!in_array($payload['status'], ['draft', 'pending', 'private', 'publish'], true)) {
                $errors['status'] = 'status is not one of draft, pending, private and publish.';
            }
        }

        if (isset($payload['type'])) {
            if (!in_array($payload['type'], ['simple', 'grouped', 'external', 'variable'], true)) {
                $errors['type'] = 'type is not one of simple, grouped, external, variable.';
            }
        }

        if (isset($payload['catalog_visibility'])) {
            if (!in_array($payload['catalog_visibility'], ['visible', 'catalog', 'search', 'hidden'], true)) {
                $errors['catalog_visibility'] = 'catalog_visibility is not one of visible, catalog, search, hidden.';
            }
        }

        if (isset($payload['stock_status'])) {
            if (!in_array($payload['stock_status'], ['instock', 'outofstock', 'onbackorder'], true)) {
                $errors['stock_status'] = 'stock_status is not one of instock, outofstock, onbackorder.';
            }
        }

        if (isset($payload['backorders'])) {
            if (!in_array($payload['backorders'], ['no', 'notify', 'yes'], true)) {
                $errors['backorders'] = 'backorders is not one of no, notify, yes.';
            }
        }

        if (isset($payload['tax_status'])) {
            if (!in_array($payload['tax_status'], ['taxable', 'shipping', 'none'], true)) {
                $errors['tax_status'] = 'tax_status is not one of taxable, shipping, none.';
            }
        }

        foreach (['regular_price', 'sale_price', 'price'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== '' && $payload[$field] !== null) {
                if (!is_numeric($payload[$field]) || (float) $payload[$field] < 0) {
                    $errors[$field] = "$field must be a non-negative number.";
                }
            }
        }

        foreach (['stock_quantity', 'low_stock_amount', 'download_limit', 'download_expiry', 'menu_order', 'parent_id'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== null) {
                if (!is_int($payload[$field]) && (is_string($payload[$field]) && preg_match('/\A-?\d+\z/', $payload[$field]) !== 1)) {
                    $errors[$field] = "$field must be an integer.";
                }
            }
        }

        foreach (['date_on_sale_from', 'date_on_sale_to'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== null && $payload[$field] !== '') {
                $normalizedDate = self::normalizeWcDate($payload[$field]);
                if ($normalizedDate === null) {
                    $errors[$field] = "$field is not a valid date.";
                } else {
                    $payload[$field] = $normalizedDate;
                }
            }
        }

        foreach (['virtual', 'downloadable', 'featured', 'sold_individually', 'reviews_allowed'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null) {
                $payload[$field] = self::booleanParam($payload[$field], $field) ? 1 : 0;
            }
        }

        if (isset($payload['dimensions']) && is_array($payload['dimensions'])) {
            foreach (['length', 'width', 'height'] as $dimension) {
                if (array_key_exists($dimension, $payload['dimensions'])) {
                    $payload[$dimension] = $payload['dimensions'][$dimension];
                }
            }
            unset($payload['dimensions']);
        }

        if (isset($payload['slug'])) {
            if (!is_string($payload['slug']) || trim($payload['slug']) === '') {
                $errors['slug'] = 'slug must be a non-empty string.';
            }
        }

        if (isset($payload['sku']) && is_string($payload['sku']) && trim($payload['sku']) === '') {
            unset($payload['sku']);
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

    /**
     * Accept WC date shapes (ISO8601 with/without Z & fraction, space
     * separated, plain date) and normalize to UTC "Y-m-d H:i:s".
     */
    private static function normalizeWcDate(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Rebuild a page-±1 absolute URL preserving other query params (WC Link semantics).
     */
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
