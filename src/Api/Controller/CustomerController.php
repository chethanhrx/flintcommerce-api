<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\CustomerAddressTransformer;
use App\Api\Transformer\CustomerTransformer;
use App\Domain\Repository\CustomerAddressRepository;
use App\Domain\Repository\CustomerRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class CustomerController
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerAddressRepository $addresses,
        private readonly CustomerTransformer $transformer,
        private readonly CustomerAddressTransformer $addressTransformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->customers->normalizeFilters($query);

        $total = $this->customers->count($filters);
        $items = $this->customers->list($filters, $pagination->limit(), $pagination->offset);

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
        $customer = $this->customers->find($id);

        if ($customer === null) {
            throw new WcApiException(
                'woocommerce_rest_customer_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($customer),
            200,
        );
    }

    public function store(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['email']) || !is_string($payload['email']) || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            $missing['email'] = 'A valid email address is required.';
        }

        if (!isset($payload['password']) || !is_string($payload['password']) || strlen($payload['password']) < 8) {
            $missing['password'] = 'Password must be at least 8 characters.';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizeCreate($payload);
        $customer = $this->customers->create($normalized);

        return Response::json(
            $this->transformer->transform($customer),
            201,
        );
    }

    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizeUpdate($payload);
        $customer = $this->customers->update($id, $normalized);

        return Response::json(
            $this->transformer->transform($customer),
            200,
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$customer, $deleted] = $this->customers->delete($id, $force);
        $data = $this->transformer->transform($customer);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    public function addresses(Request $request, int $customerId): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $type = $query['type'] ?? '';

        $addresses = $this->addresses->list($customerId, $type);

        $payload = array_map(
            fn ($a): array => $this->addressTransformer->transform($a),
            $addresses,
        );

        return Response::json($payload, 200);
    }

    public function address(Request $request, int $customerId, int $id): Response
    {
        $address = $this->addresses->find($customerId, $id);

        if ($address === null) {
            throw new WcApiException(
                'woocommerce_rest_customer_address_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->addressTransformer->transform($address),
            200,
        );
    }

    public function createAddress(Request $request, int $customerId): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['type']) || !in_array($payload['type'], ['billing', 'shipping'], true)) {
            $missing['type'] = 'type must be billing or shipping.';
        }
        if (!isset($payload['first_name']) || !is_string($payload['first_name']) || trim($payload['first_name']) === '') {
            $missing['first_name'] = 'first_name is required.';
        }
        if (!isset($payload['last_name']) || !is_string($payload['last_name']) || trim($payload['last_name']) === '') {
            $missing['last_name'] = 'last_name is required.';
        }
        if (!isset($payload['address_1']) || !is_string($payload['address_1']) || trim($payload['address_1']) === '') {
            $missing['address_1'] = 'address_1 is required.';
        }
        if (!isset($payload['city']) || !is_string($payload['city']) || trim($payload['city']) === '') {
            $missing['city'] = 'city is required.';
        }
        if (!isset($payload['postcode']) || !is_string($payload['postcode']) || trim($payload['postcode']) === '') {
            $missing['postcode'] = 'postcode is required.';
        }
        if (!isset($payload['country']) || !is_string($payload['country']) || trim($payload['country']) === '') {
            $missing['country'] = 'country is required.';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $address = $this->addresses->create($payload['customer_id'] ?? (int) $request->attribute('customerId'), $payload);

        return Response::json(
            $this->addressTransformer->transform($address),
            201,
        );
    }

    public function updateAddress(Request $request, int $customerId, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $address = $this->addresses->update($customerId, $id, $payload);

        return Response::json(
            $this->addressTransformer->transform($address),
            200,
        );
    }

    public function deleteAddress(Request $request, int $customerId, int $id): Response
    {
        $this->addresses->delete($customerId, $id);

        return Response::json(['deleted' => true], 200);
    }

    /**


     * @param array<string, mixed> $payload


     * @return array<string, mixed>


     */


    private function normalizeCreate(array $payload): array
    {
        $errors = [];

        if (isset($payload['username'])) {
            if (!is_string($payload['username']) || strlen($payload['username']) < 3) {
                $errors['username'] = 'Username must be at least 3 characters.';
            } elseif (preg_match('/\A[a-zA-Z0-9_]+\z/', $payload['username']) !== 1) {
                $errors['username'] = 'Username can only contain letters, numbers, and underscores.';
            }
        }

        if (isset($payload['role'])) {
            if (!in_array($payload['role'], ['customer', 'shop_manager'], true)) {
                $errors['role'] = 'role is not one of customer, shop_manager.';
            }
        }

        if (isset($payload['is_active']) && $payload['is_active'] !== null) {
            $payload['is_active'] = self::booleanParam($payload['is_active'], 'is_active') ? 1 : 0;
        }

        if (isset($payload['password'])) {
            $payload['password_hash'] = password_hash($payload['password'], PASSWORD_BCRYPT);
            unset($payload['password']);
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


    private function normalizeUpdate(array $payload): array
    {
        $errors = [];

        if (isset($payload['password'])) {
            if (!is_string($payload['password']) || strlen($payload['password']) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            } else {
                $payload['password_hash'] = password_hash($payload['password'], PASSWORD_BCRYPT);
                unset($payload['password']);
            }
        }

        if (isset($payload['is_active']) && $payload['is_active'] !== null) {
            $payload['is_active'] = self::booleanParam($payload['is_active'], 'is_active') ? 1 : 0;
        }

        if (isset($payload['role'])) {
            if (!in_array($payload['role'], ['customer', 'shop_manager', 'administrator'], true)) {
                $errors['role'] = 'role is not one of customer, shop_manager, administrator.';
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