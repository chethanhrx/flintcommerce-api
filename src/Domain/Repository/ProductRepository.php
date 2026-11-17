<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\Product;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

/**
 * Product collection + write queries with WooCommerce v3 semantics.
 *
 * Raw parameterized SQL: full control over collection filters without
 * N+1 hydration overhead. Values are bound, identifiers whitelisted.
 */
final class ProductRepository
{
    private const ORDERBY_MAP = [
        'date' => 'created_at',
        'modified' => 'updated_at',
        'id' => 'id',
        'title' => 'name',
        'slug' => 'slug',
        'menu_order' => 'menu_order',
        'price' => 'price',
        'popularity' => 'total_sales',
        'rating' => 'average_rating',
    ];

    private const STATUS_VALUES = ['any', 'publish', 'draft', 'pending', 'private', 'trash', 'future'];

    /**
     * Product columns writable through the API (schema whitelist — the SET
     * clause interpolates column names, so this list is the guard).
     */
    private const WRITABLE_COLUMNS = [
        'name', 'slug', 'type', 'status', 'featured', 'catalog_visibility',
        'description', 'short_description', 'sku', 'regular_price', 'sale_price',
        'date_on_sale_from', 'date_on_sale_to', 'virtual', 'downloadable',
        'download_limit', 'download_expiry', 'external_url', 'button_text',
        'tax_status', 'tax_class', 'manage_stock', 'stock_quantity', 'stock_status',
        'backorders', 'low_stock_amount', 'sold_individually', 'weight',
        'length', 'width', 'height', 'reviews_allowed', 'parent_id',
        'purchase_note', 'menu_order', 'updated_at',
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $f Validated filters
     * @return list<Product>
     */
    /**

     * @param array<string, mixed> $f

     * @return array

     */

    public function list(array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $sql = 'SELECT * FROM products' . $where
            . $this->buildOrderBy($f, $f['include'] ?? null)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): Product => $this->hydrate($row), $rows);
    }

    /**


     * @param array<string, mixed> $f


     * @return int


     */


    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM products' . $where,
            $params,
        );
    }

    public function find(int $id): ?Product
    {
        $row = $this->connection->fetch('SELECT * FROM products WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Transactional product create (WC store semantics).
     *
     * @param array<string, mixed> $payload Validated + normalized input
     * @return Product
     */
    /**

     * @param array<string, mixed> $payload

     * @return Product

     */

    public function create(array $payload): Product
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): Product {
            $slug = $payload['slug'] ?? Slug::fromName($payload['name']);
            $slug = Slug::unique($this->connection, 'products', $slug);

            $this->connection->execute(
                'INSERT INTO products (
                    name, slug, type, status, featured, catalog_visibility,
                    description, short_description, sku, price, regular_price, sale_price,
                    date_on_sale_from, date_on_sale_to,
                    virtual, downloadable, external_url, button_text,
                    tax_status, tax_class, manage_stock, stock_quantity, stock_status,
                    backorders, low_stock_amount, sold_individually,
                    weight, length, width, height,
                    reviews_allowed, parent_id, purchase_note, menu_order, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $payload['name'],
                    $slug,
                    $payload['type'] ?? 'simple',
                    $payload['status'] ?? 'publish',
                    (int) ($payload['featured'] ?? 0),
                    $payload['catalog_visibility'] ?? 'visible',
                    $payload['description'] ?? '',
                    $payload['short_description'] ?? '',
                    $payload['sku'] ?? null,
                    $payload['price'] ?? ($payload['regular_price'] ?? null),
                    $payload['regular_price'] ?? null,
                    $payload['sale_price'] ?? null,
                    $payload['date_on_sale_from'] ?? null,
                    $payload['date_on_sale_to'] ?? null,
                    (int) ($payload['virtual'] ?? 0),
                    (int) ($payload['downloadable'] ?? 0),
                    $payload['external_url'] ?? '',
                    $payload['button_text'] ?? '',
                    $payload['tax_status'] ?? 'taxable',
                    $payload['tax_class'] ?? '',
                    (int) ($payload['manage_stock'] ?? 0),
                    $payload['stock_quantity'] ?? null,
                    $payload['stock_status'] ?? 'instock',
                    $payload['backorders'] ?? 'no',
                    $payload['low_stock_amount'] ?? null,
                    (int) ($payload['sold_individually'] ?? 0),
                    $payload['weight'] ?? null,
                    $payload['length'] ?? null,
                    $payload['width'] ?? null,
                    $payload['height'] ?? null,
                    (int) ($payload['reviews_allowed'] ?? 1),
                    (int) ($payload['parent_id'] ?? 0),
                    $payload['purchase_note'] ?? '',
                    (int) ($payload['menu_order'] ?? 0),
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM products WHERE slug = ?', [$slug]);
            $this->syncCategories($id, $payload['categories'] ?? []);

            $product = $this->find($id);
            if ($product === null) {
                throw new \RuntimeException('Product insert failed.');
            }

            return $product;
        });
    }

    /**
     * Transactional partial update (WC PUT/PATCH semantics — merge into existing row).
     *
     * @param array<string, mixed> $payload Validated + normalized input
     * @return Product
     */
    /**

     * @param array<string, mixed> $payload

     * @return Product

     */

    public function update(int $id, array $payload): Product
    {
        return $this->connection->transaction(function () use ($id, $payload): Product {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_product_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['categories'], $updates['date_created'], $updates['date_modified']);

            // Only real product columns are writable (schema whitelist).
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            // Slug must stay unique across other products (WC appends -2 on clash).
            if (isset($updates['slug']) && is_string($updates['slug'])) {
                $updates['slug'] = Slug::unique($this->connection, 'products', $updates['slug'], $id);
            }

            // Recompute effective price the way create does when any price input moved.
            if (array_key_exists('price', $updates)) {
                unset($updates['price']);
            }
            $sale = array_key_exists('sale_price', $updates) ? $updates['sale_price'] : $existing->sale_price;
            $regular = array_key_exists('regular_price', $updates) ? $updates['regular_price'] : $existing->regular_price;
            $effective = self::nonEmpty($sale) ?? self::nonEmpty($regular);
            if ($effective !== null) {
                $updates['price'] = $effective;
            }

            if (isset($updates['manage_stock']) && isset($updates['stock_quantity']) && !isset($updates['stock_status'])) {
                $updates['stock_status'] = (int) $updates['stock_quantity'] > 0 ? 'instock' : 'outofstock';
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE products SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            if (array_key_exists('categories', $payload)) {
                $this->syncCategories($id, $payload['categories']);
            }

            $product = $this->find($id);
            if ($product === null) {
                throw new \RuntimeException('Product update failed.');
            }

            return $product;
        });
    }

    /**
     * WC delete semantics: force=true removes permanently, force=false moves to trash.
     *
     * @return array{0: Product, 1: bool, 2: array<int, list<array{id: int, name: string, slug: string}>>}
     *         The prior state, whether it was permanently deleted, and the
     *         category map captured BEFORE deletion (needed for the
     *         `previous` payload — the rows no longer exist afterwards).
     */
    /**

     * @return array

     */

    public function delete(int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($id, $force): array {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_product_invalid_id', 'Invalid ID.', 404);
            }

            $categoryMap = $this->categoryMapFor([$id]);

            if ($force) {
                $this->connection->execute('DELETE FROM product_category_relationships WHERE product_id = ?', [$id]);
                $this->connection->execute('DELETE FROM products WHERE id = ?', [$id]);

                return [$existing, true, $categoryMap];
            }

            $this->connection->execute(
                'UPDATE products SET status = ?, updated_at = ? WHERE id = ?',
                ['trash', gmdate('Y-m-d H:i:s'), $id],
            );

            // WC returns the post-trash resource for force=false.
            $trashed = $this->find($id);
            if ($trashed === null) {
                throw new \RuntimeException('Product trash failed.');
            }

            return [$trashed, false, $categoryMap];
        });
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * Replace the category relationships for a product.
     *
     * @param mixed $raw Array of ids or array of {id: n} objects (WC shape)
     */
    private function syncCategories(int $productId, mixed $raw): void
    {
        $ids = $this->categoryIdsFrom($raw);

        $this->connection->execute(
            'DELETE FROM product_category_relationships WHERE product_id = ?',
            [$productId],
        );

        foreach ($ids as $categoryId) {
            $exists = $this->connection->fetchColumn(
                'SELECT COUNT(*) FROM product_categories WHERE id = ?',
                [$categoryId],
            );
            if ((int) $exists === 0) {
                throw WcApiException::invalidParam(['categories' => "category $categoryId does not exist"]);
            }

            $this->connection->execute(
                'INSERT INTO product_category_relationships (product_id, category_id) VALUES (?, ?)',
                [$productId, $categoryId],
            );
        }
    }

    /**
     * @return list<int>
     */
    private function categoryIdsFrom(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $item) {
            if (is_array($item) && isset($item['id'])) {
                if (preg_match('/\A\d+\z/', (string) $item['id']) !== 1) {
                    throw WcApiException::invalidParam(['categories' => 'categories must contain valid ids']);
                }
                $ids[] = (int) $item['id'];
                continue;
            }

            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/\A\d+\z/', $item) !== 1) {
                throw WcApiException::invalidParam(['categories' => 'categories must contain valid ids']);
            }
            $ids[] = (int) $item;
        }

        return $ids;
    }

    /**
     * Category ids per product (single query for a page of products).
     *
     * @param list<int> $productIds
     * @return array<int, list<array{id: int, name: string, slug: string}>>
     */
    public function categoryMapFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $rows = $this->connection->fetchAll(
            'SELECT r.product_id, c.id, c.name, c.slug
             FROM product_category_relationships r
             INNER JOIN product_categories c ON c.id = r.category_id
             WHERE r.product_id IN (' . $placeholders . ')
             ORDER BY c.name ASC',
            $productIds,
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['product_id']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }

        return $map;
    }

    /**
     * Build WHERE clause + params from validated filters.
     *
     * @param array<string, mixed> $f
     * @return array{0: string, 1: list<mixed>}
     */
    /**

     * @param array<string, mixed> $f

     * @return array

     */

    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['status'])) {
            $statuses = $f['status'];
            if ($statuses !== ['any']) {
                $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
                $clauses[] = sprintf('status IN (%s)', $placeholders);
                foreach ($statuses as $s) {
                    $params[] = $s;
                }
            }
        }

        if (isset($f['search']) && $f['search'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['search']) . '%';
            $clauses[] = '(name LIKE ? ESCAPE \'\\\' OR description LIKE ? ESCAPE \'\\\' OR sku LIKE ? ESCAPE \'\\\')';
            array_push($params, $like, $like, $like);
        }

        if (isset($f['slug']) && $f['slug'] !== '') {
            $clauses[] = 'slug = ?';
            $params[] = $f['slug'];
        }

        if (isset($f['sku']) && $f['sku'] !== '') {
            $clauses[] = 'sku = ?';
            $params[] = $f['sku'];
        }

        if (isset($f['include']) && $f['include'] !== []) {
            $placeholders = implode(', ', array_fill(0, count($f['include']), '?'));
            $clauses[] = sprintf('id IN (%s)', $placeholders);
            foreach ($f['include'] as $id) {
                $params[] = $id;
            }
        }

        if (isset($f['exclude']) && $f['exclude'] !== []) {
            $placeholders = implode(', ', array_fill(0, count($f['exclude']), '?'));
            $clauses[] = sprintf('id NOT IN (%s)', $placeholders);
            foreach ($f['exclude'] as $id) {
                $params[] = $id;
            }
        }

        if (isset($f['category']) && $f['category'] !== []) {
            $placeholders = implode(', ', array_fill(0, count($f['category']), '?'));
            $clauses[] = sprintf(
                'EXISTS (SELECT 1 FROM product_category_relationships r WHERE r.product_id = products.id AND r.category_id IN (%s))',
                $placeholders,
            );
            foreach ($f['category'] as $id) {
                $params[] = $id;
            }
        }

        if (isset($f['featured'])) {
            $clauses[] = 'featured = ?';
            $params[] = $f['featured'] ? 1 : 0;
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $f
     */
    private function buildOrderBy(array $f, mixed $include): string
    {
        $direction = strtolower($f['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        if (($f['orderby'] ?? 'date') === 'include' && is_array($include) && $include !== []) {
            $cases = [];
            foreach (array_values($include) as $i => $id) {
                $cases[] = sprintf('WHEN %d THEN %d', (int) $id, $i);
            }
            return ' ORDER BY CASE id ' . implode(' ', $cases) . ' ELSE ' . count($include) . ' END ASC';
        }

        $column = self::ORDERBY_MAP[$f['orderby'] ?? 'date'] ?? 'created_at';

        return sprintf(' ORDER BY %s %s', $column, $direction);
    }

    /**
     * @param array<string, mixed> $f Raw filters — validates + normalizes
     * @return array<string, mixed>
     */
    /**

     * @param array<string, mixed> $f

     * @return array<string, mixed>

     */

    public function normalizeFilters(array $f): array
    {
        $out = [];

        if (isset($f['status'])) {
            $statuses = is_array($f['status']) ? $f['status'] : [$f['status']];
            foreach ($statuses as $s) {
                if (!in_array($s, self::STATUS_VALUES, true)) {
                    throw WcApiException::invalidParam(['status' => 'status is not one of ' . implode(',', self::STATUS_VALUES)]);
                }
            }
            $out['status'] = $statuses;
        }

        foreach (['search', 'slug', 'sku'] as $key) {
            if (isset($f[$key]) && is_string($f[$key])) {
                $out[$key] = $f[$key];
            }
        }

        foreach (['include', 'exclude', 'category'] as $key) {
            if (isset($f[$key])) {
                $out[$key] = self::idList($f[$key], $key);
            }
        }

        if (isset($f['orderby']) && !array_key_exists($f['orderby'], self::ORDERBY_MAP) && $f['orderby'] !== 'include') {
            throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']);
        }

        if (isset($f['order']) && !in_array(strtolower((string) $f['order']), ['asc', 'desc'], true)) {
            throw WcApiException::invalidParam(['order' => 'order must be asc or desc']);
        }

        if (isset($f['featured'])) {
            $out['featured'] = self::booleanParam($f['featured'], 'featured');
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function idList(mixed $raw, string $key): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/\A\d+\z/', $part) !== 1) {
                throw WcApiException::invalidParam([$key => "$key must contain integers"]);
            }
            $ids[] = (int) $part;
        }

        return $ids;
    }

    private static function booleanParam(mixed $raw, string $key): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $v = strtolower(trim((string) $raw));

        return match ($v) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => throw WcApiException::invalidParam([$key => "$key must be boolean"]),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    /**

     * @param array<string, mixed> $row

     * @return Product

     */

    /**


     * @param array<string, mixed> $row


     * @return \App\Domain\Model\Product


     */


    private function hydrate(array $row): \App\Domain\Model\Product
    {
        $product = new Product();

        foreach ($row as $column => $value) {
            $product->{$column} = $value;
        }

        return $product;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
