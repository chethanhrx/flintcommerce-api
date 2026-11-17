<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\AppTestCase;

/**
 * Order notes: nested CRUD under /orders/{orderId}/notes.
 */
final class OrderNoteTest extends AppTestCase
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

    private function noteUri(int $orderId, string $suffix = ''): string
    {
        return '/api/fc/v3/orders/' . $orderId . '/notes' . $suffix;
    }

    public function testNotesRequireAuthentication(): void
    {
        $response = $this->get($this->noteUri($this->orderId));

        $response->assertStatus(401);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_cannot_authenticate', $body['code'] ?? null);
    }

    public function testCreateNote(): void
    {
        $response = $this->postJson($this->noteUri($this->orderId), ['note' => 'Payment received.'], $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertGreaterThan(0, $body['id']);
        self::assertSame('Payment received.', $body['note']);
        self::assertFalse($body['customer_note']);
        self::assertNotNull($body['date_created_gmt']);
    }

    public function testCreateCustomerNote(): void
    {
        $response = $this->postJson($this->noteUri($this->orderId), ['note' => 'Visible to customer.', 'customer_note' => true], $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertTrue($body['customer_note']);
    }

    public function testCreateNoteRequiresNote(): void
    {
        $response = $this->postJson($this->noteUri($this->orderId), [], $this->auth());

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('note', array_key_first($body['data']['params'] ?? []));
    }

    public function testNotesOnMissingOrder(): void
    {
        $list = $this->get($this->noteUri(9999), $this->auth());
        $list->assertNotFound();

        $body = json_decode($list->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_order_invalid_id', $body['code'] ?? null);

        $created = $this->postJson($this->noteUri(9999), ['note' => 'x'], $this->auth());
        $created->assertNotFound();
    }

    public function testListNotes(): void
    {
        $this->postJson($this->noteUri($this->orderId), ['note' => 'First.'], $this->auth())->assertStatus(201);
        $this->postJson($this->noteUri($this->orderId), ['note' => 'Second.'], $this->auth())->assertStatus(201);

        $response = $this->get($this->noteUri($this->orderId), $this->auth());

        $response->assertOk()->assertHeader('X-WP-Total', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body);
        self::assertSame('First.', $body[0]['note']);
    }

    public function testShowNote(): void
    {
        $created = $this->postJson($this->noteUri($this->orderId), ['note' => 'Shown.'], $this->auth());
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->get($this->noteUri($this->orderId, '/' . $id), $this->auth());
        $response->assertOk();
        self::assertSame('Shown.', json_decode($response->baseResponse->body(), true)['note']);

        $missing = $this->get($this->noteUri($this->orderId, '/9999'), $this->auth());
        $missing->assertNotFound();

        $body = json_decode($missing->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_order_note_invalid_id', $body['code'] ?? null);
    }

    public function testUpdateNote(): void
    {
        $created = $this->postJson($this->noteUri($this->orderId), ['note' => 'Before.'], $this->auth());
        $id = json_decode($created->baseResponse->body(), true)['id'];

        foreach (['PUT', 'PATCH', 'POST'] as $method) {
            $response = $this->json($method, $this->noteUri($this->orderId, '/' . $id), ['note' => $method . ' edit']);
            $response->assertOk();
            self::assertSame($method . ' edit', json_decode($response->baseResponse->body(), true)['note']);
        }
    }

    public function testDeleteNote(): void
    {
        $created = $this->postJson($this->noteUri($this->orderId), ['note' => 'Doomed.'], $this->auth());
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->call('DELETE', $this->noteUri($this->orderId, '/' . $id . '?force=true'), $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertTrue($body['deleted'] ?? null);
        self::assertSame('Doomed.', $body['previous']['note'] ?? null);

        self::assertFalse($this->db->fetchColumn(
            'SELECT id FROM order_notes WHERE order_id = ? AND id = ?',
            [$this->orderId, $id],
        ));
    }

    public function testBatchNotes(): void
    {
        $existing = $this->postJson($this->noteUri($this->orderId), ['note' => 'Keep.'], $this->auth());
        $existingId = json_decode($existing->baseResponse->body(), true)['id'];

        $response = $this->postJson($this->noteUri($this->orderId, '/batch'), [
            'create' => [['note' => 'Batch one.'], ['note' => 'Batch two.']],
            'update' => [['id' => $existingId, 'note' => 'Kept and edited.']],
            'delete' => [],
        ], $this->auth());

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body['create']);
        self::assertCount(1, $body['update']);
        self::assertSame('Kept and edited.', $body['update'][0]['note']);
    }
}
