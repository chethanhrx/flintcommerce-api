<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\AppTestCase;

/**
 * Order refunds: nested CRUD under /orders/{orderId}/refunds.
 */
final class OrderRefundTest extends AppTestCase
{
    private string $keyPair = '';
    private int $orderId = 0;

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

        $created = $this->postJson('/api/fc/v3/orders', $this->orderPayload(), $this->auth());
        $created->assertStatus(201);
        $this->orderId = (int) json_decode($created->baseResponse->body(), true)['id'];
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

    private function orderPayload(): array
    {
        return [
            'line_items' => [['product_id' => 1, 'quantity' => 1]],
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
        ];
    }

    private function refundUri(string $suffix = ''): string
    {
        return '/api/fc/v3/orders/' . $this->orderId . '/refunds' . $suffix;
    }

    public function testRefundsRequireAuthentication(): void
    {
        $response = $this->get($this->refundUri());

        $response->assertStatus(401);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_cannot_authenticate', $body['code'] ?? null);
    }

    public function testCreateRefund(): void
    {
        $response = $this->postJson($this->refundUri(), ['amount' => '5.00', 'reason' => 'Damaged item.'], $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertGreaterThan(0, $body['id']);
        self::assertSame($this->orderId, $body['order_id']);
        self::assertSame('5.00', $body['amount']);
        self::assertSame('Damaged item.', $body['reason']);
        self::assertSame('pending', $body['status']);
        self::assertNotNull($body['date_created_gmt']);
    }

    public function testCreateRefundRequiresAmount(): void
    {
        $response = $this->postJson($this->refundUri(), ['reason' => 'No amount.'], $this->auth());

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('amount', array_key_first($body['data']['params'] ?? []));
    }

    public function testCreateRefundRejectsNegativeAmount(): void
    {
        $response = $this->postJson($this->refundUri(), ['amount' => '-1.00'], $this->auth());

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertArrayHasKey('amount', $body['data']['params'] ?? []);
    }

    public function testRefundsOnMissingOrder(): void
    {
        $missing = $this->postJson('/api/fc/v3/orders/9999/refunds', ['amount' => '1.00'], $this->auth());
        $missing->assertNotFound();

        $body = json_decode($missing->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_order_invalid_id', $body['code'] ?? null);

        $list = $this->get('/api/fc/v3/orders/9999/refunds', $this->auth());
        $list->assertNotFound();
    }

    public function testListRefunds(): void
    {
        $this->postJson($this->refundUri(), ['amount' => '1.00'], $this->auth())->assertStatus(201);
        $this->postJson($this->refundUri(), ['amount' => '2.00'], $this->auth())->assertStatus(201);

        $response = $this->get($this->refundUri(), $this->auth());

        $response->assertOk()->assertHeader('X-WP-Total', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body);
        self::assertSame('1.00', $body[0]['amount']);
    }

    public function testShowRefund(): void
    {
        $created = $this->postJson($this->refundUri(), ['amount' => '3.00'], $this->auth());
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->get($this->refundUri('/' . $id), $this->auth());
        $response->assertOk();
        self::assertSame('3.00', json_decode($response->baseResponse->body(), true)['amount']);

        $missing = $this->get($this->refundUri('/9999'), $this->auth());
        $missing->assertNotFound();

        $body = json_decode($missing->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_order_refund_invalid_id', $body['code'] ?? null);
    }

    public function testUpdateRefund(): void
    {
        $created = $this->postJson($this->refundUri(), ['amount' => '3.00'], $this->auth());
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $put = $this->json('PUT', $this->refundUri('/' . $id), ['reason' => 'Updated reason.']);
        $put->assertOk();
        self::assertSame('Updated reason.', json_decode($put->baseResponse->body(), true)['reason']);

        $status = $this->json('PATCH', $this->refundUri('/' . $id), ['status' => 'completed']);
        $status->assertOk();
        self::assertSame('completed', json_decode($status->baseResponse->body(), true)['status']);

        $bad = $this->json('POST', $this->refundUri('/' . $id), ['status' => 'bogus']);
        $bad->assertStatus(400);

        $body = json_decode($bad->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertSame('status', array_key_first($body['data']['params'] ?? []));
    }

    public function testDeleteRefund(): void
    {
        $created = $this->postJson($this->refundUri(), ['amount' => '3.00'], $this->auth());
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->call('DELETE', $this->refundUri('/' . $id . '?force=true'), $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertTrue($body['deleted'] ?? null);
        self::assertSame('3.00', $body['previous']['amount'] ?? null);

        self::assertFalse($this->db->fetchColumn(
            'SELECT id FROM order_refunds WHERE order_id = ? AND id = ?',
            [$this->orderId, $id],
        ));
    }

    public function testBatchRefunds(): void
    {
        $existing = $this->postJson($this->refundUri(), ['amount' => '1.00'], $this->auth());
        $existingId = json_decode($existing->baseResponse->body(), true)['id'];

        $response = $this->postJson($this->refundUri('/batch'), [
            'create' => [['amount' => '2.00'], ['amount' => '3.00']],
            'update' => [['id' => $existingId, 'reason' => 'Batch reason.']],
            'delete' => [],
        ], $this->auth());

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body['create']);
        self::assertCount(1, $body['update']);
        self::assertSame('Batch reason.', $body['update'][0]['reason']);
    }
}
