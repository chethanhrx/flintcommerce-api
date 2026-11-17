<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\ShippingZoneTransformer;
use App\Api\Transformer\ShippingZoneLocationTransformer;
use App\Api\Transformer\ShippingZoneMethodTransformer;
use App\Domain\Repository\ShippingZoneRepository;
use App\Domain\Repository\ShippingZoneLocationRepository;
use App\Domain\Repository\ShippingZoneMethodRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class ShippingZoneController
{
    public function __construct(
        private readonly ShippingZoneRepository $zones,
        private readonly ShippingZoneLocationRepository $locations,
        private readonly ShippingZoneMethodRepository $methods,
        private readonly ShippingZoneTransformer $zoneTransformer,
        private readonly ShippingZoneLocationTransformer $locationTransformer,
        private readonly ShippingZoneMethodTransformer $methodTransformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->zones->normalizeFilters($query);

        $total = $this->zones->count($filters);
        $items = $this->zones->list($filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($z): array => $this->zoneTransformer->transform($z),
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
        $zone = $this->zones->find($id);

        if ($zone === null) {
            throw new WcApiException(
                'woocommerce_rest_shipping_zone_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->zoneTransformer->transform($zone),
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
        $zone = $this->zones->create($normalized);

        return Response::json(
            $this->zoneTransformer->transform($zone),
            201,
        );
    }

    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizePayload($payload);
        $zone = $this->zones->update($id, $normalized);

        return Response::json(
            $this->zoneTransformer->transform($zone),
            200,
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$zone, $deleted] = $this->zones->delete($id, $force);
        $data = $this->zoneTransformer->transform($zone);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    // Zone locations
    public function locationsIndex(Request $request, int $zoneId): Response
    {
        $this->ensureZoneExists($zoneId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->locations->normalizeFilters($query);

        $total = $this->locations->count($zoneId, $filters);
        $items = $this->locations->list($zoneId, $filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($l): array => $this->locationTransformer->transform($l),
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

    public function locationsStore(Request $request, int $zoneId): Response
    {
        $this->ensureZoneExists($zoneId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['type']) || !in_array($payload['type'], ['country', 'state', 'postcode', 'city', 'continent'], true)) {
            $missing['type'] = 'type must be country, state, postcode, city, or continent.';
        }
        if (!isset($payload['code']) || !is_string($payload['code']) || trim($payload['code']) === '') {
            $missing['code'] = 'code is required.';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizeLocationPayload($payload);
        $location = $this->locations->create($zoneId, $normalized);

        return Response::json(
            $this->locationTransformer->transform($location),
            201,
        );
    }

    public function locationsShow(Request $request, int $zoneId, int $id): Response
    {
        $this->ensureZoneExists($zoneId);

        $location = $this->locations->find($zoneId, $id);

        if ($location === null) {
            throw new WcApiException(
                'woocommerce_rest_shipping_zone_location_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->locationTransformer->transform($location),
            200,
        );
    }

    public function locationsUpdate(Request $request, int $zoneId, int $id): Response
    {
        $this->ensureZoneExists($zoneId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizeLocationPayload($payload);
        $location = $this->locations->update($zoneId, $id, $normalized);

        return Response::json(
            $this->locationTransformer->transform($location),
            200,
        );
    }

    public function locationsDestroy(Request $request, int $zoneId, int $id): Response
    {
        $this->ensureZoneExists($zoneId);

        $this->locations->delete($zoneId, $id);

        return Response::json(['deleted' => true], 200);
    }

    // Zone methods
    public function methodsIndex(Request $request, int $zoneId): Response
    {
        $this->ensureZoneExists($zoneId);

        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->methods->normalizeFilters($query);

        $total = $this->methods->count($zoneId, $filters);
        $items = $this->methods->list($zoneId, $filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($m): array => $this->methodTransformer->transform($m),
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

    public function methodsStore(Request $request, int $zoneId): Response
    {
        $this->ensureZoneExists($zoneId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['method_id']) || !is_string($payload['method_id']) || trim($payload['method_id']) === '') {
            $missing['method_id'] = 'method_id is required.';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizeMethodPayload($payload);
        $method = $this->methods->create($zoneId, $normalized);

        return Response::json(
            $this->methodTransformer->transform($method),
            201,
        );
    }

    public function methodsShow(Request $request, int $zoneId, int $id): Response
    {
        $this->ensureZoneExists($zoneId);

        $method = $this->methods->find($zoneId, $id);

        if ($method === null) {
            throw new WcApiException(
                'woocommerce_rest_shipping_zone_method_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->methodTransformer->transform($method),
            200,
        );
    }

    public function methodsUpdate(Request $request, int $zoneId, int $id): Response
    {
        $this->ensureZoneExists($zoneId);

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizeMethodPayload($payload);
        $method = $this->methods->update($zoneId, $id, $normalized);

        return Response::json(
            $this->methodTransformer->transform($method),
            200,
        );
    }

    public function methodsDestroy(Request $request, int $zoneId, int $id): Response
    {
        $this->ensureZoneExists($zoneId);

        $this->methods->delete($zoneId, $id);

        return Response::json(['deleted' => true], 200);
    }

    private function ensureZoneExists(int $zoneId): void
    {
        $zone = $this->zones->find($zoneId);

        if ($zone === null) {
            throw new WcApiException(
                'woocommerce_rest_shipping_zone_invalid_id',
                'Invalid ID.',
                404,
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

        if (isset($payload['name']) && (!is_string($payload['name']) || trim($payload['name']) === '')) {
            $errors['name'] = 'name must be a non-empty string.';
        }

        if ($errors !== []) {
            throw WcApiException::invalidParam($errors);
        }

        return $payload;
    }

    /**


     * @param array<string, mixed> $payload


     * @return array<string, mixed>


     */


    private function normalizeLocationPayload(array $payload): array
    {
        $errors = [];

        if (isset($payload['type']) && !in_array($payload['type'], ['country', 'state', 'postcode', 'city', 'continent'], true)) {
            $errors['type'] = 'type must be country, state, postcode, city, or continent.';
        }

        if ($errors !== []) {
            throw WcApiException::invalidParam($errors);
        }

        return $payload;
    }

    /**


     * @param array<string, mixed> $payload


     * @return array<string, mixed>


     */


    private function normalizeMethodPayload(array $payload): array
    {
        $errors = [];

        if (isset($payload['is_enabled']) && $payload['is_enabled'] !== null) {
            $payload['is_enabled'] = self::booleanParam($payload['is_enabled'], 'is_enabled') ? 1 : 0;
        }

        if (isset($payload['settings']) && is_array($payload['settings'])) {
            // Settings are key => value pairs; preserve keys.
            $payload['settings'] = json_encode($payload['settings']);
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