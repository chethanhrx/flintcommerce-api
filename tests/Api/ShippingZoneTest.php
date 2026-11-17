<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\AppTestCase;

final class ShippingZoneTest extends AppTestCase
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

    private function createZone(string $name = 'Domestic', int $order = 0): int
    {
        $response = $this->postJson('/api/fc/v3/shipping/zones', ['name' => $name, 'order' => $order], $this->auth());
        $response->assertStatus(201);

        return (int) json_decode($response->baseResponse->body(), true)['id'];
    }

    public function testZonesRequireAuthentication(): void
    {
        $response = $this->get('/api/fc/v3/shipping/zones');

        $response->assertStatus(401);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_cannot_authenticate', $body['code'] ?? null);
        self::assertSame(401, $body['data']['status'] ?? null);
    }

    public function testCreateAndFetchZone(): void
    {
        $response = $this->postJson('/api/fc/v3/shipping/zones', ['name' => 'Domestic', 'order' => 2], $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertGreaterThan(0, $body['id']);
        self::assertSame('Domestic', $body['name']);
        self::assertSame(2, $body['order']);
        self::assertArrayHasKey('_links', $body);
        self::assertArrayHasKey('locations', $body['_links']);
        self::assertArrayHasKey('methods', $body['_links']);

        $fetched = $this->get('/api/fc/v3/shipping/zones/' . $body['id'], $this->auth());
        $fetched->assertOk();
        self::assertSame('Domestic', json_decode($fetched->baseResponse->body(), true)['name']);
    }

    public function testCreateZoneRequiresName(): void
    {
        $response = $this->postJson('/api/fc/v3/shipping/zones', ['order' => 1], $this->auth());

        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('name', array_key_first($body['data']['params'] ?? []));
    }

    public function testListZones(): void
    {
        $this->createZone('Domestic', 1);
        $this->createZone('International', 2);

        $response = $this->get('/api/fc/v3/shipping/zones', $this->auth());

        $response->assertOk()->assertHeader('X-WP-Total', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body);
        self::assertSame('Domestic', $body[0]['name']);
        self::assertSame('International', $body[1]['name']);
    }

    public function testUpdateZone(): void
    {
        $id = $this->createZone('Old name');

        $put = $this->json('PUT', '/api/fc/v3/shipping/zones/' . $id, ['name' => 'New name']);
        $put->assertOk();
        self::assertSame('New name', json_decode($put->baseResponse->body(), true)['name']);

        $patch = $this->json('PATCH', '/api/fc/v3/shipping/zones/' . $id, ['order' => 7]);
        $patch->assertOk();
        self::assertSame(7, json_decode($patch->baseResponse->body(), true)['order']);

        $post = $this->postJson('/api/fc/v3/shipping/zones/' . $id, ['name' => 'Post alias'], $this->auth());
        $post->assertOk();
        self::assertSame('Post alias', json_decode($post->baseResponse->body(), true)['name']);
    }

    public function testDeleteZoneForce(): void
    {
        $id = $this->createZone('Doomed');

        $response = $this->call('DELETE', '/api/fc/v3/shipping/zones/' . $id . '?force=true', $this->auth());

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertTrue($body['deleted'] ?? null);
        self::assertSame('Doomed', $body['previous']['name'] ?? null);

        self::assertFalse($this->db->fetchColumn('SELECT id FROM shipping_zones WHERE id = ?', [$id]));
    }

    public function testZoneNotFound(): void
    {
        $response = $this->get('/api/fc/v3/shipping/zones/9999', $this->auth());

        $response->assertNotFound();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_shipping_zone_invalid_id', $body['code'] ?? null);
    }

    public function testLocationsCrud(): void
    {
        $zoneId = $this->createZone();

        $created = $this->postJson('/api/fc/v3/shipping/zones/' . $zoneId . '/locations', ['type' => 'state', 'code' => 'US:CA'], $this->auth());
        $created->assertStatus(201);

        $body = json_decode($created->baseResponse->body(), true);
        self::assertSame($zoneId, $body['zone_id']);
        self::assertSame('state', $body['type']);
        self::assertSame('US:CA', $body['code']);

        $list = $this->get('/api/fc/v3/shipping/zones/' . $zoneId . '/locations', $this->auth());
        $list->assertOk();
        self::assertCount(1, json_decode($list->baseResponse->body(), true));

        $invalid = $this->postJson('/api/fc/v3/shipping/zones/' . $zoneId . '/locations', ['type' => 'galaxy', 'code' => 'X'], $this->auth());
        $invalid->assertStatus(400);
        $invalidBody = json_decode($invalid->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $invalidBody['code'] ?? null);
        self::assertSame('type', array_key_first($invalidBody['data']['params'] ?? []));

        $updated = $this->json('PUT', '/api/fc/v3/shipping/zones/' . $zoneId . '/locations/' . $body['id'], ['code' => 'US:NY']);
        $updated->assertOk();
        self::assertSame('US:NY', json_decode($updated->baseResponse->body(), true)['code']);

        $deleted = $this->call('DELETE', '/api/fc/v3/shipping/zones/' . $zoneId . '/locations/' . $body['id'], $this->auth());
        $deleted->assertOk();
        self::assertTrue(json_decode($deleted->baseResponse->body(), true)['deleted'] ?? null);

        $missingZone = $this->get('/api/fc/v3/shipping/zones/9999/locations', $this->auth());
        $missingZone->assertNotFound();
    }

    public function testMethodsCrud(): void
    {
        $zoneId = $this->createZone();

        $created = $this->postJson('/api/fc/v3/shipping/zones/' . $zoneId . '/methods', [
            'method_id' => 'flat_rate',
            'title' => 'Flat rate',
            'settings' => ['cost' => '5.00'],
        ], $this->auth());
        $created->assertStatus(201);

        $body = json_decode($created->baseResponse->body(), true);
        self::assertSame('flat_rate', $body['method_id']);
        self::assertTrue($body['is_enabled']);
        self::assertSame(['cost' => '5.00'], $body['settings']);

        $list = $this->get('/api/fc/v3/shipping/zones/' . $zoneId . '/methods', $this->auth());
        $list->assertOk();
        self::assertCount(1, json_decode($list->baseResponse->body(), true));

        $missingMethod = $this->postJson('/api/fc/v3/shipping/zones/' . $zoneId . '/methods', ['title' => 'No id'], $this->auth());
        $missingMethod->assertStatus(400);
        $missingBody = json_decode($missingMethod->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $missingBody['code'] ?? null);
        self::assertSame('method_id', array_key_first($missingBody['data']['params'] ?? []));

        $updated = $this->json('PUT', '/api/fc/v3/shipping/zones/' . $zoneId . '/methods/' . $body['id'], ['is_enabled' => false]);
        $updated->assertOk();
        self::assertFalse(json_decode($updated->baseResponse->body(), true)['is_enabled']);

        $deleted = $this->call('DELETE', '/api/fc/v3/shipping/zones/' . $zoneId . '/methods/' . $body['id'], $this->auth());
        $deleted->assertOk();
        self::assertTrue(json_decode($deleted->baseResponse->body(), true)['deleted'] ?? null);

        $missingZone = $this->postJson('/api/fc/v3/shipping/zones/9999/methods', ['method_id' => 'flat_rate'], $this->auth());
        $missingZone->assertNotFound();
    }
}
