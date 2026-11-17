<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\AppTestCase;

/**
 * Settings API: /settings (groups), /settings/{group} (settings in group),
 * /settings/{group}/{id} (single setting), PUT/POST/PATCH update, POST batch.
 */
final class SettingsTest extends AppTestCase
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

        // Setting groups
        $this->db->execute(
            'INSERT INTO setting_groups (id, label, description, parent_id, sort) VALUES (?, ?, ?, ?, ?)',
            ['general', 'General Settings', 'General options for the store', null, 0],
        );
        $this->db->execute(
            'INSERT INTO setting_groups (id, label, description, parent_id, sort) VALUES (?, ?, ?, ?, ?)',
            ['products', 'Products', 'Product-related settings', null, 10],
        );

        // Settings in 'general' group
        $this->db->execute(
            'INSERT INTO settings (group_id, id, label, description, type, value, default_value, tip, placeholder, options, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['general', 'site_title', 'Site Title', 'Title of the site', 'text', 'My Store', 'My Store', '', 'Enter site title', null, 0],
        );
        $this->db->execute(
            'INSERT INTO settings (group_id, id, label, description, type, value, default_value, tip, placeholder, options, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['general', 'tax_enabled', 'Enable Taxes', 'Enable tax calculations', 'checkbox', '1', '1', '', '', null, 10],
        );
        $this->db->execute(
            'INSERT INTO settings (group_id, id, label, description, type, value, default_value, tip, placeholder, options, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['general', 'admin_email', 'Admin Email', 'Email for admin notifications', 'email', 'admin@example.com', 'admin@example.com', '', 'admin@example.com', null, 20],
        );
        $this->db->execute(
            'INSERT INTO settings (group_id, id, label, description, type, value, default_value, tip, placeholder, options, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['general', 'currency', 'Currency', 'Default currency', 'select', 'USD', 'USD', '', '', '[{"value":"USD","label":"US Dollar"},{"value":"EUR","label":"Euro"}]', 30],
        );
        $this->db->execute(
            'INSERT INTO settings (group_id, id, label, description, type, value, default_value, tip, placeholder, options, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['general', 'allowed_roles', 'Allowed Roles', 'User roles with access', 'multiselect', '["administrator","shop_manager"]', '["administrator"]', '', '', '[{"value":"administrator","label":"Administrator"},{"value":"shop_manager","label":"Shop Manager"},{"value":"customer","label":"Customer"}]', 40],
        );
        $this->db->execute(
            'INSERT INTO settings (group_id, id, label, description, type, value, default_value, tip, placeholder, options, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['general', 'product_price', 'Default Price', 'Default product price', 'number', '10', '10', '', '', null, 50],
        );
        $this->db->execute(
            'INSERT INTO settings (group_id, id, label, description, type, value, default_value, tip, placeholder, options, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['general', 'tax_rate', 'Default Tax Rate', 'Default tax rate percent', 'decimal', '20.00', '20.00', '', '', null, 60],
        );

        // Settings in 'products' group
        $this->db->execute(
            'INSERT INTO settings (group_id, id, label, description, type, value, default_value, tip, placeholder, options, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['products', 'default_weight_unit', 'Default Weight Unit', 'Weight unit for products', 'select', 'kg', 'kg', '', '', '[{"value":"kg","label":"Kilograms"},{"value":"lb","label":"Pounds"}]', 0],
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

    public function testSettingsRequireAuthentication(): void
    {
        $response = $this->get('/api/fc/v3/settings');
        $response->assertStatus(401);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_cannot_authenticate', $body['code'] ?? null);
    }

    public function testListSettingGroups(): void
    {
        $response = $this->get('/api/fc/v3/settings', $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body);
        self::assertSame('general', $body[0]['id']);
        self::assertSame('General Settings', $body[0]['label']);
        self::assertArrayHasKey('_links', $body[0]);
    }

    public function testGetSettingsForGroup(): void
    {
        $response = $this->get('/api/fc/v3/settings/general', $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(7, $body); // 7 settings in general group
        self::assertSame('site_title', $body[0]['id']);
        self::assertSame('My Store', $body[0]['value']);
        self::assertArrayHasKey('options', $body[0]);
    }

    public function testGetSettingsForUnknownGroup(): void
    {
        $response = $this->get('/api/fc/v3/settings/unknown', $this->auth());
        $response->assertNotFound();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_setting_invalid_group', $body['code'] ?? null);
    }

    public function testShowSetting(): void
    {
        $response = $this->get('/api/fc/v3/settings/general/site_title', $this->auth());
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('site_title', $body['id']);
        self::assertSame('Site Title', $body['label']);
        self::assertSame('text', $body['type']);
        self::assertSame('My Store', $body['value']);
        self::assertSame('My Store', $body['default']);
        self::assertArrayHasKey('_links', $body);
    }

    public function testShowSettingUnknownGroup(): void
    {
        $response = $this->get('/api/fc/v3/settings/unknown/site_title', $this->auth());
        $response->assertNotFound();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_setting_invalid_group', $body['code'] ?? null);
    }

    public function testShowSettingUnknownId(): void
    {
        $response = $this->get('/api/fc/v3/settings/general/unknown', $this->auth());
        $response->assertNotFound();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_setting_invalid_id', $body['code'] ?? null);
    }

    public function testUpdateTextSetting(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/site_title', ['value' => 'New Store Title']);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('New Store Title', $body['value']);
    }

    public function testUpdateCheckboxSetting(): void
    {
        // Initially '1' (true)
        $response = $this->json('PUT', '/api/fc/v3/settings/general/tax_enabled', ['value' => false]);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertFalse($body['value']);
    }

    public function testUpdateCheckboxSettingAcceptsTrueString(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/tax_enabled', ['value' => 'true']);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertTrue($body['value']);
    }

    public function testUpdateEmailSettingValid(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/admin_email', ['value' => 'new@example.com']);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('new@example.com', $body['value']);
    }

    public function testUpdateEmailSettingInvalid(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/admin_email', ['value' => 'not-an-email']);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertArrayHasKey('value', $body['data']['params'] ?? []);
    }

    public function testUpdateSelectSettingValid(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/currency', ['value' => 'EUR']);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('EUR', $body['value']);
    }

    public function testUpdateSelectSettingInvalid(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/currency', ['value' => 'XYZ']);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertArrayHasKey('value', $body['data']['params'] ?? []);
    }

    public function testUpdateMultiselectSetting(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/allowed_roles', ['value' => ['customer', 'shop_manager']]);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame(['customer', 'shop_manager'], $body['value']);
    }

    public function testUpdateMultiselectSettingInvalid(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/allowed_roles', ['value' => ['invalid_role']]);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertArrayHasKey('value', $body['data']['params'] ?? []);
    }

    public function testUpdateNumberSetting(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/product_price', ['value' => 25]);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('25', $body['value']);
    }

    public function testUpdateNumberSettingInvalid(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/product_price', ['value' => 'not-a-number']);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertArrayHasKey('value', $body['data']['params'] ?? []);
    }

    public function testUpdateDecimalSetting(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/tax_rate', ['value' => '15.5']);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('15.50', $body['value']);
    }

    public function testUpdateRequiresValue(): void
    {
        $response = $this->json('PUT', '/api/fc/v3/settings/general/site_title', []);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame('value', array_key_first($body['data']['params'] ?? []));
    }

    public function testUpdateSettingWorksWithPostAndPatch(): void
    {
        foreach (['POST', 'PATCH'] as $method) {
            $response = $this->json($method, '/api/fc/v3/settings/general/site_title', ['value' => $method . ' update']);
            $response->assertOk();
            self::assertSame($method . ' update', json_decode($response->baseResponse->body(), true)['value']);
        }
    }

    public function testBatchUpdateSettings(): void
    {
        $response = $this->json('POST', '/api/fc/v3/settings/general/batch', [
            'update' => [
                ['id' => 'site_title', 'value' => 'Batch Title'],
                ['id' => 'tax_enabled', 'value' => false],
                ['id' => 'currency', 'value' => 'EUR'],
            ],
        ]);
        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(3, $body['update']);
        self::assertSame('Batch Title', $body['update'][0]['value']);
        self::assertFalse($body['update'][1]['value']);
        self::assertSame('EUR', $body['update'][2]['value']);
    }

    public function testBatchUpdateInvalidId(): void
    {
        $response = $this->json('POST', '/api/fc/v3/settings/general/batch', [
            'update' => [
                ['id' => 'nonexistent', 'value' => 'test'],
            ],
        ]);
        $response->assertStatus(404);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_setting_invalid_id', $body['code'] ?? null);
    }

    public function testBatchUpdateRequiresArray(): void
    {
        $response = $this->json('POST', '/api/fc/v3/settings/general/batch', [
            'update' => 'not-an-array',
        ]);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertSame('update', array_key_first($body['data']['params'] ?? []));
    }

    public function testBatchUpdateRequiresIdAndValue(): void
    {
        $response = $this->json('POST', '/api/fc/v3/settings/general/batch', [
            'update' => [
                ['value' => 'missing-id'],
            ],
        ]);
        $response->assertStatus(400);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertStringContainsString('update[0]', array_key_first($body['data']['params'] ?? ''));
    }
}