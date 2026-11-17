<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\AppTestCase;

final class ApiTest extends AppTestCase
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

        $categoryIds = [];
        foreach ([['Appetizers', 'appetizers'], ['Main Courses', 'main-courses']] as [$name, $slug]) {
            $this->db->execute(
                'INSERT INTO product_categories (name, slug, parent_id, description, display, menu_order, created_at, updated_at)
                 VALUES (?, ?, 0, \'\', \'default\', 0, ?, ?)',
                [$name, $slug, $now, $now],
            );
            $categoryIds[] = (int) $this->db->fetchColumn(
                'SELECT id FROM product_categories WHERE slug = ?',
                [$slug],
            );
        }

        $products = [
            ['Cheese and garlic potato skins', 'cheese-and-garlic-potato-skins', 'potato-skins-401', '8.95', '8.95', null, 25],
            ['Deep fried spicy squid', 'deep-fried-spicy-squid', 'squid-101', '10.95', '15.00', '10.95', 8],
            ['Grilled sea bass', 'grilled-sea-bass', 'sea-bass-102', '18.20', '18.20', null, 3],
            ['Thai basil chicken', 'thai-basil-chicken', 'thai-chicken-103', '11.00', '11.00', null, 0],
        ];

        foreach ($products as $i => [$name, $slug, $sku, $price, $regular, $sale, $stock]) {
            $createdAt = sprintf('2026-11-16 10:%02d:00', $i);

            $this->db->execute(
                'INSERT INTO products (
                    name, slug, type, status, sku, price, regular_price, sale_price,
                    manage_stock, stock_quantity, stock_status, menu_order, created_at, updated_at
                ) VALUES (?, ?, \'simple\', \'publish\', ?, ?, ?, ?, 1, ?, \'instock\', ?, ?, ?)',
                [$name, $slug, $sku, $price, $regular, $sale, $stock, $i, $createdAt, $createdAt],
            );

            $productId = (int) $this->db->fetchColumn('SELECT id FROM products WHERE slug = ?', [$slug]);

            $this->db->execute(
                'INSERT INTO product_category_relationships (product_id, category_id) VALUES (?, ?)',
                [$productId, $categoryIds[$i % 2]],
            );
        }

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

    public function testApiIndexIsPublic(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertArrayHasKey('name', $body);
        self::assertArrayHasKey('namespace', $body);
        self::assertArrayHasKey('routes', $body);
    }

    public function testHealthIsPublic(): void
    {
        $response = $this->get('/health');
        $response->assertOk();
        self::assertSame('ok', json_decode($response->baseResponse->body(), true)['status'] ?? null);
    }

    public function testProductsRequireAuthentication(): void
    {
        $response = $this->get('/api/fc/v3/products');

        $response->assertStatus(401);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_cannot_authenticate', $body['code'] ?? null);
        self::assertSame(401, $body['data']['status'] ?? null);
    }

    public function testProductsRejectInvalidKey(): void
    {
        $response = $this->get(
            '/api/fc/v3/products',
            ['Authorization' => 'Basic ' . base64_encode('ck_bad:cs_bad')],
        );

        $response->assertStatus(401);
    }

    public function testProductsListWithValidKey(): void
    {
        $response = $this->get('/api/fc/v3/products', $this->auth());

        $response->assertOk()
            ->assertHeader('X-WP-Total', '4')
            ->assertHeader('X-WP-TotalPages', '1');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(4, $body);

        $first = $body[0];
        foreach (
            [
                'id', 'name', 'slug', 'permalink', 'date_created', 'date_created_gmt',
                'type', 'status', 'sku', 'price', 'regular_price', 'sale_price',
                'on_sale', 'purchasable', 'manage_stock', 'stock_quantity', 'stock_status',
                'dimensions', 'categories', 'images', 'meta_data',
            ] as $field
        ) {
            self::assertArrayHasKey($field, $first, "Missing field: $field");
        }

        self::assertSame('Thai basil chicken', $first['name']);
        self::assertSame('11.00', $first['price']);
        self::assertSame('11.00', $first['regular_price']);
        self::assertSame('', $first['sale_price']);
        self::assertSame(0, $first['stock_quantity']);
        self::assertSame('instock', $first['stock_status']);
        self::assertSame(2, $first['categories'][0]['id']);
    }

    public function testProductsPagination(): void
    {
        $response = $this->get('/api/fc/v3/products?per_page=2&page=1', $this->auth());

        $response->assertOk()
            ->assertHeader('X-WP-Total', '4')
            ->assertHeader('X-WP-TotalPages', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertCount(2, $body);

        $link = $response->baseResponse->header('Link');
        self::assertStringContainsString('rel="next"', $link ?? '');
        self::assertStringContainsString('page=2', $link ?? '');
    }

    public function testProductsPaginationPageTwoHasPrevLink(): void
    {
        $response = $this->get('/api/fc/v3/products?per_page=2&page=2', $this->auth());

        $response->assertOk();

        $link = $response->baseResponse->header('Link');
        self::assertStringContainsString('rel="prev"', $link ?? '');
        self::assertStringContainsString('page=1', $link ?? '');
    }

    public function testPerPageAbove100IsRejected(): void
    {
        $response = $this->get('/api/fc/v3/products?per_page=101', $this->auth());

        $response->assertStatus(400);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
    }

    public function testProductsSearchFilter(): void
    {
        $response = $this->get('/api/fc/v3/products?search=squid', $this->auth());

        $response->assertOk()->assertHeader('X-WP-Total', '1');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('Deep fried spicy squid', $body[0]['name']);
        self::assertSame('10.95', $body[0]['sale_price']);
        self::assertTrue($body[0]['on_sale']);
    }

    public function testProductsSlugAndSkuFilter(): void
    {
        $response = $this->get('/api/fc/v3/products?slug=grilled-sea-bass', $this->auth());
        $response->assertOk()->assertHeader('X-WP-Total', '1');

        $response = $this->get('/api/fc/v3/products?sku=thai-chicken-103', $this->auth());
        $response->assertOk()->assertHeader('X-WP-Total', '1');
    }

    public function testProductsCategoryFilter(): void
    {
        $categoryId = (int) $this->db->fetchColumn(
            'SELECT id FROM product_categories WHERE slug = ?',
            ['appetizers'],
        );

        $response = $this->get('/api/fc/v3/products?category=' . $categoryId, $this->auth());
        $response->assertOk()->assertHeader('X-WP-Total', '2');
    }

    public function testProductsIncludeAndOrderby(): void
    {
        $response = $this->get('/api/fc/v3/products?include=3,1&orderby=include', $this->auth());

        $response->assertOk()->assertHeader('X-WP-Total', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame(3, $body[0]['id']);
        self::assertSame(1, $body[1]['id']);
    }

    public function testProductShow(): void
    {
        $response = $this->get('/api/fc/v3/products/1', $this->auth());

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('Cheese and garlic potato skins', $body['name']);
        self::assertSame(25, $body['stock_quantity']);
    }

    public function testProductShowNotFound(): void
    {
        $response = $this->get('/api/fc/v3/products/9999', $this->auth());

        $response->assertStatus(404);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_product_invalid_id', $body['code'] ?? null);
        self::assertSame('Invalid ID.', $body['message'] ?? null);
    }

    public function testProductShowInvalidIdIs404(): void
    {
        $this->get('/api/fc/v3/products/not-a-number', $this->auth())->assertNotFound();
    }

    public function testCategoriesListWithCounts(): void
    {
        $response = $this->get('/api/fc/v3/products/categories', $this->auth());

        $response->assertOk()->assertHeader('X-WP-Total', '2');

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('Appetizers', $body[0]['name']);
        self::assertSame(2, $body[0]['count']);
        self::assertArrayHasKey('image', $body[0]);
        self::assertNull($body[0]['image']);
    }

    public function testCategoryShow(): void
    {
        $categoryId = (int) $this->db->fetchColumn(
            'SELECT id FROM product_categories WHERE slug = ?',
            ['main-courses'],
        );

        $response = $this->get('/api/fc/v3/products/categories/' . $categoryId, $this->auth());

        $response->assertOk();

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('Main Courses', $body['name']);
        self::assertSame(2, $body['count']);
    }

    public function testCategoryNotFound(): void
    {
        $response = $this->get('/api/fc/v3/products/categories/9999', $this->auth());

        $response->assertStatus(404);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_product_cat_invalid_id', $body['code'] ?? null);
    }

    public function testUnknownRouteIsWcShaped404(): void
    {
        $response = $this->get('/api/fc/v3/nonexistent', $this->auth());

        $response->assertStatus(404);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_no_route', $body['code'] ?? null);
    }

    public function testMethodNotAllowedCarriesAllowHeader(): void
    {
        $response = $this->call('DELETE', '/health');

        $response->assertStatus(405);
        self::assertStringContainsString('GET', $response->baseResponse->header('Allow') ?? '');
    }

    public function testStoreProduct(): void
    {
        $categoryId = (int) $this->db->fetchColumn(
            'SELECT id FROM product_categories WHERE slug = ?',
            ['main-courses'],
        );

        $response = $this->postJson('/api/fc/v3/products', [
            'name' => 'Coconut rice',
            'type' => 'simple',
            'status' => 'publish',
            'regular_price' => '9.50',
            'manage_stock' => true,
            'stock_quantity' => 12,
            'categories' => [['id' => $categoryId]],
        ], $this->auth());

        $response->assertStatus(201);

        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('Coconut rice', $body['name']);
        self::assertSame('coconut-rice', $body['slug']);
        self::assertSame('9.50', $body['regular_price']);
        self::assertSame('9.50', $body['price']);
        self::assertSame(12, $body['stock_quantity']);
        self::assertSame($categoryId, $body['categories'][0]['id'] ?? null);
        self::assertSame(0, $body['total_sales']);

        $count = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM products');
        self::assertSame(5, $count);
    }

    public function testStoreProductWithoutNameIsMissingParam(): void
    {
        $response = $this->postJson('/api/fc/v3/products', ['regular_price' => '5'], $this->auth());

        $response->assertStatus(400);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_missing_callback_param', $body['code'] ?? null);
        self::assertSame(400, $body['data']['status'] ?? null);
        self::assertArrayHasKey('params', $body['data'] ?? []);
    }

    public function testStoreProductWithBadStatusIsInvalidParam(): void
    {
        $response = $this->postJson('/api/fc/v3/products', [
            'name' => 'X',
            'status' => 'banana',
        ], $this->auth());

        $response->assertStatus(400);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertSame('status', array_key_first($body['data']['params'] ?? []));
    }

    public function testStoreSlugCollisionGetsSuffixed(): void
    {
        $response = $this->postJson('/api/fc/v3/products', [
            'name' => 'Thai basil chicken',
        ], $this->auth());

        $response->assertStatus(201);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertStringStartsWith('thai-basil-chicken', $body['slug']);
        self::assertNotSame('thai-basil-chicken', $body['slug']);
    }

    public function testReadPermissionKeyCannotWrite(): void
    {
        $now = '2026-11-16 10:00:00';
        $ck = 'ck_' . bin2hex(random_bytes(20));
        $cs = 'cs_' . bin2hex(random_bytes(20));

        $this->db->execute(
            'INSERT INTO api_keys (description, permissions, consumer_key, consumer_secret, truncated_key, created_at, updated_at)
             VALUES (?, \'read\', ?, ?, ?, ?, ?)',
            ['readonly key', hash_hmac('sha256', $ck, 'wc-api'), $cs, substr($ck, -7), $now, $now],
        );

        $response = $this->post(
            '/api/fc/v3/products',
            json_encode(['name' => 'X']),
            ['Authorization' => 'Basic ' . base64_encode($ck . ':' . $cs), 'Content-Type' => 'application/json'],
        );

        $response->assertStatus(403);
    }

    public function testUpdateProductPartialMerge(): void
    {
        $response = $this->postJson('/api/fc/v3/products/1', [
            'name' => 'Cheese and garlic potato skins XL',
            'regular_price' => '12.00',
        ], $this->auth());

        $response->assertOk();
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('Cheese and garlic potato skins XL', $body['name']);
        self::assertSame('cheese-and-garlic-potato-skins', $body['slug']);
        self::assertSame('12.00', $body['regular_price']);
        self::assertSame('12.00', $body['price']);
        self::assertSame('potato-skins-401', $body['sku']);

        $updatedAt = (string) $this->db->fetchColumn('SELECT updated_at FROM products WHERE id = 1');
        self::assertStringStartsWith('2026', $updatedAt);
    }

    public function testUpdateProductUnknownId404(): void
    {
        $response = $this->postJson('/api/fc/v3/products/9999', ['name' => 'X'], $this->auth());

        $response->assertStatus(404);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('woocommerce_rest_product_invalid_id', $body['code'] ?? null);
    }

    public function testUpdateProductInvalidValue400(): void
    {
        $response = $this->postJson('/api/fc/v3/products/1', ['status' => 'banana'], $this->auth());

        $response->assertStatus(400);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertSame('status', array_key_first($body['data']['params'] ?? []));
    }

    public function testUpdateSalePriceRecompute(): void
    {
        $categoryId = (int) $this->db->fetchColumn('SELECT id FROM product_categories WHERE slug = ?', ['main-courses']);
        $created = $this->postJson('/api/fc/v3/products', [
            'name' => 'Miso soup',
            'regular_price' => '5.00',
            'categories' => [['id' => $categoryId]],
        ], $this->auth());
        $id = (int) json_decode($created->baseResponse->body(), true)['id'];

        $onSale = $this->postJson("/api/fc/v3/products/$id", ['sale_price' => '3.50'], $this->auth());
        $onSale->assertOk();
        $body = json_decode($onSale->baseResponse->body(), true);
        self::assertSame('3.50', $body['price']);

        $cleared = $this->postJson("/api/fc/v3/products/$id", ['sale_price' => ''], $this->auth());
        $cleared->assertOk();
        $body = json_decode($cleared->baseResponse->body(), true);
        self::assertSame('5.00', $body['price']);
    }

    public function testUpdateDimensionsAndBooleans(): void
    {
        $response = $this->postJson('/api/fc/v3/products/1', [
            'dimensions' => ['length' => '10.5', 'width' => '20.1', 'height' => '5.0'],
            'virtual' => true,
            'featured' => false,
        ], $this->auth());

        $response->assertOk();
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('10.5', $body['dimensions']['length']);
        self::assertSame('20.1', $body['dimensions']['width']);
        self::assertSame('5.0', $body['dimensions']['height']);
        self::assertTrue($body['virtual']);
        self::assertFalse($body['featured']);
    }

    public function testUpdateSlugCollisionGetsSuffixed(): void
    {
        $response = $this->postJson('/api/fc/v3/products/1', [
            'slug' => 'deep-fried-spicy-squid',
        ], $this->auth());

        $response->assertOk();
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('deep-fried-spicy-squid-2', $body['slug']);
    }

    public function testUpdateSaleDateValidated(): void
    {
        $good = $this->postJson('/api/fc/v3/products/1', [
            'date_on_sale_from' => '2026-12-01T09:30:00Z',
        ], $this->auth());
        $good->assertOk();
        $row = (string) $this->db->fetchColumn('SELECT date_on_sale_from FROM products WHERE id = 1');
        self::assertSame('2026-12-01 09:30:00', $row);

        $bad = $this->postJson('/api/fc/v3/products/1', [
            'date_on_sale_to' => 'not-a-date',
        ], $this->auth());
        $bad->assertStatus(400);
        $body = json_decode($bad->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
        self::assertSame('date_on_sale_to', array_key_first($body['data']['params'] ?? []));
    }

    public function testDeleteProductTrashSemantics(): void
    {
        $response = $this->call('DELETE', '/api/fc/v3/products/1?force=false', $this->auth());

        $response->assertOk();
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('trash', $body['status'] ?? null);
        self::assertArrayNotHasKey('deleted', $body);

        $status = (string) $this->db->fetchColumn('SELECT status FROM products WHERE id = 1');
        self::assertSame('trash', $status);
    }

    public function testDeleteProductForceSemantics(): void
    {
        $categoryId = (int) $this->db->fetchColumn('SELECT id FROM product_categories WHERE slug = ?', ['appetizers']);
        $beforeRelations = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM product_category_relationships WHERE product_id = 1',
        );
        self::assertGreaterThan(0, $beforeRelations);

        $response = $this->call('DELETE', '/api/fc/v3/products/1?force=true', $this->auth());

        $response->assertOk();
        $body = json_decode($response->baseResponse->body(), true);
        self::assertTrue($body['deleted'] ?? null);
        self::assertSame('Cheese and garlic potato skins', $body['previous']['name'] ?? null);
        self::assertSame($categoryId, $body['previous']['categories'][0]['id'] ?? null);

        self::assertFalse($this->db->fetchColumn('SELECT id FROM products WHERE id = 1'));
        $relations = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM product_category_relationships WHERE product_id = 1',
        );
        self::assertSame(0, $relations);
    }

    public function testBatchCreateUpdateDelete(): void
    {
        $categoryId = (int) $this->db->fetchColumn('SELECT id FROM product_categories WHERE slug = ?', ['main-courses']);

        $response = $this->postJson('/api/fc/v3/products/batch', [
            'create' => [['name' => 'Spring rolls', 'regular_price' => '6.00', 'categories' => [['id' => $categoryId]]]],
            'update' => [['id' => 4, 'regular_price' => '12.50']],
            'delete' => [3],
        ], $this->auth());

        $response->assertOk();
        $body = json_decode($response->baseResponse->body(), true);

        self::assertCount(1, $body['create']);
        self::assertSame('spring-rolls', $body['create'][0]['slug']);
        self::assertSame('6.00', $body['create'][0]['price']);

        self::assertCount(1, $body['update']);
        self::assertSame(4, $body['update'][0]['id']);
        self::assertSame('12.50', $body['update'][0]['price']);

        self::assertCount(1, $body['delete']);
        self::assertTrue($body['delete'][0]['deleted']);
        self::assertSame('Grilled sea bass', $body['delete'][0]['previous']['name']);

        self::assertFalse($this->db->fetchColumn('SELECT id FROM products WHERE id = 3'));
        self::assertSame(12.50, (float) $this->db->fetchColumn('SELECT price FROM products WHERE id = 4'));
    }

    public function testBatchUpdateMissingId400(): void
    {
        $response = $this->postJson('/api/fc/v3/products/batch', [
            'update' => [['regular_price' => '1.00']],
        ], $this->auth());

        $response->assertStatus(400);
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame('rest_invalid_param', $body['code'] ?? null);
    }

    public function testBatchKeysAlwaysPresent(): void
    {
        $response = $this->postJson('/api/fc/v3/products/batch', [], $this->auth());

        $response->assertOk();
        $body = json_decode($response->baseResponse->body(), true);
        self::assertSame([], $body['create']);
        self::assertSame([], $body['update']);
        self::assertSame([], $body['delete']);
    }
}
