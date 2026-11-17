<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ShippingZoneMethod;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ShippingZoneMethodRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $zoneId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$zoneId], $params);

        $sql = 'SELECT * FROM shipping_zone_methods WHERE zone_id = ?' . $where
            . ' ORDER BY method_order ASC, id ASC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): \App\Domain\Model\ShippingZoneMethod => $this->hydrate($row), $rows);
    }

    public function count(int $zoneId, array $f): int
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$zoneId], $params);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM shipping_zone_methods WHERE zone_id = ?' . $where,
            $params,
        );
    }

    public function find(int $zoneId, int $id): ?\App\Domain\Model\ShippingZoneMethod
    {
        $row = $this->connection->fetch(
            'SELECT * FROM shipping_zone_methods WHERE zone_id = ? AND id = ?',
            [$zoneId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $zoneId, array $payload): \App\Domain\Model\ShippingZoneMethod
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($zoneId, $payload, $now): \App\Domain\Model\ShippingZoneMethod {
            $this->connection->execute(
                'INSERT INTO shipping_zone_methods (
                    zone_id, method_id, method_order, is_enabled, settings, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $zoneId,
                    $payload['method_id'],
                    $payload['method_order'] ?? 0,
                    (int) ($payload['is_enabled'] ?? 1),
                    $payload['settings'] ?? null,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM shipping_zone_methods WHERE zone_id = ? ORDER BY id DESC LIMIT 1',
                [$zoneId],
            );

            $method = $this->find($zoneId, $id);
            if ($method === null) {
                throw new \RuntimeException('Shipping zone method insert failed.');
            }

            return $method;
        });
    }

    public function update(int $zoneId, int $id, array $payload): \App\Domain\Model\ShippingZoneMethod
    {
        return $this->connection->transaction(function () use ($zoneId, $id, $payload): \App\Domain\Model\ShippingZoneMethod {
            $existing = $this->find($zoneId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_shipping_zone_method_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['zone_id'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['is_enabled']) && $updates['is_enabled'] !== null) {
                $updates['is_enabled'] = self::booleanParam($updates['is_enabled'], 'is_enabled') ? 1 : 0;
            }

            if (isset($updates['settings']) && is_array($updates['settings'])) {
                $updates['settings'] = json_encode($updates['settings']);
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE shipping_zone_methods SET $set WHERE zone_id = ? AND id = ?",
                    [...array_values($updates), $zoneId, $id],
                );
            }

            $method = $this->find($zoneId, $id);
            if ($method === null) {
                throw new \RuntimeException('Shipping zone method update failed.');
            }

            return $method;
        });
    }

    public function delete(int $zoneId, int $id): void
    {
        $this->connection->execute(
            'DELETE FROM shipping_zone_methods WHERE zone_id = ? AND id = ?',
            [$zoneId, $id],
        );
    }

    private const WRITABLE_COLUMNS = [
        'method_id', 'method_order', 'is_enabled', 'settings', 'updated_at',
    ];

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['method_id']) && $f['method_id'] !== '') {
            $clauses[] = 'method_id = ?';
            $params[] = $f['method_id'];
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

        if (isset($f['method_id']) && $f['method_id'] !== '') {
            if (!is_string($f['method_id']) || trim($f['method_id']) === '') {
                throw WcApiException::invalidParam(['method_id' => 'method_id must be a non-empty string.']);
            }
            $out['method_id'] = $f['method_id'];
        }

        return $out;
    }

    private static function booleanParam(mixed $raw, string $key): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $v = is_string($raw) ? strtolower(trim($raw)) : $raw;

        return match ($v) {
            1, '1', 'true', 'yes' => true,
            0, '0', 'false', 'no' => false,
            default => throw WcApiException::invalidParam([$key => "$key must be boolean"]),
        };
    }

    private function hydrate(array $row): \App\Domain\Model\ShippingZoneMethod
    {
        $method = new \App\Domain\Model\ShippingZoneMethod();

        foreach ($row as $column => $value) {
            $method->{$column} = $value;
        }

        return $method;
    }
}