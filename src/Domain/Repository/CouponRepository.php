<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\Coupon;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

final class CouponRepository
{
    private const ORDERBY_MAP = [
        'date' => 'date_created',
        'modified' => 'date_modified',
        'id' => 'id',
        'code' => 'code',
    ];

    private const DISCOUNT_TYPES = ['fixed_cart', 'percent', 'fixed_product', 'percent_product'];

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
        $sql = 'SELECT * FROM coupons' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): Coupon => $this->hydrate($row), $rows);
    }

    /**


     * @param array<string, mixed> $f


     * @return int


     */


    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM coupons' . $where,
            $params,
        );
    }

    public function find(int $id): ?Coupon
    {
        $row = $this->connection->fetch('SELECT * FROM coupons WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByCode(string $code): ?Coupon
    {
        $row = $this->connection->fetch('SELECT * FROM coupons WHERE code = ?', [$code]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**


     * @param array<string, mixed> $payload


     * @return Coupon


     */


    public function create(array $payload): Coupon
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): Coupon {
            $code = strtoupper($payload['code'] ?? Slug::fromName($payload['description'] ?? 'coupon'));
            $code = $this->uniqueCode($code);

$this->connection->execute(
                'INSERT INTO coupons (
                    code, amount, discount_type, description, date_expires, date_expires_gmt,
                    usage_count, individual_use, product_ids, excluded_product_ids,
                    usage_limit, usage_limit_per_user, limit_usage_to_x_items,
                    free_shipping, product_categories, excluded_product_categories,
                    exclude_sale_items, minimum_amount, maximum_amount,
                    email_restrictions, used_by,
                    date_created, date_created_gmt, date_modified, date_modified_gmt,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $code,
                    $payload['amount'] ?? '0.00',
                    $payload['discount_type'] ?? 'fixed_cart',
                    $payload['description'] ?? '',
                    $payload['date_expires'] ?? null,
                    $payload['date_expires_gmt'] ?? null,
                    0,
                    (int) ($payload['individual_use'] ?? 0),
                    $payload['product_ids'] ?? null,
                    $payload['excluded_product_ids'] ?? null,
                    $payload['usage_limit'] ?? null,
                    $payload['usage_limit_per_user'] ?? null,
                    $payload['limit_usage_to_x_items'] ?? null,
                    (int) ($payload['free_shipping'] ?? 0),
                    $payload['product_categories'] ?? null,
                    $payload['excluded_product_categories'] ?? null,
                    (int) ($payload['exclude_sale_items'] ?? 0),
                    $payload['minimum_amount'] ?? '0.00',
                    $payload['maximum_amount'] ?? '0.00',
                    $payload['email_restrictions'] ?? null,
                    $payload['used_by'] ?? null,
                    $now,
                    $now,
                    $now,
                    $now,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM coupons WHERE code = ?', [$code]);

            $coupon = $this->find($id);
            if ($coupon === null) {
                throw new \RuntimeException('Coupon insert failed.');
            }

            return $coupon;
        });
    }

    /**


     * @param array<string, mixed> $payload


     * @return Coupon


     */


    public function update(int $id, array $payload): Coupon
    {
        return $this->connection->transaction(function () use ($id, $payload): Coupon {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_coupon_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['code'], $updates['date_created'], $updates['date_created_gmt'], $updates['date_modified'], $updates['date_modified_gmt'], $updates['usage_count']);

            if (isset($updates['discount_type']) && !in_array($updates['discount_type'], self::DISCOUNT_TYPES, true)) {
                throw WcApiException::invalidParam(['discount_type' => 'discount_type is not a valid option']);
            }

            $updates['date_modified'] = gmdate('Y-m-d H:i:s');
            $updates['date_modified_gmt'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE coupons SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $coupon = $this->find($id);
            if ($coupon === null) {
                throw new \RuntimeException('Coupon update failed.');
            }

            return $coupon;
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
                throw new WcApiException('woocommerce_rest_coupon_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute('DELETE FROM coupons WHERE id = ?', [$id]);
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE coupons SET updated_at = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), $id],
            );

            $trashed = $this->find($id);
            if ($trashed === null) {
                throw new \RuntimeException('Coupon trash failed.');
            }

            return [$trashed, false];
        });
    }

    private function uniqueCode(string $code): string
    {
        $candidate = $code;
        $suffix = 2;

        while (true) {
            $exists = $this->connection->fetchColumn('SELECT COUNT(*) FROM coupons WHERE code = ?', [$candidate]);
            if ((int) $exists === 0) {
                return $candidate;
            }
            $candidate = $code . '-' . $suffix;
            $suffix++;
        }
    }

    private const WRITABLE_COLUMNS = [
        'amount', 'discount_type', 'description', 'date_expires', 'date_expires_gmt',
        'individual_use', 'product_ids', 'excluded_product_ids', 'usage_limit',
        'usage_limit_per_user', 'limit_usage_to_x_items', 'free_shipping',
        'product_categories', 'excluded_product_categories', 'exclude_sale_items',
        'minimum_amount', 'maximum_amount', 'email_restrictions', 'used_by',
        'date_modified', 'date_modified_gmt',
    ];

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['code']) && $f['code'] !== '') {
            $clauses[] = 'code = ?';
            $params[] = $f['code'];
        }

        if (isset($f['search']) && $f['search'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['search']) . '%';
            $clauses[] = '(code LIKE ? ESCAPE \'\\\' OR description LIKE ? ESCAPE \'\\\')';
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

        if (isset($f['discount_type'])) {
            $clauses[] = 'discount_type = ?';
            $params[] = $f['discount_type'];
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


     * @return Coupon


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\Coupon



     */



    private function hydrate(array $row): \App\Domain\Model\Coupon
    {
        $coupon = new Coupon();

        foreach ($row as $column => $value) {
            $coupon->{$column} = $value;
        }

        return $coupon;
    }
}