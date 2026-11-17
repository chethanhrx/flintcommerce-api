<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ProductAttributeTerm;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ProductAttributeTermRepository
{
    private const ORDERBY_MAP = [
        'name' => 'name',
        'id' => 'id',
        'slug' => 'slug',
        'menu_order' => 'menu_order',
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $attributeId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$attributeId], $params);

        $sql = 'SELECT * FROM product_attribute_terms WHERE attribute_id = ?' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): ProductAttributeTerm => $this->hydrate($row), $rows);
    }

    public function count(int $attributeId, array $f): int
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$attributeId], $params);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM product_attribute_terms WHERE attribute_id = ?' . $where,
            $params,
        );
    }

    public function find(int $attributeId, int $id): ?ProductAttributeTerm
    {
        $row = $this->connection->fetch(
            'SELECT * FROM product_attribute_terms WHERE attribute_id = ? AND id = ?',
            [$attributeId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $attributeId, array $payload): ProductAttributeTerm
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($attributeId, $payload, $now): ProductAttributeTerm {
            $slug = $payload['slug'] ?? Slug::fromName($payload['name']);
            $slug = $this->uniqueSlug($slug, null, $attributeId);

            $menuOrder = (int) ($payload['menu_order'] ?? $this->getNextMenuOrder($attributeId));

            $this->connection->execute(
                'INSERT INTO product_attribute_terms (attribute_id, name, slug, menu_order, description, count, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?)',
                [
                    $attributeId,
                    $payload['name'],
                    $slug,
                    $menuOrder,
                    $payload['description'] ?? '',
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM product_attribute_terms WHERE attribute_id = ? AND slug = ?',
                [$attributeId, $slug],
            );

            $term = $this->find($attributeId, $id);
            if ($term === null) {
                throw new \RuntimeException('Attribute term insert failed.');
            }

            return $term;
        });
    }

    public function update(int $attributeId, int $id, array $payload): ProductAttributeTerm
    {
        return $this->connection->transaction(function () use ($attributeId, $id, $payload): ProductAttributeTerm {
            $existing = $this->find($attributeId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_product_attribute_term_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['attribute_id'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['slug']) && is_string($updates['slug'])) {
                $updates['slug'] = $this->uniqueSlug($updates['slug'], $id, $attributeId);
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE product_attribute_terms SET $set WHERE attribute_id = ? AND id = ?",
                    [...array_values($updates), $attributeId, $id],
                );
            }

            $term = $this->find($attributeId, $id);
            if ($term === null) {
                throw new \RuntimeException('Attribute term update failed.');
            }

            return $term;
        });
    }

    public function delete(int $attributeId, int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($attributeId, $id, $force): array {
            $existing = $this->find($attributeId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_product_attribute_term_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute(
                    'DELETE FROM product_attribute_terms WHERE attribute_id = ? AND id = ?',
                    [$attributeId, $id],
                );
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE product_attribute_terms SET updated_at = ? WHERE attribute_id = ? AND id = ?',
                [gmdate('Y-m-d H:i:s'), $attributeId, $id],
            );

            $trashed = $this->find($attributeId, $id);
            if ($trashed === null) {
                throw new \RuntimeException('Attribute term trash failed.');
            }

            return [$trashed, false];
        });
    }

    private function getNextMenuOrder(int $attributeId): int
    {
        $max = $this->connection->fetchColumn(
            'SELECT MAX(menu_order) FROM product_attribute_terms WHERE attribute_id = ?',
            [$attributeId],
        );

        return ((int) ($max ?? 0)) + 1;
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null, int $attributeId = 0): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (true) {
            $sql = 'SELECT COUNT(*) FROM product_attribute_terms WHERE slug = ?';
            $params = [$candidate];

            if ($attributeId > 0) {
                $sql .= ' AND attribute_id = ?';
                $params[] = $attributeId;
            }

            if ($ignoreId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $ignoreId;
            }

            if ((int) $this->connection->fetchColumn($sql, $params) === 0) {
                return $candidate;
            }

            $candidate = $slug . '-' . $suffix;
            $suffix++;

            if ($suffix > 100) {
                return $slug . '-' . bin2hex(random_bytes(4));
            }
        }
    }

    private const WRITABLE_COLUMNS = [
        'name', 'slug', 'menu_order', 'description', 'updated_at',
    ];

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['search']) && $f['search'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['search']) . '%';
            $clauses[] = '(name LIKE ? ESCAPE \'\\\' OR slug LIKE ? ESCAPE \'\\\')';
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

        if (isset($f['slug']) && $f['slug'] !== '') {
            $clauses[] = 'slug = ?';
            $params[] = $f['slug'];
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**


     * @param array<string, mixed> $f


     * @return string


     */


    private function buildOrderBy(array $f): string
    {
        $direction = strtolower($f['order'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';
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

        if (isset($f['orderby']) && !array_key_exists($f['orderby'], self::ORDERBY_MAP)) {
            throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']);
        }

        if (isset($f['order']) && !in_array(strtolower((string) $f['order']), ['asc', 'desc'], true)) {
            throw WcApiException::invalidParam(['order' => 'order must be asc or desc']);
        }

        foreach (['search', 'slug'] as $key) {
            if (isset($f[$key]) && is_string($f[$key])) {
                $out[$key] = $f[$key];
            }
        }

        foreach (['include', 'exclude'] as $key) {
            if (isset($f[$key])) {
                $out[$key] = self::idList($f[$key], $key);
            }
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


     * @return ProductAttributeTerm


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\ProductAttributeTerm



     */



    private function hydrate(array $row): \App\Domain\Model\ProductAttributeTerm
    {
        $term = new ProductAttributeTerm();

        foreach ($row as $column => $value) {
            $term->{$column} = $value;
        }

        return $term;
    }
}