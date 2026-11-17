<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\ProductReviewTransformer;
use App\Domain\Repository\ProductRepository;
use App\Domain\Repository\ProductReviewRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class ProductReviewController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductReviewRepository $reviews,
        private readonly ProductReviewTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    private function ensureProductExists(int $productId): void
    {
        if ($this->products->find($productId) === null) {
            throw new WcApiException(
                'woocommerce_rest_product_invalid_id',
                'Invalid ID.',
                404,
            );
        }
    }

    public function index(Request $request, int $productId): Response
    {
        $this->ensureProductExists($productId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->reviews->normalizeFilters($query);

        $total = $this->reviews->count($productId, $filters);
        $items = $this->reviews->list($productId, $filters, $pagination->limit(), $pagination->offset);

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

    public function show(Request $request, int $productId, int $id): Response
    {
        $this->ensureProductExists($productId);

        $review = $this->reviews->find($productId, $id);

        if ($review === null) {
            throw new WcApiException(
                'woocommerce_rest_review_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($review),
            200,
        );
    }

    public function store(Request $request, int $productId): Response
    {
        $this->ensureProductExists($productId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        foreach (['reviewer_name', 'reviewer_email', 'review'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                $missing[$field] = "$field is required";
            }
        }

        if (isset($payload['rating'])) {
            if (!is_int($payload['rating']) && (is_string($payload['rating']) && preg_match('/\A\d+\z/', $payload['rating']) !== 1)) {
                $missing['rating'] = 'rating must be an integer between 1 and 5';
            } else {
                $rating = (int) $payload['rating'];
                if ($rating < 1 || $rating > 5) {
                    $missing['rating'] = 'rating must be between 1 and 5';
                }
            }
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizePayload($payload);
        $review = $this->reviews->create($productId, $normalized);

        return Response::json(
            $this->transformer->transform($review),
            201,
        );
    }

    public function update(Request $request, int $productId, int $id): Response
    {
        $this->ensureProductExists($productId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $review = $this->reviews->update($productId, $id, $normalized);

        return Response::json(
            $this->transformer->transform($review),
            200,
        );
    }

    public function destroy(Request $request, int $productId, int $id): Response
    {
        $this->ensureProductExists($productId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$review, $deleted] = $this->reviews->delete($productId, $id, $force);
        $data = $this->transformer->transform($review);

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
        $this->ensureProductExists($productId);

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
            foreach (['reviewer_name', 'reviewer_email', 'review'] as $field) {
                if (!isset($item[$field]) || !is_string($item[$field]) || trim($item[$field]) === '') {
                    $missing[$field] = "$field is required";
                }
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $review = $this->reviews->create($productId, $this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($review);
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
            $review = $this->reviews->update($productId, (int) $item['id'], $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($review);
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
            [$review, $wasDeleted] = $this->reviews->delete($productId, (int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($review),
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

        if (isset($payload['status'])) {
            if (!in_array($payload['status'], ['approved', 'hold', 'spam', 'unspam', 'trash'], true)) {
                $errors['status'] = 'status is not one of approved, hold, spam, unspam, trash.';
            }
        }

        if (isset($payload['rating']) && $payload['rating'] !== null) {
            if (!is_int($payload['rating']) && (is_string($payload['rating']) && preg_match('/\A\d+\z/', $payload['rating']) !== 1)) {
                $errors['rating'] = 'rating must be an integer between 1 and 5';
            } else {
                $rating = (int) $payload['rating'];
                if ($rating < 1 || $rating > 5) {
                    $errors['rating'] = 'rating must be between 1 and 5';
                }
            }
        }

        if (isset($payload['verified']) && $payload['verified'] !== null) {
            $payload['verified'] = self::booleanParam($payload['verified'], 'verified') ? 1 : 0;
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