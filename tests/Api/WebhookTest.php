<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\AppTestCase;

/**
 * Webhooks API: /webhooks (CRUD + batch), /webhooks/{id}/deliveries (list, show, retry).
 */
final class WebhookTest extends AppTestCase
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

    public function testWebhooksRequireAuthentication(): void
    {
        $response = $this->get('/api/fc/v3/webhooks');
        $response->assertStatus(401);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_cannot_authenticate', $body['code'] ?? null);
    }

    public function testListWebhooks(): void
    {
        $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Order Created',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook/order',
        ])->assertStatus(201);

        $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Product Updated',
            'topic' => 'product.updated',
            'delivery_url' => 'https://example.com/webhook/product',
        ])->assertStatus(201);

        $response = $this->get('/api/fc/v3/webhooks', $this->auth());
        $response->assertOk()->assertHeader('X-WP-Total', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body);
        self::assertSame('Order Created', $body[0]['name']);
        self::assertArrayHasKey('_links', $body[0]);
    }

    public function testCreateWebhook(): void
    {
        $response = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test Webhook',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
            'status' => 'active',
        ]);

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertGreaterThan(0, $body['id']);
        self::assertSame('Test Webhook', $body['name']);
        self::assertSame('order.created', $body['topic']);
        self::assertSame('https://example.com/webhook', $body['delivery_url']);
        self::assertSame('active', $body['status']);
        self::assertNotEmpty($body['secret']);
        self::assertArrayHasKey('_links', $body);
    }

    public function testCreateWebhookRequiresName(): void
    {
        $response = $this->json('POST', '/api/fc/v3/webhooks', [
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('name', array_key_first($body['data']['params'] ?? []));
    }

    public function testCreateWebhookRequiresTopic(): void
    {
        $response = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test',
            'delivery_url' => 'https://example.com/webhook',
        ]);

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('topic', array_key_first($body['data']['params'] ?? []));
    }

    public function testCreateWebhookRequiresDeliveryUrl(): void
    {
        $response = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test',
            'topic' => 'order.created',
        ]);

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('delivery_url', array_key_first($body['data']['params'] ?? []));
    }

    public function testCreateWebhookInvalidDeliveryUrl(): void
    {
        $response = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test',
            'topic' => 'order.created',
            'delivery_url' => 'not-a-url',
        ]);

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('delivery_url', array_key_first($body['data']['params'] ?? []));
    }

    public function testCreateWebhookInvalidStatus(): void
    {
        $response = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
            'status' => 'invalid',
        ]);

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertSame('status', array_key_first($body['data']['params'] ?? []));
    }

    public function testShowWebhook(): void
    {
        $created = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Show Test',
            'topic' => 'order.updated',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->get('/api/fc/v3/webhooks/' . $id, $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('Show Test', $body['name']);
    }

    public function testShowWebhookNotFound(): void
    {
        $response = $this->get('/api/fc/v3/webhooks/9999', $this->auth());
        $response->assertNotFound();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_webhook_invalid_id', $body['code'] ?? null);
    }

    public function testUpdateWebhook(): void
    {
        $created = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Before Update',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        foreach (['PUT', 'PATCH', 'POST'] as $method) {
            $response = $this->json($method, '/api/fc/v3/webhooks/' . $id, [
                'name' => $method . ' Updated',
                'status' => 'paused',
            ]);
            $response->assertOk();
            self::assertSame($method . ' Updated', json_decode($response->baseResponse->body(), true)['name']);
            self::assertSame('paused', json_decode($response->baseResponse->body(), true)['status']);
        }
    }

    public function testUpdateWebhookInvalidStatus(): void
    {
        $created = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->json('PUT', '/api/fc/v3/webhooks/' . $id, ['status' => 'invalid']);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertSame('status', array_key_first($body['data']['params'] ?? []));
    }

    public function testUpdateWebhookInvalidDeliveryUrl(): void
    {
        $created = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->json('PUT', '/api/fc/v3/webhooks/' . $id, ['delivery_url' => 'bad-url']);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertSame('delivery_url', array_key_first($body['data']['params'] ?? []));
    }

    public function testDeleteWebhook(): void
    {
        $created = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'To Delete',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->call('DELETE', '/api/fc/v3/webhooks/' . $id . '?force=true', $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertTrue($body['deleted'] ?? null);
        self::assertSame('To Delete', $body['previous']['name'] ?? null);

        self::assertFalse($this->db->fetchColumn('SELECT id FROM webhooks WHERE id = ?', [$id]));
    }

    public function testDeleteWebhookSoft(): void
    {
        $created = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Soft Delete',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $id = json_decode($created->baseResponse->body(), true)['id'];

        $response = $this->call('DELETE', '/api/fc/v3/webhooks/' . $id, $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertArrayNotHasKey('deleted', $body);
        self::assertSame('disabled', $body['status'] ?? null);
    }

    public function testBatchWebhooks(): void
    {
        $existing = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Keep',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/keep',
        ]);
        $existingId = json_decode($existing->baseResponse->body(), true)['id'];

        $response = $this->json('POST', '/api/fc/v3/webhooks/batch', [
            'create' => [
                ['name' => 'Batch One', 'topic' => 'product.created', 'delivery_url' => 'https://example.com/1'],
                ['name' => 'Batch Two', 'topic' => 'product.updated', 'delivery_url' => 'https://example.com/2'],
            ],
            'update' => [
                ['id' => $existingId, 'name' => 'Kept and Renamed'],
            ],
            'delete' => [],
        ]);

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body['create']);
        self::assertCount(1, $body['update']);
        self::assertSame('Kept and Renamed', $body['update'][0]['name']);
    }

    public function testBatchWebhooksInvalidCreate(): void
    {
        $response = $this->json('POST', '/api/fc/v3/webhooks/batch', [
            'create' => [
                ['topic' => 'order.created', 'delivery_url' => 'https://example.com/webhook'], // missing name
            ],
        ]);

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('name', array_key_first($body['data']['params'] ?? []));
    }

    // --- Deliveries ---

    public function testListDeliveries(): void
    {
        $webhook = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Delivery Test',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $webhookId = json_decode($webhook->baseResponse->body(), true)['id'];

        // Seed some deliveries
        $this->db->execute(
            'INSERT INTO webhook_deliveries (webhook_id, request_method, request_url, request_headers, request_body, status, date_created, date_created_gmt)
             VALUES (?, \'POST\', ?, \'{"Content-Type":"application/json"}\', \'{"test":1}\', \'delivered\', ?, ?)',
            [$webhookId, 'https://example.com/webhook', '2026-11-16 10:00:00', '2026-11-16 10:00:00'],
        );
        $this->db->execute(
            'INSERT INTO webhook_deliveries (webhook_id, request_method, request_url, request_headers, request_body, status, date_created, date_created_gmt)
             VALUES (?, \'POST\', ?, \'{"Content-Type":"application/json"}\', \'{"test":2}\', \'failed\', ?, ?)',
            [$webhookId, 'https://example.com/webhook', '2026-11-16 10:01:00', '2026-11-16 10:01:00'],
        );

        $response = $this->get("/api/fc/v3/webhooks/{$webhookId}/deliveries", $this->auth());
        $response->assertOk()->assertHeader('X-WP-Total', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body);
        // Order is by id DESC (newest first) - failed was inserted second
        self::assertSame('failed', $body[0]['status']);
        self::assertSame('delivered', $body[1]['status']);
        self::assertArrayHasKey('_links', $body[0]);
    }

    public function testListDeliveriesUnknownWebhook(): void
    {
        $response = $this->get('/api/fc/v3/webhooks/9999/deliveries', $this->auth());
        $response->assertNotFound();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_webhook_invalid_id', $body['code'] ?? null);
    }

    public function testShowDelivery(): void
    {
        $webhook = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Delivery Show',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $webhookId = json_decode($webhook->baseResponse->body(), true)['id'];

        $this->db->execute(
            'INSERT INTO webhook_deliveries (webhook_id, request_method, request_url, request_headers, request_body, response_code, response_headers, response_body, duration_ms, status, date_created, date_created_gmt)
             VALUES (?, \'POST\', ?, \'{"Content-Type":"application/json"}\', \'{"order":1}\', 200, \'{"Content-Type":"application/json"}\', \'{"ok":true}\', 150, \'delivered\', ?, ?)',
            [$webhookId, 'https://example.com/webhook', '2026-11-16 10:00:00', '2026-11-16 10:00:00'],
        );
        $deliveryId = (int) $this->db->fetchColumn('SELECT id FROM webhook_deliveries ORDER BY id DESC LIMIT 1');

        $response = $this->get("/api/fc/v3/webhooks/{$webhookId}/deliveries/{$deliveryId}", $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame($deliveryId, $body['id']);
        self::assertSame($webhookId, $body['webhook_id']);
        self::assertSame('POST', $body['request_method']);
        self::assertSame(200, $body['response_code']);
        self::assertSame(150, $body['duration_ms']);
        self::assertSame('delivered', $body['status']);
    }

    public function testShowDeliveryNotFound(): void
    {
        $webhook = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $webhookId = json_decode($webhook->baseResponse->body(), true)['id'];

        $response = $this->get("/api/fc/v3/webhooks/{$webhookId}/deliveries/9999", $this->auth());
        $response->assertNotFound();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_webhook_delivery_invalid_id', $body['code'] ?? null);
    }

    public function testRetryDeliveries(): void
    {
        $webhook = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Retry Test',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $webhookId = json_decode($webhook->baseResponse->body(), true)['id'];

        $this->db->execute(
            'INSERT INTO webhook_deliveries (webhook_id, request_method, request_url, status, attempts, date_created, date_created_gmt)
             VALUES (?, \'POST\', ?, \'failed\', 1, ?, ?)',
            [$webhookId, 'https://example.com/webhook', '2026-11-16 10:00:00', '2026-11-16 10:00:00'],
        );
        $deliveryId1 = (int) $this->db->fetchColumn('SELECT id FROM webhook_deliveries ORDER BY id DESC LIMIT 1');

        $this->db->execute(
            'INSERT INTO webhook_deliveries (webhook_id, request_method, request_url, status, attempts, date_created, date_created_gmt)
             VALUES (?, \'POST\', ?, \'pending\', 2, ?, ?)',
            [$webhookId, 'https://example.com/webhook', '2026-11-16 10:01:00', '2026-11-16 10:01:00'],
        );
        $deliveryId2 = (int) $this->db->fetchColumn('SELECT id FROM webhook_deliveries ORDER BY id DESC LIMIT 1');

        $response = $this->json('POST', "/api/fc/v3/webhooks/{$webhookId}/deliveries/retry", [
            'delivery_ids' => [$deliveryId1, $deliveryId2, 9999],
        ]);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body); // 9999 ignored

        foreach ($body as $d) {
            self::assertSame('retrying', $d['status']);
            self::assertNotNull($d['last_attempt_at']);
        }
    }

    public function testRetryDeliveriesRequiresArray(): void
    {
        $webhook = $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Test',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/webhook',
        ]);
        $webhookId = json_decode($webhook->baseResponse->body(), true)['id'];

        $response = $this->json('POST', "/api/fc/v3/webhooks/{$webhookId}/deliveries/retry", [
            'delivery_ids' => 'not-an-array',
        ]);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('delivery_ids', array_key_first($body['data']['params'] ?? []));
    }

    public function testFilterWebhooksByStatus(): void
    {
        $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Active One',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/1',
            'status' => 'active',
        ]);
        $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Paused One',
            'topic' => 'order.updated',
            'delivery_url' => 'https://example.com/2',
            'status' => 'paused',
        ]);
        $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Disabled One',
            'topic' => 'product.created',
            'delivery_url' => 'https://example.com/3',
            'status' => 'disabled',
        ]);

        $response = $this->get('/api/fc/v3/webhooks?status=active', $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(1, $body);
        self::assertSame('Active One', $body[0]['name']);
    }

    public function testFilterWebhooksByTopic(): void
    {
        $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Order Hook',
            'topic' => 'order.created',
            'delivery_url' => 'https://example.com/1',
        ]);
        $this->json('POST', '/api/fc/v3/webhooks', [
            'name' => 'Product Hook',
            'topic' => 'product.created',
            'delivery_url' => 'https://example.com/2',
        ]);

        $response = $this->get('/api/fc/v3/webhooks?topic=order.created', $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(1, $body);
        self::assertSame('Order Hook', $body[0]['name']);
    }
}