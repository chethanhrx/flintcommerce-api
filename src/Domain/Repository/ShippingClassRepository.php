<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ShippingClass;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ShippingClassRepository
{
    private const ORDERBY_MAP = [
        'name' => 'name',
        'id' => 'id',
        'slug' => 'slug',
        'count' => 'count',
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    public function list(array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $sql = 'SELECT * FROM shipping_classes' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): ShippingClass => $this->hydrate($row), $rows);
    }

    /**


     * @param array<string, mixed> $f


     * @return int


     */


    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM shipping_classes' . $where,
            $params,
        );
    }

    public function find(int $id): ?ShippingClass
    {
        $row = $this->connection->fetch('SELECT * FROM shipping_classes WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**


     * @param array<string, mixed> $payload


     * @return ShippingClass


     */


    public function create(array $payload): ShippingClass
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): ShippingClass {
            $slug = $payload['slug'] ?? Slug::fromName($payload['name']);
            $slug = Slug::unique($this->connection, 'shipping_classes', $slug);

            $this->connection->execute(
                'INSERT INTO shipping_classes (name, slug, description, count, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, ?)',
                [
                    $payload['name'],
                    $slug,
                    $payload['description'] ?? '',
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM shipping_classes WHERE slug = ?', [$slug]);

            $sc = $this->find($id);
            if ($sc === null) {
                throw new \RuntimeException('Shipping class insert failed.');
            }

            return $sc;
        });
    }

    /**


     * @param array<string, mixed> $payload


     * @return ShippingClass


     */


    public function update(int $id, array $payload): ShippingClass
    {
        return $this->connection->transaction(function () use ($id, $payload): ShippingClass {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_shipping_class_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['count'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['slug']) && is_string($updates['slug'])) {
                $updates['slug'] = Slug::unique($this->connection, 'shipping_classes', $updates['slug'], $id);
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE shipping_classes SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $sc = $this->find($id);
            if ($sc === null) {
                throw new \RuntimeException('Shipping class update failed.');
            }

            return $sc;
        });
    }

    /**


     * @return array


     */


    public function delete(int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($id, $force): array {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_shipping_class_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute('DELETE FROM shipping_classes WHERE id = ?', [$id]);
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE shipping_classes SET updated_at = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), $id],
            );

            $trashed = $this->find($id);
            if ($trashed === null) {
                throw new \RuntimeException('Shipping class trash failed.');
            }

            return [$trashed, false];
        });
    }

    private const WRITABLE_COLUMNS = [
        'name', 'slug', 'description', 'updated_at',
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
        $column = self::ORDERBY_MAP[$f['orderby'] ?? 'name'] ?? 'name';

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


     * @return ShippingClass


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\ShippingClass



     */



    private function hydrate(array $row): \App\Domain\Model\ShippingClass
    {
        $sc = new ShippingClass();

        foreach ($row as $column => $value) {
            $sc->{$column} = $value;
        }

        return $sc;
    }
}