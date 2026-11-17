<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\TaxRate;
use FlintPHP\Framework\Database\ConnectionInterface;

final class TaxRateRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $taxClassId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$taxClassId], $params);

        $sql = 'SELECT * FROM tax_rates WHERE tax_class_id = ?' . $where
            . ' ORDER BY priority ASC, id ASC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): \App\Domain\Model\TaxRate => $this->hydrate($row), $rows);
    }

    public function count(int $taxClassId, array $f): int
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$taxClassId], $params);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM tax_rates WHERE tax_class_id = ?' . $where,
            $params,
        );
    }

    public function find(int $taxClassId, int $id): ?\App\Domain\Model\TaxRate
    {
        $row = $this->connection->fetch(
            'SELECT * FROM tax_rates WHERE tax_class_id = ? AND id = ?',
            [$taxClassId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $taxClassId, array $payload): \App\Domain\Model\TaxRate
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($taxClassId, $payload, $now): \App\Domain\Model\TaxRate {
            $this->connection->execute(
                'INSERT INTO tax_rates (
                    tax_class_id, country, state, postcode, city, rate, name,
                    priority, compound, shipping, order_, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $taxClassId,
                    $payload['country'],
                    $payload['state'] ?? null,
                    $payload['postcode'] ?? null,
                    $payload['city'] ?? null,
                    $payload['rate'],
                    $payload['name'] ?? null,
                    $payload['priority'] ?? 1,
                    (int) ($payload['compound'] ?? 0),
                    (int) ($payload['shipping'] ?? 0),
                    $payload['order'] ?? 0,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM tax_rates WHERE tax_class_id = ? ORDER BY id DESC LIMIT 1',
                [$taxClassId],
            );

            $rate = $this->find($taxClassId, $id);
            if ($rate === null) {
                throw new \RuntimeException('Tax rate insert failed.');
            }

            return $rate;
        });
    }

    public function update(int $taxClassId, int $id, array $payload): \App\Domain\Model\TaxRate
    {
        return $this->connection->transaction(function () use ($taxClassId, $id, $payload): \App\Domain\Model\TaxRate {
            $existing = $this->find($taxClassId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_tax_rate_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['tax_class_id'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['country'])) {
                $updates['country'] = strtoupper($updates['country']);
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE tax_rates SET $set WHERE tax_class_id = ? AND id = ?",
                    [...array_values($updates), $taxClassId, $id],
                );
            }

            $rate = $this->find($taxClassId, $id);
            if ($rate === null) {
                throw new \RuntimeException('Tax rate update failed.');
            }

            return $rate;
        });
    }

    public function delete(int $taxClassId, int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($taxClassId, $id, $force): array {
            $existing = $this->find($taxClassId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_tax_rate_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute(
                    'DELETE FROM tax_rates WHERE tax_class_id = ? AND id = ?',
                    [$taxClassId, $id],
                );
                return [$existing, true];
            }

            $trashed = $this->find($taxClassId, $id);
            if ($trashed === null) {
                throw new \RuntimeException('Tax rate trash failed.');
            }

            return [$trashed, false];
        });
    }

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['country']) && $f['country'] !== '') {
            $clauses[] = 'country = ?';
            $params[] = strtoupper($f['country']);
        }

        if (isset($f['state']) && $f['state'] !== '') {
            $clauses[] = 'state = ?';
            $params[] = $f['state'];
        }

        if (isset($f['postcode']) && $f['postcode'] !== '') {
            $clauses[] = 'postcode = ?';
            $params[] = $f['postcode'];
        }

        $where = $clauses === [] ? '' : ' AND ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $f): array
    {
        $out = [];

        if (isset($f['country']) && $f['country'] !== '') {
            $out['country'] = strtoupper($f['country']);
        }
        if (isset($f['state']) && $f['state'] !== '') {
            $out['state'] = $f['state'];
        }
        if (isset($f['postcode']) && $f['postcode'] !== '') {
            $out['postcode'] = $f['postcode'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return \App\Domain\Model\TaxRate
     */
    private function hydrate(array $row): \App\Domain\Model\TaxRate
    {
        $rate = new \App\Domain\Model\TaxRate();

        foreach ($row as $column => $value) {
            $rate->{$column} = $value;
        }

        return $rate;
    }
}