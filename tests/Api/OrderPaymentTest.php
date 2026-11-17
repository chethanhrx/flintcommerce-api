<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\AppTestCase;

/**
 * Payment gateway integration with the order flow: payment_method must
 * reference an existing, enabled gateway (by id_key); payment_method_title
 * is auto-filled from the gateway title when omitted.
 */
final class OrderPaymentTest extends AppTestCase
{
    private string $keyPair = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function seed(): void
    {
        $now = '2026-11-16 10:00:00';

        $this->keyPair = 'ck_' . bin2hex(random_bytes(20)) . ':cs_' . bin2hex(random_bytes(20));
        [$ck, $cs] = explode(':', $this->keyPair);

        $this->db->execute(
            'INSERT INTO api_keys (description, permissions, consumer_key, consumer_secret, truncated_key, created_at, updated_at)
             VALUES (?, \'read_write\', ?, ?, ?, ?, ?)',
            ['test key', hash_hmac('sha256', $ck, 'wc-api'), $cs, substr($ck, -7), $now, $now],
        );

        $this->db->execute(
            'INSERT INTO products (
                name, slug, type, status, sku, price, regular_price, sale_price,
                manage_stock, stock_quantity, stock_status, menu_order, created_at, updated_at
            ) VALUES (\'Test product\', \'test-product\', \'simple\', \'publish\', \'tp-1\', \'10.00\', \'10.00\', \'\', 0, NULL, \'instock\', 0, ?, ?)',
            [$now, $now],
        );

        foreach ([['stripe', 'Stripe', 1], ['bacs', 'Bank transfer', 0]] as [$idKey, $title, $enabled]) {
            $this->db->execute(
                'INSERT INTO payment_gateways (id_key, title, description, order_, enabled, created_at, updated_at)
                 VALUES (?, ?, \'\', 0, ?, ?, ?)',
                [$idKey, $title, $enabled, $now, $now],
            );
        }
    }

    private function auth(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode($this->keyPair)];
    }

    private function json(string $method, string $uri, array $data = []): \FlintPHP\Framework\Testing\TestResponse
    {
        return $this->call(
            $method,
            $uri,
            [...$this->auth(), 'Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private function orderPayload(array $overrides = []): array
    {
        return [
            'line_items' => [['product_id' => 1, 'quantity' => 2]],
            'billing' => [
                'first_name' => 'Ada', 'last_name' => 'Lovelace',
                'address_1' => '1 Analytical Way', 'city' => 'London',
                'state' => 'LND', 'postcode' => 'E1 1AA', 'country' => 'GB',
                'email' => 'ada@example.com',
            ],
            'shipping' => [
                'first_name' => 'Ada', 'last_name' => 'Lovelace',
                'address_1' => '1 Analytical Way', 'city' => 'London',
                'state' => 'LND', 'postcode' => 'E1 1AA', 'country' => 'GB',
            ],
            ...$overrides,
        ];
    }

    public function testCreateOrderWithEnabledGatewayAutofillsTitle(): void
    {
        $response = $this->postJson('/api/fc/v3/orders', $this->orderPayload([
            'payment_method' => 'stripe',
        ]), $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('stripe', $body['payment_method']);
        self::assertSame('Stripe', $body['payment_method_title']);
    }

    public function testCreateOrderWithExplicitTitleIsRespected(): void
    {
        $response = $this->postJson('/api/fc/v3/orders', $this->orderPayload([
            'payment_method' => 'stripe',
            'payment_method_title' => 'Credit card',
        ]), $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('Credit card', $body['payment_method_title']);
    }

    public function testCreateOrderWithoutGateway(): void
    {
        $response = $this->postJson('/api/fc/v3/orders', $this->orderPayload(), $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('', $body['payment_method']);
        self::assertSame('', $body['payment_method_title']);
    }

    public function testCreateOrderWithUnknownGatewayFails(): void
    {
        $response = $this->postJson('/api/fc/v3/orders', $this->orderPayload([
            'payment_method' => 'carrier_pigeon',
        ]), $this->auth());

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertArrayHasKey('payment_method', $body['data']['params'] ?? []);
    }

    public function testCreateOrderWithDisabledGatewayFails(): void
    {
        $response = $this->postJson('/api/fc/v3/orders', $this->orderPayload([
            'payment_method' => 'bacs',
        ]), $this->auth());

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertArrayHasKey('payment_method', $body['data']['params'] ?? []);
    }

    public function testUpdateOrderPaymentMethodAutofillsTitle(): void
    {
        $created = $this->postJson('/api/fc/v3/orders', $this->orderPayload(), $this->auth());
        $created->assertStatus(201);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->json('PUT', '/api/fc/v3/orders/' . $id, ['payment_method' => 'stripe']);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('stripe', $body['payment_method']);
        self::assertSame('Stripe', $body['payment_method_title']);
    }

    public function testUpdateOrderToUnknownGatewayFails(): void
    {
        $created = $this->postJson('/api/fc/v3/orders', $this->orderPayload(), $this->auth());
        $created->assertStatus(201);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->json('PUT', '/api/fc/v3/orders/' . $id, ['payment_method' => 'nope']);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
    }

    public function testUpdateOrderClearingGatewayClearsTitle(): void
    {
        $created = $this->postJson('/api/fc/v3/orders', $this->orderPayload([
            'payment_method' => 'stripe',
        ]), $this->auth());
        $created->assertStatus(201);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->json('PUT', '/api/fc/v3/orders/' . $id, ['payment_method' => '']);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('', $body['payment_method']);
        self::assertSame('', $body['payment_method_title']);
    }

    public function testCreateOrderTotalsWithGateway(): void
    {
        // Sanity: gateway integration must not disturb totals math.
        $response = $this->postJson('/api/fc/v3/orders', $this->orderPayload([
            'payment_method' => 'stripe',
        ]), $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('20.00', $body['total']);
        self::assertCount(1, $body['line_items']);
    }
}
