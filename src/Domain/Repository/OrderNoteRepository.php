<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\OrderNote;
use FlintPHP\Framework\Database\ConnectionInterface;

final class OrderNoteRepository
{
    private const ORDERBY_MAP = [
        'date' => 'date_created',
        'id' => 'id',
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $orderId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$orderId], $params);

        $sql = 'SELECT * FROM order_notes WHERE order_id = ?' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): OrderNote => $this->hydrate($row), $rows);
    }

    public function count(int $orderId, array $f): int
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$orderId], $params);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM order_notes WHERE order_id = ?' . $where,
            $params,
        );
    }

    public function find(int $orderId, int $id): ?OrderNote
    {
        $row = $this->connection->fetch(
            'SELECT * FROM order_notes WHERE order_id = ? AND id = ?',
            [$orderId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $orderId, array $payload): OrderNote
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($orderId, $payload, $now): OrderNote {
            $this->connection->execute(
                'INSERT INTO order_notes (order_id, note, customer_note, added_by_user_id, date_created, date_created_gmt)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $payload['note'],
                    (int) ($payload['customer_note'] ?? 0),
                    $payload['added_by_user_id'] ?? null,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM order_notes WHERE order_id = ? ORDER BY id DESC LIMIT 1',
                [$orderId],
            );

            $note = $this->find($orderId, $id);
            if ($note === null) {
                throw new \RuntimeException('Order note insert failed.');
            }

            return $note;
        });
    }

    public function update(int $orderId, int $id, array $payload): OrderNote
    {
        return $this->connection->transaction(function () use ($orderId, $id, $payload): OrderNote {
            $existing = $this->find($orderId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_order_note_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['order_id'], $updates['date_created'], $updates['date_created_gmt']);

            $updates['date_created_gmt'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE order_notes SET $set WHERE order_id = ? AND id = ?",
                    [...array_values($updates), $orderId, $id],
                );
            }

            $note = $this->find($orderId, $id);
            if ($note === null) {
                throw new \RuntimeException('Order note update failed.');
            }

            return $note;
        });
    }

    public function delete(int $orderId, int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($orderId, $id, $force): array {
            $existing = $this->find($orderId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_order_note_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute(
                    'DELETE FROM order_notes WHERE order_id = ? AND id = ?',
                    [$orderId, $id],
                );
                return [$existing, true];
            }

            return [$existing, false];
        });
    }

    private const WRITABLE_COLUMNS = [
        'note', 'customer_note', 'added_by_user_id', 'date_created_gmt',
    ];

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['type']) && $f['type'] !== '') {
            $clauses[] = 'customer_note = ?';
            $params[] = $f['type'] === 'customer' ? 1 : 0;
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

        if (isset($f['type'])) {
            if (!in_array($f['type'], ['all', 'customer', 'internal'], true)) {
                throw WcApiException::invalidParam(['type' => 'type must be all, customer, or internal.']);
            }
            $out['type'] = $f['type'];
        }

        if (isset($f['orderby']) && !array_key_exists($f['orderby'], self::ORDERBY_MAP)) {
            throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']);
        }

        if (isset($f['order']) && !in_array(strtolower((string) $f['order']), ['asc', 'desc'], true)) {
            throw WcApiException::invalidParam(['order' => 'order must be asc or desc']);
        }

        return $out;
    }

    /**


     * @param array<string, mixed> $row


     * @return OrderNote


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\OrderNote



     */



    private function hydrate(array $row): \App\Domain\Model\OrderNote
    {
        $note = new OrderNote();

        foreach ($row as $column => $value) {
            $note->{$column} = $value;
        }

        return $note;
    }
}