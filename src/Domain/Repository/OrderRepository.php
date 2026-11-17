<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\Order;
use App\Domain\Model\OrderItem;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

final class OrderRepository
{
    private const ORDERBY_MAP = [
        'date' => 'date_created',
        'modified' => 'date_modified',
        'id' => 'id',
        'total' => 'total',
    ];

    private const STATUS_VALUES = ['any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'trash'];

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
        $sql = 'SELECT * FROM orders' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): Order => $this->hydrate($row), $rows);
    }

    /**


     * @param array<string, mixed> $f


     * @return int


     */


    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM orders' . $where,
            $params,
        );
    }

    public function find(int $id): ?Order
    {
        $row = $this->connection->fetch('SELECT * FROM orders WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**


     * @param array<string, mixed> $payload


     * @return Order


     */


    public function create(array $payload): Order
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): Order {
            $orderKey = $payload['order_key'] ?? 'wc_order_' . Slug::fromName(uniqid('', true));

            $this->connection->execute(
                'INSERT INTO orders (
                    parent_id, status, currency, version, prices_include_tax,
                    date_created, date_created_gmt, date_modified, date_modified_gmt,
                    discount_total, discount_tax, shipping_total, shipping_tax,
                    cart_tax, total, total_tax, customer_id, order_key,
                    billing_first_name, billing_last_name, billing_company,
                    billing_address_1, billing_address_2, billing_city, billing_state,
                    billing_postcode, billing_country, billing_email, billing_phone,
                    shipping_first_name, shipping_last_name, shipping_company,
                    shipping_address_1, shipping_address_2, shipping_city, shipping_state,
                    shipping_postcode, shipping_country, shipping_phone,
                    payment_method, payment_method_title, transaction_id,
                    customer_ip_address, customer_user_agent, created_via,
                    coupon_codes, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $payload['parent_id'] ?? 0,
                    $payload['status'] ?? 'pending',
                    $payload['currency'] ?? 'USD',
                    $payload['version'] ?? 'fc/v3',
                    (int) ($payload['prices_include_tax'] ?? 0),
                    $now,
                    $now,
                    $now,
                    $now,
                    $payload['discount_total'] ?? '0.00',
                    $payload['discount_tax'] ?? '0.00',
                    $payload['shipping_total'] ?? '0.00',
                    $payload['shipping_tax'] ?? '0.00',
                    $payload['cart_tax'] ?? '0.00',
                    $payload['total'] ?? '0.00',
                    $payload['total_tax'] ?? '0.00',
                    $payload['customer_id'] ?? 0,
                    $orderKey,
                    $payload['billing_first_name'] ?? null,
                    $payload['billing_last_name'] ?? null,
                    $payload['billing_company'] ?? null,
                    $payload['billing_address_1'] ?? null,
                    $payload['billing_address_2'] ?? null,
                    $payload['billing_city'] ?? null,
                    $payload['billing_state'] ?? null,
                    $payload['billing_postcode'] ?? null,
                    $payload['billing_country'] ?? null,
                    $payload['billing_email'] ?? null,
                    $payload['billing_phone'] ?? null,
                    $payload['shipping_first_name'] ?? null,
                    $payload['shipping_last_name'] ?? null,
                    $payload['shipping_company'] ?? null,
                    $payload['shipping_address_1'] ?? null,
                    $payload['shipping_address_2'] ?? null,
                    $payload['shipping_city'] ?? null,
                    $payload['shipping_state'] ?? null,
                    $payload['shipping_postcode'] ?? null,
                    $payload['shipping_country'] ?? null,
                    $payload['shipping_phone'] ?? null,
                    $payload['payment_method'] ?? null,
                    $payload['payment_method_title'] ?? null,
                    $payload['transaction_id'] ?? null,
                    $payload['customer_ip_address'] ?? null,
                    $payload['customer_user_agent'] ?? null,
                    $payload['created_via'] ?? 'wc-api',
                    $payload['coupon_codes'] ?? null,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM orders WHERE order_key = ?', [$orderKey]);

            $order = $this->find($id);
            if ($order === null) {
                throw new \RuntimeException('Order insert failed.');
            }

            return $order;
        });
    }

    /**


     * @param array<string, mixed> $payload


     * @return Order


     */


    public function update(int $id, array $payload): Order
    {
        return $this->connection->transaction(function () use ($id, $payload): Order {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_order_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['date_created'], $updates['date_created_gmt'], $updates['date_modified'], $updates['date_modified_gmt']);

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE orders SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $order = $this->find($id);
            if ($order === null) {
                throw new \RuntimeException('Order update failed.');
            }

            return $order;
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
                throw new WcApiException('woocommerce_rest_order_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute('DELETE FROM order_items WHERE order_id = ?', [$id]);
                $this->connection->execute('DELETE FROM orders WHERE id = ?', [$id]);
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
                ['trash', gmdate('Y-m-d H:i:s'), $id],
            );

            $trashed = $this->find($id);
            if ($trashed === null) {
                throw new \RuntimeException('Order trash failed.');
            }

            return [$trashed, false];
        });
    }

    public function getItems(int $orderId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC',
            [$orderId],
        );

        return array_map(fn (array $row): OrderItem => $this->hydrateItem($row), $rows);
    }

    private const WRITABLE_COLUMNS = [
        'parent_id', 'status', 'currency', 'version', 'prices_include_tax',
        'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax',
        'cart_tax', 'total', 'total_tax', 'customer_id', 'order_key',
        'billing_first_name', 'billing_last_name', 'billing_company',
        'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state',
        'billing_postcode', 'billing_country', 'billing_email', 'billing_phone',
        'shipping_first_name', 'shipping_last_name', 'shipping_company',
        'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state',
        'shipping_postcode', 'shipping_country', 'shipping_phone',
        'payment_method', 'payment_method_title', 'transaction_id',
        'customer_ip_address', 'customer_user_agent', 'created_via',
        'completed_at', 'completed_at_gmt', 'paid_at', 'paid_at_gmt',
        'coupon_codes', 'updated_at',
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

        if (isset($f['customer']) && is_int($f['customer'])) {
            $clauses[] = 'customer_id = ?';
            $params[] = $f['customer'];
        }

        if (isset($f['search']) && $f['search'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['search']) . '%';
            $clauses[] = '(billing_first_name LIKE ? ESCAPE \'\\\' OR billing_last_name LIKE ? ESCAPE \'\\\' OR billing_email LIKE ? ESCAPE \'\\\' OR order_key LIKE ? ESCAPE \'\\\')';
            array_push($params, $like, $like, $like, $like);
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

        if (isset($f['dp']) && $f['dp'] !== '') {
        }

        if (isset($f['before']) && is_string($f['before'])) {
            $clauses[] = 'date_created <= ?';
            $params[] = $f['before'];
        }

        if (isset($f['after']) && is_string($f['after'])) {
            $clauses[] = 'date_created >= ?';
            $params[] = $f['after'];
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

        if (($f['orderby'] ?? 'date') === 'include' && is_array($f['include'] ?? null) && $f['include'] !== []) {
            $cases = [];
            foreach (array_values($f['include']) as $i => $id) {
                $cases[] = sprintf('WHEN %d THEN %d', (int) $id, $i);
            }
            return ' ORDER BY CASE id ' . implode(' ', $cases) . ' ELSE ' . count($f['include']) . ' END ASC';
        }

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

        if (isset($f['status'])) {
            $statuses = is_array($f['status']) ? $f['status'] : [$f['status']];
            foreach ($statuses as $s) {
                if (!in_array($s, self::STATUS_VALUES, true)) {
                    throw WcApiException::invalidParam(['status' => 'status is not one of ' . implode(',', self::STATUS_VALUES)]);
                }
            }
            $out['status'] = $statuses;
        }

        if (isset($f['customer']) && is_int($f['customer'])) {
            $out['customer'] = $f['customer'];
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

        if (isset($f['orderby']) && !array_key_exists($f['orderby'], self::ORDERBY_MAP)) {
            throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']);
        }

        if (isset($f['order']) && !in_array(strtolower((string) $f['order']), ['asc', 'desc'], true)) {
            throw WcApiException::invalidParam(['order' => 'order must be asc or desc']);
        }

        if (isset($f['before']) && is_string($f['before'])) {
            $out['before'] = $f['before'];
        }

        if (isset($f['after']) && is_string($f['after'])) {
            $out['after'] = $f['after'];
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


     * @return Order


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\Order



     */



    private function hydrate(array $row): \App\Domain\Model\Order
    {
        $order = new Order();

        foreach ($row as $column => $value) {
            $order->{$column} = $value;
        }

        return $order;
    }

    /**


     * @param array<string, mixed> $row


     * @return OrderItem


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\OrderItem



     */



    private function hydrateItem(array $row): \App\Domain\Model\OrderItem
    {
        $item = new OrderItem();

        foreach ($row as $column => $value) {
            $item->{$column} = $value;
        }

        return $item;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}