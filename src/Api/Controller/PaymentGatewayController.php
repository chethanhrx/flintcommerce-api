<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\PaymentGatewayTransformer;
use App\Domain\Repository\PaymentGatewayRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class PaymentGatewayController
{
    public function __construct(
        private readonly \App\Domain\Repository\PaymentGatewayRepository $gateways,
        private readonly \App\Api\Transformer\PaymentGatewayTransformer $transformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->gateways->normalizeFilters($query);

        $total = $this->gateways->count($filters);
        $items = $this->gateways->list($filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($g): array => $this->transformer->transform($g),
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
        $gateway = $this->gateways->find($id);

        if ($gateway === null) {
            throw new WcApiException(
                'woocommerce_rest_payment_gateway_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($gateway),
            200,
        );
    }

    public function store(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['title']) || !is_string($payload['title']) || trim($payload['title']) === '') {
            $missing['title'] = 'title is required';
        }
        if (!isset($payload['id_key']) || !is_string($payload['id_key']) || trim($payload['id_key']) === '') {
            $missing['id_key'] = 'id_key is required';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizePayload($payload);
        $gateway = $this->gateways->create($normalized);

        return Response::json(
            $this->transformer->transform($gateway),
            201,
        );
    }

    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $gateway = $this->gateways->update($id, $normalized);

        return Response::json(
            $this->transformer->transform($gateway),
            200,
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$gateway, $deleted] = $this->gateways->delete($id, $force);
        $data = $this->transformer->transform($gateway);

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
            if (!isset($item['title']) || !is_string($item['title']) || trim($item['title']) === '') {
                $missing['title'] = 'title is required';
            }
            if (!isset($item['id_key']) || !is_string($item['id_key']) || trim($item['id_key']) === '') {
                $missing['id_key'] = 'id_key is required';
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $gateway = $this->gateways->create($this->normalizePayload($item));
            $result['create'][] = $this->transformer->transform($gateway);
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
            $gateway = $this->gateways->update((int) $item['id'], $this->normalizePayload($item));
            $result['update'][] = $this->transformer->transform($gateway);
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
            [$gateway, $wasDeleted] = $this->gateways->delete((int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($gateway),
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

        if (isset($payload['enabled']) && $payload['enabled'] !== null) {
            $payload['enabled'] = self::booleanParam($payload['enabled'], 'enabled') ? 1 : 0;
        }

        if (isset($payload['settings']) && is_array($payload['settings'])) {
            // Settings are key => value pairs; preserve keys.
            $payload['settings'] = json_encode($payload['settings']);
        }

        if (isset($payload['method_supports']) && is_array($payload['method_supports'])) {
            $payload['method_supports'] = json_encode(array_values($payload['method_supports']));
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