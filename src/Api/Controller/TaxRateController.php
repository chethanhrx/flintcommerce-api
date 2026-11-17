<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\TaxRateTransformer;
use App\Domain\Repository\TaxClassRepository;
use App\Domain\Repository\TaxRateRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class TaxRateController
{
    public function __construct(
        private readonly \App\Domain\Repository\TaxClassRepository $taxClasses,
        private readonly \App\Domain\Repository\TaxRateRepository $taxRates,
        private readonly TaxRateTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    private function ensureTaxClassExists(int $taxClassId): void
    {
        if ($this->taxClasses->find($taxClassId) === null) {
            throw new WcApiException(
                'woocommerce_rest_tax_class_invalid_id',
                'Invalid tax class ID.',
                404,
            );
        }
    }

    public function index(Request $request, int $taxClassId): Response
    {
        $this->ensureTaxClassExists($taxClassId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->taxRates->normalizeFilters($query);

        $total = $this->taxRates->count($taxClassId, $filters);
        $items = $this->taxRates->list($taxClassId, $filters, $pagination->limit(), $pagination->offset);

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

    public function show(Request $request, int $taxClassId, int $id): Response
    {
        $this->ensureTaxClassExists($taxClassId);

        $rate = $this->taxRates->find($taxClassId, $id);

        if ($rate === null) {
            throw new WcApiException(
                'woocommerce_rest_tax_rate_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($rate),
            200,
        );
    }

    public function store(Request $request, int $taxClassId): Response
    {
        $this->ensureTaxClassExists($taxClassId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['country']) || !is_string($payload['country']) || strlen($payload['country']) !== 2) {
            $missing['country'] = 'country is required and must be a 2-letter ISO code.';
        }
        if (!isset($payload['rate']) || !is_numeric($payload['rate']) || (float) $payload['rate'] < 0) {
            $missing['rate'] = 'rate must be a non-negative number.';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizePayload($payload);
        $rate = $this->taxRates->create($taxClassId, $normalized);

        return Response::json(
            $this->transformer->transform($rate),
            201,
        );
    }

    public function update(Request $request, int $taxClassId, int $id): Response
    {
        $this->ensureTaxClassExists($taxClassId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $rate = $this->taxRates->update($taxClassId, $id, $normalized);

        return Response::json(
            $this->transformer->transform($rate),
            200,
        );
    }

    public function destroy(Request $request, int $taxClassId, int $id): Response
    {
        $this->ensureTaxClassExists($taxClassId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$rate, $deleted] = $this->taxRates->delete($taxClassId, $id, $force);
        $data = $this->transformer->transform($rate);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    public function batch(Request $request, int $taxClassId): Response
    {
        $this->ensureTaxClassExists($taxClassId);

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
            if (!isset($item['country']) || !is_string($item['country']) || strlen($item['country']) !== 2) {
                $missing['country'] = 'country is required and must be a 2-letter ISO code.';
            }
            if (!isset($item['rate']) || !is_numeric($item['rate']) || (float) $item['rate'] < 0) {
                $missing['rate'] = 'rate must be a non-negative number.';
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $rate = $this->taxRates->create($taxClassId, $this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($rate);
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
            $rate = $this->taxRates->update($taxClassId, (int) $item['id'], $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($rate);
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
            [$rate, $wasDeleted] = $this->taxRates->delete($taxClassId, (int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($rate),
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

        if (isset($payload['state']) && !is_string($payload['state'])) {
            $errors['state'] = 'state must be a string.';
        }

        if (isset($payload['postcode']) && !is_string($payload['postcode'])) {
            $errors['postcode'] = 'postcode must be a string.';
        }

        if (isset($payload['city']) && !is_string($payload['city'])) {
            $errors['city'] = 'city must be a string.';
        }

        if (isset($payload['rate']) && $payload['rate'] !== '' && $payload['rate'] !== null) {
            if (!is_numeric($payload['rate']) || (float) $payload['rate'] < 0) {
                $errors['rate'] = 'rate must be a non-negative number.';
            }
        }

        foreach (['priority', 'order'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== null) {
                if (!is_int($payload[$field]) && (is_string($payload[$field]) && preg_match('/\A-?\d+\z/', $payload[$field]) !== 1)) {
                    $errors[$field] = "$field must be an integer.";
                }
            }
        }

        foreach (['compound', 'shipping'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null) {
                $payload[$field] = self::booleanParam($payload[$field], $field) ? 1 : 0;
            }
        }

        if (isset($payload['country'])) {
            $payload['country'] = strtoupper($payload['country']);
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