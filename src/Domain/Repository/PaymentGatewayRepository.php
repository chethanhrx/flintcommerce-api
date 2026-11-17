<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\PaymentGateway;
use FlintPHP\Framework\Database\ConnectionInterface;

final class PaymentGatewayRepository
{
    private const ORDERBY_MAP = [
        'id' => 'id',
        'title' => 'title',
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
        $sql = 'SELECT * FROM payment_gateways' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): PaymentGateway => $this->hydrate($row), $rows);
    }

    /**


     * @param array<string, mixed> $f


     * @return int


     */


    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM payment_gateways' . $where,
            $params,
        );
    }

    public function find(int $id): ?PaymentGateway
    {
        $row = $this->connection->fetch('SELECT * FROM payment_gateways WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByIdKey(string $idKey): ?PaymentGateway
    {
        $row = $this->connection->fetch('SELECT * FROM payment_gateways WHERE id_key = ?', [$idKey]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**


     * @param array<string, mixed> $payload


     * @return PaymentGateway


     */


    public function create(array $payload): PaymentGateway
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): PaymentGateway {
            $idKey = $payload['id_key'] ?? strtolower(str_replace(' ', '_', $payload['title']));

            $this->connection->execute(
                'INSERT INTO payment_gateways (
                    id_key, title, description, order_, enabled,
                    method_title, method_description, method_supports,
                    settings, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $idKey,
                    $payload['title'],
                    $payload['description'] ?? '',
                    $payload['order'] ?? 0,
                    (int) ($payload['enabled'] ?? 1),
                    $payload['method_title'] ?? null,
                    $payload['method_description'] ?? null,
                    $payload['method_supports'] ?? null,
                    $payload['settings'] ?? null,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM payment_gateways WHERE id_key = ?', [$idKey]);

            $gateway = $this->find($id);
            if ($gateway === null) {
                throw new \RuntimeException('Payment gateway insert failed.');
            }

            return $gateway;
        });
    }

    /**


     * @param array<string, mixed> $payload


     * @return PaymentGateway


     */


    public function update(int $id, array $payload): PaymentGateway
    {
        return $this->connection->transaction(function () use ($id, $payload): PaymentGateway {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_payment_gateway_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['id_key'], $updates['date_created'], $updates['date_modified']);

            // API field is `order`; the column is `order_`.
            if (array_key_exists('order', $updates)) {
                $updates['order_'] = (int) $updates['order'];
            }
            unset($updates['order']);

            if (isset($updates['enabled']) && $updates['enabled'] !== null) {
                $updates['enabled'] = self::booleanParam($updates['enabled'], 'enabled') ? 1 : 0;
            }

            if (isset($updates['settings']) && is_array($updates['settings'])) {
                $updates['settings'] = json_encode($updates['settings']);
            }

            if (isset($updates['method_supports']) && is_array($updates['method_supports'])) {
                $updates['method_supports'] = json_encode($updates['method_supports']);
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE payment_gateways SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $gateway = $this->find($id);
            if ($gateway === null) {
                throw new \RuntimeException('Payment gateway update failed.');
            }

            return $gateway;
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
                throw new WcApiException('woocommerce_rest_payment_gateway_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute('DELETE FROM payment_gateways WHERE id = ?', [$id]);
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE payment_gateways SET enabled = 0, updated_at = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), $id],
            );

            $disabled = $this->find($id);
            if ($disabled === null) {
                throw new \RuntimeException('Payment gateway disable failed.');
            }

            return [$disabled, false];
        });
    }

    private const WRITABLE_COLUMNS = [
        'id_key', 'title', 'description', 'order_', 'enabled',
        'method_title', 'method_description', 'method_supports', 'settings', 'updated_at',
    ];

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['enabled']) && $f['enabled'] !== '') {
            // normalizeFilters() may hand back int 0/1 (or the raw string); both must match.
            $enabled = in_array($f['enabled'], [1, '1', 'true', true], true);
            $clauses[] = 'enabled = ?';
            $params[] = $enabled ? 1 : 0;
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

        if (isset($f['enabled']) && $f['enabled'] !== '') {
            if (!in_array($f['enabled'], ['true', 'false', '1', '0'], true)) {
                throw WcApiException::invalidParam(['enabled' => 'enabled must be true or false.']);
            }
            $out['enabled'] = $f['enabled'] === 'true' || $f['enabled'] === '1' ? 1 : 0;
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

    /**


     * @param array<string, mixed> $row


     * @return PaymentGateway


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\PaymentGateway



     */



    private function hydrate(array $row): \App\Domain\Model\PaymentGateway
    {
        $gateway = new PaymentGateway();

        foreach ($row as $column => $value) {
            $gateway->{$column} = $value;
        }

        return $gateway;
    }
}