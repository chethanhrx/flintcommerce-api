<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\AppTestCase;

final class PaymentGatewayTest extends AppTestCase
{
    private string $keyPair = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKey();
    }

    private function seedKey(): void
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

    private function createGateway(string $idKey = 'stripe', string $title = 'Stripe'): int
    {
        $response = $this->postJson('/api/fc/v3/payment_gateways', ['id_key' => $idKey, 'title' => $title], $this->auth());
        $response->assertStatus(201);

        return (int) json_decode($response->baseResponse->body(), true)['id'];
    }

    public function testGatewaysRequireAuthentication(): void
    {
        $response = $this->get('/api/fc/v3/payment_gateways');

        $response->assertStatus(401);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_cannot_authenticate', $body['code'] ?? null);
        self::assertSame(401, $body['data']['status'] ?? null);
    }

    public function testCreateAndFetchGateway(): void
    {
        $response = $this->postJson('/api/fc/v3/payment_gateways', [
            'id_key' => 'stripe',
            'title' => 'Stripe',
            'description' => 'Pay by card',
            'enabled' => true,
            'method_supports' => ['products', 'refunds'],
        ], $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertGreaterThan(0, $body['id']);
        self::assertSame('stripe', $body['id_key']);
        self::assertSame('Stripe', $body['title']);
        self::assertTrue($body['enabled']);
        self::assertSame(['products', 'refunds'], $body['method_supports']);

        $fetched = $this->get('/api/fc/v3/payment_gateways/' . $body['id'], $this->auth());
        $fetched->assertOk();
        self::assertSame('stripe', json_decode($fetched->baseResponse->body(), true)['id_key']);
    }

    public function testCreateGatewayRequiresTitleAndIdKey(): void
    {
        $response = $this->postJson('/api/fc/v3/payment_gateways', [], $this->auth());

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);

        $params = $body['data']['params'] ?? [];
        self::assertArrayHasKey('title', $params);
        self::assertArrayHasKey('id_key', $params);
    }

    public function testListGateways(): void
    {
        $this->createGateway('stripe', 'Stripe');
        $this->createGateway('bacs', 'Bank transfer');

        $response = $this->get('/api/fc/v3/payment_gateways', $this->auth());

        $response->assertOk()->assertHeader('X-WP-Total', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body);
    }

    public function testListGatewaysFilterEnabled(): void
    {
        $this->postJson('/api/fc/v3/payment_gateways', ['id_key' => 'stripe', 'title' => 'Stripe', 'order' => 1], $this->auth())->assertStatus(201);
        $cod = $this->postJson('/api/fc/v3/payment_gateways', ['id_key' => 'cod', 'title' => 'Cash on delivery', 'order' => 2], $this->auth())->assertStatus(201);
        $codId = json_decode($cod->baseResponse->body(), true)['id'];
        $this->json('PUT', '/api/fc/v3/payment_gateways/' . $codId, ['enabled' => false]);

        $response = $this->get('/api/fc/v3/payment_gateways?enabled=true', $this->auth());

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(1, $body);
        self::assertSame('stripe', $body[0]['id_key']);
    }

    public function testUpdateGateway(): void
    {
        $id = $this->createGateway();

        $put = $this->json('PUT', '/api/fc/v3/payment_gateways/' . $id, ['enabled' => false, 'settings' => ['secret' => 'sk_test']]);
        $put->assertOk();

        $body = json_decode($put->baseResponse->body(), true);
        self::assertFalse($body['enabled']);
        self::assertSame(['secret' => 'sk_test'], $body['settings']);

        $post = $this->postJson('/api/fc/v3/payment_gateways/' . $id, ['enabled' => true], $this->auth());
        $post->assertOk();
        self::assertTrue(json_decode($post->baseResponse->body(), true)['enabled']);
    }

    public function testDeleteGatewayForceAndDisable(): void
    {
        $id = $this->createGateway('bacs', 'Bank transfer');

        // Without force: disable semantics
        $soft = $this->call('DELETE', '/api/fc/v3/payment_gateways/' . $id, $this->auth());
        $soft->assertOk();
        self::assertFalse(json_decode($soft->baseResponse->body(), true)['enabled']);

        // Force: hard delete
        $hard = $this->call('DELETE', '/api/fc/v3/payment_gateways/' . $id . '?force=true', $this->auth());
        $hard->assertOk();

        $body = json_decode($hard->baseResponse->body(), true);
        self::assertTrue($body['deleted'] ?? null);
        self::assertSame('Bank transfer', $body['previous']['title'] ?? null);

        self::assertFalse($this->db->fetchColumn('SELECT id FROM payment_gateways WHERE id = ?', [$id]));
    }

    public function testGatewayNotFound(): void
    {
        $response = $this->get('/api/fc/v3/payment_gateways/9999', $this->auth());

        $response->assertNotFound();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_payment_gateway_invalid_id', $body['code'] ?? null);
    }

    public function testBatchGateways(): void
    {
        $existingId = $this->createGateway('stripe', 'Stripe');

        $response = $this->postJson('/api/fc/v3/payment_gateways/batch', [
            'create' => [['id_key' => 'bacs', 'title' => 'Bank transfer']],
            'update' => [['id' => $existingId, 'title' => 'Stripe (new)']],
            'delete' => [],
        ], $this->auth());

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(1, $body['create']);
        self::assertSame('bacs', $body['create'][0]['id_key']);
        self::assertCount(1, $body['update']);
        self::assertSame('Stripe (new)', $body['update'][0]['title']);
    }
}
