<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ShippingZone;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ShippingZoneRepository
{
    private const ORDERBY_MAP = [
        'id' => 'id',
        'name' => 'name',
        'order' => 'order_',
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
        $sql = 'SELECT * FROM shipping_zones' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): ShippingZone => $this->hydrate($row), $rows);
    }

    /**


     * @param array<string, mixed> $f


     * @return int


     */


    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM shipping_zones' . $where,
            $params,
        );
    }

    public function find(int $id): ?\App\Domain\Model\ShippingZone
    {
        $row = $this->connection->fetch('SELECT * FROM shipping_zones WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(array $payload): \App\Domain\Model\ShippingZone
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): \App\Domain\Model\ShippingZone {
            $this->connection->execute(
                'INSERT INTO shipping_zones (name, order_, created_at, updated_at)
                 VALUES (?, ?, ?, ?)',
                [
                    $payload['name'],
                    $payload['order'] ?? 0,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM shipping_zones WHERE name = ?',
                [$payload['name']],
            );

            $zone = $this->find($id);
            if ($zone === null) {
                throw new \RuntimeException('Shipping zone insert failed.');
            }

            return $zone;
        });
    }

    public function update(int $id, array $payload): \App\Domain\Model\ShippingZone
    {
        return $this->connection->transaction(function () use ($id, $payload): \App\Domain\Model\ShippingZone {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_shipping_zone_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['date_created'], $updates['date_modified']);

            // API field is `order`; the column is `order_`.
            if (array_key_exists('order', $updates)) {
                $updates['order_'] = (int) $updates['order'];
            }
            unset($updates['order']);

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE shipping_zones SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $zone = $this->find($id);
            if ($zone === null) {
                throw new \RuntimeException('Shipping zone update failed.');
            }

            return $zone;
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
                throw new WcApiException('woocommerce_rest_shipping_zone_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute('DELETE FROM shipping_zones WHERE id = ?', [$id]);
                return [$existing, true];
            }

            return [$existing, false];
        });
    }

    private const WRITABLE_COLUMNS = [
        'name', 'order_', 'updated_at',
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
            $clauses[] = 'name LIKE ? ESCAPE \'\\\'';
            $params[] = $like;
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
        $column = self::ORDERBY_MAP[$f['orderby'] ?? 'order'] ?? 'order_';

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

        if (isset($f['search']) && is_string($f['search'])) {
            $out['search'] = $f['search'];
        }

        return $out;
    }

    private function hydrate(array $row): \App\Domain\Model\ShippingZone
    {
        $zone = new \App\Domain\Model\ShippingZone();

        foreach ($row as $column => $value) {
            $zone->{$column} = $value;
        }

        return $zone;
    }
}