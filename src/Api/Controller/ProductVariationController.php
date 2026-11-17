<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\ProductVariationTransformer;
use App\Domain\Repository\ProductRepository;
use App\Domain\Repository\ProductVariationRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class ProductVariationController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductVariationRepository $variations,
        private readonly ProductVariationTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request, int $productId): Response
    {
        $this->ensureVariableProduct($productId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->variations->normalizeFilters($query);

        $total = $this->variations->count($productId, $filters);
        $items = $this->variations->list($productId, $filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($v): array => $this->transformer->transform($v),
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

    public function show(Request $request, int $productId, int $id): Response
    {
        $this->ensureVariableProduct($productId);

        $variation = $this->variations->find($productId, $id);

        if ($variation === null) {
            throw new WcApiException(
                'woocommerce_rest_product_variation_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($variation),
            200,
        );
    }

    public function store(Request $request, int $productId): Response
    {
        $this->ensureVariableProduct($productId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $variation = $this->variations->create($productId, $normalized);

        return Response::json(
            $this->transformer->transform($variation),
            201,
        );
    }

    public function update(Request $request, int $productId, int $id): Response
    {
        $this->ensureVariableProduct($productId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $variation = $this->variations->update($productId, $id, $normalized);

        return Response::json(
            $this->transformer->transform($variation),
            200,
        );
    }

    public function destroy(Request $request, int $productId, int $id): Response
    {
        $this->ensureVariableProduct($productId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$variation, $deleted] = $this->variations->delete($productId, $id, $force);
        $data = $this->transformer->transform($variation);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    public function batch(Request $request, int $productId): Response
    {
        $this->ensureVariableProduct($productId);

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
            $variation = $this->variations->create($productId, $this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($variation);
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
            $variation = $this->variations->update($productId, $id, $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($variation);
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
            [$variation, $wasDeleted] = $this->variations->delete($productId, (int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($variation),
            ];
            unset($wasDeleted);
        }

        return Response::json($result, 200);
    }

    private function ensureVariableProduct(int $productId): void
    {
        $product = $this->products->find($productId);

        if ($product === null) {
            throw new WcApiException(
                'woocommerce_rest_product_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        if (($product->type ?? 'simple') !== 'variable') {
            throw new WcApiException(
                'woocommerce_rest_invalid_parent_product',
                'Parent product must be of type variable.',
                400,
            );
        }
    }

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

        foreach (['regular_price', 'sale_price', 'price'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== '' && $payload[$field] !== null) {
                if (!is_numeric($payload[$field]) || (float) $payload[$field] < 0) {
                    $errors[$field] = "$field must be a non-negative number.";
                }
            }
        }

        foreach (['stock_quantity', 'download_limit', 'download_expiry', 'menu_order', 'shipping_class_id'] as $field) {
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

        foreach (['virtual', 'downloadable', 'manage_stock', 'backorders_allowed', 'backordered'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null) {
                $payload[$field] = self::booleanParam($payload[$field], $field) ? 1 : 0;
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

        if (isset($payload['dimensions']) && is_array($payload['dimensions'])) {
            foreach (['length', 'width', 'height'] as $dimension) {
                if (array_key_exists($dimension, $payload['dimensions'])) {
                    $payload[$dimension] = $payload['dimensions'][$dimension];
                }
            }
            unset($payload['dimensions']);
        }

        if (isset($payload['image']) && is_array($payload['image'])) {
            $payload['image'] = json_encode($payload['image']);
        }

        if (isset($payload['attributes']) && is_array($payload['attributes'])) {
            $payload['attributes'] = json_encode(array_values($payload['attributes']));
        }

        if (isset($payload['downloads']) && is_array($payload['downloads'])) {
            $payload['downloads'] = json_encode(array_values($payload['downloads']));
        }

        if (isset($payload['meta_data']) && is_array($payload['meta_data'])) {
            $payload['meta_data'] = json_encode(array_values($payload['meta_data']));
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