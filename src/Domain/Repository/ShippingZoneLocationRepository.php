<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ShippingZoneLocation;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ShippingZoneLocationRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $zoneId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$zoneId], $params);

        $sql = 'SELECT * FROM shipping_zone_locations WHERE zone_id = ?' . $where
            . ' ORDER BY type ASC, code ASC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): \App\Domain\Model\ShippingZoneLocation => $this->hydrate($row), $rows);
    }

    public function count(int $zoneId, array $f): int
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$zoneId], $params);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM shipping_zone_locations WHERE zone_id = ?' . $where,
            $params,
        );
    }

    public function find(int $zoneId, int $id): ?\App\Domain\Model\ShippingZoneLocation
    {
        $row = $this->connection->fetch(
            'SELECT * FROM shipping_zone_locations WHERE zone_id = ? AND id = ?',
            [$zoneId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $zoneId, array $payload): \App\Domain\Model\ShippingZoneLocation
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($zoneId, $payload, $now): \App\Domain\Model\ShippingZoneLocation {
            $this->connection->execute(
                'INSERT INTO shipping_zone_locations (zone_id, type, code, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $zoneId,
                    $payload['type'],
                    $payload['code'],
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM shipping_zone_locations WHERE zone_id = ? AND type = ? AND code = ?',
                [$zoneId, $payload['type'], $payload['code']],
            );

            $location = $this->find($zoneId, $id);
            if ($location === null) {
                throw new \RuntimeException('Shipping zone location insert failed.');
            }

            return $location;
        });
    }

    public function update(int $zoneId, int $id, array $payload): \App\Domain\Model\ShippingZoneLocation
    {
        return $this->connection->transaction(function () use ($zoneId, $id, $payload): \App\Domain\Model\ShippingZoneLocation {
            $existing = $this->find($zoneId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_shipping_zone_location_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['zone_id'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['type'])) {
                if (!in_array($updates['type'], ['country', 'state', 'postcode', 'city', 'continent'], true)) {
                    throw WcApiException::invalidParam(['type' => 'type must be country, state, postcode, city, or continent.']);
                }
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE shipping_zone_locations SET $set WHERE zone_id = ? AND id = ?",
                    [...array_values($updates), $zoneId, $id],
                );
            }

            $location = $this->find($zoneId, $id);
            if ($location === null) {
                throw new \RuntimeException('Shipping zone location update failed.');
            }

            return $location;
        });
    }

    public function delete(int $zoneId, int $id): void
    {
        $this->connection->execute(
            'DELETE FROM shipping_zone_locations WHERE zone_id = ? AND id = ?',
            [$zoneId, $id],
        );
    }

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['type']) && $f['type'] !== '') {
            $clauses[] = 'type = ?';
            $params[] = $f['type'];
        }

        if (isset($f['code']) && $f['code'] !== '') {
            $clauses[] = 'code = ?';
            $params[] = $f['code'];
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**


     * @param array<string, mixed> $f


     * @return array<string, mixed>


     */


    public function normalizeFilters(array $f): array
    {
        $out = [];

        if (isset($f['type']) && $f['type'] !== '') {
            if (!in_array($f['type'], ['country', 'state', 'postcode', 'city', 'continent'], true)) {
                throw WcApiException::invalidParam(['type' => 'type must be country, state, postcode, city, or continent.']);
            }
            $out['type'] = $f['type'];
        }

        if (isset($f['code']) && $f['code'] !== '') {
            if (!is_string($f['code']) || trim($f['code']) === '') {
                throw WcApiException::invalidParam(['code' => 'code must be a non-empty string.']);
            }
            $out['code'] = $f['code'];
        }

        return $out;
    }

    private function hydrate(array $row): \App\Domain\Model\ShippingZoneLocation
    {
        $location = new \App\Domain\Model\ShippingZoneLocation();

        foreach ($row as $column => $value) {
            $location->{$column} = $value;
        }

        return $location;
    }
}