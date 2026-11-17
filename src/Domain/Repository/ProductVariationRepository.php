<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ProductVariation;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ProductVariationRepository
{
    private const ORDERBY_MAP = [
        'date' => 'created_at',
        'modified' => 'updated_at',
        'id' => 'id',
        'menu_order' => 'menu_order',
        'price' => 'price',
        'popularity' => 'total_sales',
        'rating' => 'average_rating',
    ];

    private const STATUS_VALUES = ['any', 'publish', 'draft', 'pending', 'private', 'trash'];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $productId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$productId], $params);

        $sql = 'SELECT * FROM product_variations WHERE product_id = ?' . $where
            . $this->buildOrderBy($f, $f['include'] ?? null)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): ProductVariation => $this->hydrate($row), $rows);
    }

    public function count(int $productId, array $f): int
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$productId], $params);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM product_variations WHERE product_id = ?' . $where,
            $params,
        );
    }

    public function find(int $productId, int $id): ?ProductVariation
    {
        $row = $this->connection->fetch(
            'SELECT * FROM product_variations WHERE product_id = ? AND id = ?',
            [$productId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $productId, array $payload): ProductVariation
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($productId, $payload, $now): ProductVariation {
            $this->connection->execute(
                'INSERT INTO product_variations (
                    product_id, status, sku, price, regular_price, sale_price,
                    date_on_sale_from, date_on_sale_to, virtual, downloadable,
                    downloads, download_limit, download_expiry, tax_status,
                    tax_class, manage_stock, stock_quantity, stock_status,
                    backorders, backorders_allowed, backordered, low_stock_amount,
                    weight, length, width, height, shipping_class, shipping_class_id,
                    image, attributes, menu_order, meta_data, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $productId,
                    $payload['status'] ?? 'publish',
                    $payload['sku'] ?? null,
                    $payload['price'] ?? ($payload['regular_price'] ?? null),
                    $payload['regular_price'] ?? null,
                    $payload['sale_price'] ?? null,
                    $payload['date_on_sale_from'] ?? null,
                    $payload['date_on_sale_to'] ?? null,
                    (int) ($payload['virtual'] ?? 0),
                    (int) ($payload['downloadable'] ?? 0),
                    $payload['downloads'] ?? null,
                    $payload['download_limit'] ?? -1,
                    $payload['download_expiry'] ?? -1,
                    $payload['tax_status'] ?? 'taxable',
                    $payload['tax_class'] ?? '',
                    (int) ($payload['manage_stock'] ?? 0),
                    $payload['stock_quantity'] ?? null,
                    $payload['stock_status'] ?? 'instock',
                    $payload['backorders'] ?? 'no',
                    (int) ($payload['backorders_allowed'] ?? 0),
                    (int) ($payload['backordered'] ?? 0),
                    $payload['low_stock_amount'] ?? null,
                    $payload['weight'] ?? null,
                    $payload['length'] ?? null,
                    $payload['width'] ?? null,
                    $payload['height'] ?? null,
                    $payload['shipping_class'] ?? null,
                    $payload['shipping_class_id'] ?? 0,
                    $payload['image'] ?? null,
                    $payload['attributes'] ?? null,
                    (int) ($payload['menu_order'] ?? 0),
                    $payload['meta_data'] ?? null,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM product_variations WHERE product_id = ? ORDER BY id DESC LIMIT 1',
                [$productId],
            );

            $variation = $this->find($productId, $id);
            if ($variation === null) {
                throw new \RuntimeException('Variation insert failed.');
            }

            return $variation;
        });
    }

    public function update(int $productId, int $id, array $payload): ProductVariation
    {
        return $this->connection->transaction(function () use ($productId, $id, $payload): ProductVariation {
            $existing = $this->find($productId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_product_variation_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['product_id'], $updates['date_created'], $updates['date_modified']);

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

            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE product_variations SET $set WHERE product_id = ? AND id = ?",
                    [...array_values($updates), $productId, $id],
                );
            }

            $variation = $this->find($productId, $id);
            if ($variation === null) {
                throw new \RuntimeException('Variation update failed.');
            }

            return $variation;
        });
    }

    public function delete(int $productId, int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($productId, $id, $force): array {
            $existing = $this->find($productId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_product_variation_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute(
                    'DELETE FROM product_variations WHERE product_id = ? AND id = ?',
                    [$productId, $id],
                );

                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE product_variations SET status = ?, updated_at = ? WHERE product_id = ? AND id = ?',
                ['trash', gmdate('Y-m-d H:i:s'), $productId, $id],
            );

            $trashed = $this->find($productId, $id);
            if ($trashed === null) {
                throw new \RuntimeException('Variation trash failed.');
            }

            return [$trashed, false];
        });
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private const WRITABLE_COLUMNS = [
        'status', 'sku', 'regular_price', 'sale_price',
        'date_on_sale_from', 'date_on_sale_to', 'virtual', 'downloadable',
        'downloads', 'download_limit', 'download_expiry', 'tax_status',
        'tax_class', 'manage_stock', 'stock_quantity', 'stock_status',
        'backorders', 'backorders_allowed', 'backordered', 'low_stock_amount',
        'weight', 'length', 'width', 'height', 'shipping_class', 'shipping_class_id',
        'image', 'attributes', 'menu_order', 'meta_data', 'updated_at',
    ];

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
            $clauses[] = '(sku LIKE ? ESCAPE \'\\\' OR description LIKE ? ESCAPE \'\\\')';
            array_push($params, $like, $like);
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

        if (isset($f['offset']) && is_int($f['offset'])) {
        }

        $where = $clauses === [] ? '' : ' AND ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    private function buildOrderBy(array $f, mixed $include): string
    {
        $direction = strtolower($f['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        if (($f['orderby'] ?? 'menu_order') === 'include' && is_array($include) && $include !== []) {
            $cases = [];
            foreach (array_values($include) as $i => $id) {
                $cases[] = sprintf('WHEN %d THEN %d', (int) $id, $i);
            }
            return ' ORDER BY CASE id ' . implode(' ', $cases) . ' ELSE ' . count($include) . ' END ASC';
        }

        $column = self::ORDERBY_MAP[$f['orderby'] ?? 'menu_order'] ?? 'menu_order';

        return sprintf(' ORDER BY %s %s', $column, $direction);
    }

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

        foreach (['search'] as $key) {
            if (isset($f[$key]) && is_string($f[$key])) {
                $out[$key] = $f[$key];
            }
        }

        foreach (['include', 'exclude'] as $key) {
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

        return $out;
    }

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

    /**


     * @param array<string, mixed> $row


     * @return ProductVariation


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\ProductVariation



     */



    private function hydrate(array $row): \App\Domain\Model\ProductVariation
    {
        $variation = new ProductVariation();

        foreach ($row as $column => $value) {
            $variation->{$column} = $value;
        }

        return $variation;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}