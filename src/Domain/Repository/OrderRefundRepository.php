<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\OrderRefund;
use FlintPHP\Framework\Database\ConnectionInterface;

final class OrderRefundRepository
{
    private const ORDERBY_MAP = [
        'date' => 'date_created',
        'id' => 'id',
    ];

    private const STATUS_VALUES = ['pending', 'completed', 'failed'];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $orderId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$orderId], $params);

        $sql = 'SELECT * FROM order_refunds WHERE order_id = ?' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): \App\Domain\Model\OrderRefund => $this->hydrate($row), $rows);
    }

    public function count(int $orderId, array $f): int
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$orderId], $params);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM order_refunds WHERE order_id = ?' . $where,
            $params,
        );
    }

    public function find(int $orderId, int $id): ?\App\Domain\Model\OrderRefund
    {
        $row = $this->connection->fetch(
            'SELECT * FROM order_refunds WHERE order_id = ? AND id = ?',
            [$orderId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $orderId, array $payload): \App\Domain\Model\OrderRefund
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($orderId, $payload, $now): \App\Domain\Model\OrderRefund {
            $this->connection->execute(
                'INSERT INTO order_refunds (
                    order_id, amount, reason, status, refunded_by, refunded_via, date_created, date_created_gmt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $payload['amount'],
                    $payload['reason'] ?? '',
                    $payload['status'] ?? 'pending',
                    $payload['refunded_by'] ?? null,
                    $payload['refunded_via'] ?? null,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM order_refunds WHERE order_id = ? ORDER BY id DESC LIMIT 1',
                [$orderId],
            );

            $refund = $this->find($orderId, $id);
            if ($refund === null) {
                throw new \RuntimeException('Refund insert failed.');
            }

            return $refund;
        });
    }

    public function update(int $orderId, int $id, array $payload): \App\Domain\Model\OrderRefund
    {
        return $this->connection->transaction(function () use ($orderId, $id, $payload): \App\Domain\Model\OrderRefund {
            $existing = $this->find($orderId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_order_refund_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['order_id'], $updates['date_created'], $updates['date_created_gmt']);

            if (isset($updates['status']) && !in_array($updates['status'], self::STATUS_VALUES, true)) {
                throw WcApiException::invalidParam(['status' => 'status is not one of ' . implode(',', self::STATUS_VALUES)]);
            }

            if (isset($updates['amount']) && $updates['amount'] !== null) {
                if (!is_numeric($updates['amount']) || (float) $updates['amount'] < 0) {
                    throw WcApiException::invalidParam(['amount' => 'amount must be a non-negative number.']);
                }
            }

            $updates['date_created_gmt'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE order_refunds SET $set WHERE order_id = ? AND id = ?",
                    [...array_values($updates), $orderId, $id],
                );
            }

            $refund = $this->find($orderId, $id);
            if ($refund === null) {
                throw new \RuntimeException('Refund update failed.');
            }

            return $refund;
        });
    }

    public function delete(int $orderId, int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($orderId, $id, $force): array {
            $existing = $this->find($orderId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_order_refund_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute(
                    'DELETE FROM order_refunds WHERE order_id = ? AND id = ?',
                    [$orderId, $id],
                );
                return [$existing, true];
            }

            return [$existing, false];
        });
    }

    private const WRITABLE_COLUMNS = [
        'amount', 'reason', 'status', 'refunded_by', 'refunded_via', 'date_created_gmt',
    ];

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['status']) && $f['status'] !== '') {
            $clauses[] = 'status = ?';
            $params[] = $f['status'];
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
        $direction = strtolower($f['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $column = self::ORDERBY_MAP[$f['orderby'] ?? 'date'] ?? 'date_created';

        return sprintf(' ORDER BY %s %s', $column, $direction);
    }

    /**


     * @param array<string, mixed> $f


     * @return array<string, mixed>


     */


    public function normalizeFilters(array $f): array
    {
        $out = [];

        if (isset($f['status']) && $f['status'] !== '') {
            if (!in_array($f['status'], self::STATUS_VALUES, true)) {
                throw WcApiException::invalidParam(['status' => 'status is not one of ' . implode(',', self::STATUS_VALUES)]);
            }
            $out['status'] = $f['status'];
        }

        if (isset($f['orderby']) && !array_key_exists($f['orderby'], self::ORDERBY_MAP)) {
            throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']);
        }

        if (isset($f['order']) && !in_array(strtolower((string) $f['order']), ['asc', 'desc'], true)) {
            throw WcApiException::invalidParam(['order' => 'order must be asc or desc']);
        }

        return $out;
    }

    private function hydrate(array $row): \App\Domain\Model\OrderRefund
    {
        $refund = new \App\Domain\Model\OrderRefund();

        foreach ($row as $column => $value) {
            $refund->{$column} = $value;
        }

        return $refund;
    }
}