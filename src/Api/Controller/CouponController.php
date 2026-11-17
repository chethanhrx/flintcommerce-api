<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\CouponTransformer;
use App\Domain\Repository\CouponRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class CouponController
{
    public function __construct(
        private readonly CouponRepository $coupons,
        private readonly CouponTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->coupons->normalizeFilters($query);

        $total = $this->coupons->count($filters);
        $items = $this->coupons->list($filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($c): array => $this->transformer->transform($c),
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
        $coupon = $this->coupons->find($id);

        if ($coupon === null) {
            throw new WcApiException(
                'woocommerce_rest_coupon_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($coupon),
            200,
        );
    }

    public function store(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['code']) || !is_string($payload['code']) || trim($payload['code']) === '') {
            $missing['code'] = 'code is required';
        }
        if (!isset($payload['amount']) || !is_numeric($payload['amount']) || (float) $payload['amount'] < 0) {
            $missing['amount'] = 'amount must be a non-negative number';
        }
        if (!isset($payload['discount_type']) || !in_array($payload['discount_type'], ['fixed_cart', 'percent', 'fixed_product', 'percent_product'], true)) {
            $missing['discount_type'] = 'discount_type is not one of fixed_cart, percent, fixed_product, percent_product.';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizePayload($payload);
        $coupon = $this->coupons->create($normalized);

        return Response::json(
            $this->transformer->transform($coupon),
            201,
        );
    }

    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $coupon = $this->coupons->update($id, $normalized);

        return Response::json(
            $this->transformer->transform($coupon),
            200,
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$coupon, $deleted] = $this->coupons->delete($id, $force);
        $data = $this->transformer->transform($coupon);

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
            if (!isset($item['code']) || !is_string($item['code']) || trim($item['code']) === '') {
                $missing['code'] = 'code is required';
            }
            if (!isset($item['amount']) || !is_numeric($item['amount']) || (float) $item['amount'] < 0) {
                $missing['amount'] = 'amount must be a non-negative number';
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $coupon = $this->coupons->create($this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($coupon);
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
            $coupon = $this->coupons->update((int) $item['id'], $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($coupon);
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
            [$coupon, $wasDeleted] = $this->coupons->delete((int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($coupon),
            ];
            unset($wasDeleted);
        }

        return Response::json($result, 200);
    }

    /**
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

        if (isset($payload['amount']) && $payload['amount'] !== '' && $payload['amount'] !== null) {
            if (!is_numeric($payload['amount']) || (float) $payload['amount'] < 0) {
                $errors['amount'] = 'amount must be a non-negative number.';
            }
        }

        if (isset($payload['discount_type']) && !in_array($payload['discount_type'], ['fixed_cart', 'percent', 'fixed_product', 'percent_product'], true)) {
            $errors['discount_type'] = 'discount_type is not one of fixed_cart, percent, fixed_product, percent_product.';
        }

        if (isset($payload['usage_limit']) && $payload['usage_limit'] !== null) {
            if (!is_int($payload['usage_limit']) && (is_string($payload['usage_limit']) && preg_match('/\A\d+\z/', $payload['usage_limit']) !== 1)) {
                $errors['usage_limit'] = 'usage_limit must be an integer.';
            }
        }

        if (isset($payload['usage_limit_per_user']) && $payload['usage_limit_per_user'] !== null) {
            if (!is_int($payload['usage_limit_per_user']) && (is_string($payload['usage_limit_per_user']) && preg_match('/\A\d+\z/', $payload['usage_limit_per_user']) !== 1)) {
                $errors['usage_limit_per_user'] = 'usage_limit_per_user must be an integer.';
            }
        }

        if (isset($payload['limit_usage_to_x_items']) && $payload['limit_usage_to_x_items'] !== null) {
            if (!is_int($payload['limit_usage_to_x_items']) && (is_string($payload['limit_usage_to_x_items']) && preg_match('/\A\d+\z/', $payload['limit_usage_to_x_items']) !== 1)) {
                $errors['limit_usage_to_x_items'] = 'limit_usage_to_x_items must be an integer.';
            }
        }

        foreach (['free_shipping', 'individual_use', 'exclude_sale_items'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null) {
                $payload[$field] = self::booleanParam($payload[$field], $field) ? 1 : 0;
            }
        }

        foreach (['minimum_amount', 'maximum_amount', 'amount'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== '' && $payload[$field] !== null) {
                if (!is_numeric($payload[$field]) || (float) $payload[$field] < 0) {
                    $errors[$field] = "$field must be a non-negative number.";
                }
            }
        }

        foreach (['product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'used_by'] as $field) {
            if (isset($payload[$field]) && is_array($payload[$field])) {
                $payload[$field] = json_encode(array_values(array_map('intval', $payload[$field])));
            } elseif (isset($payload[$field]) && $payload[$field] === '') {
                unset($payload[$field]);
            }
        }

        if (isset($payload['email_restrictions']) && is_array($payload['email_restrictions'])) {
            $payload['email_restrictions'] = json_encode(array_values(array_map('strval', $payload['email_restrictions'])));
        }

        if (isset($payload['code']) && is_string($payload['code']) && trim($payload['code']) === '') {
            unset($payload['code']);
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

        return $this->baseUrl . $request->path() . (count($parts) === 0 ? '' : '?' . implode('&', $parts));
    }
}