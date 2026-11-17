<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\Customer;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

final class CustomerRepository
{
    private const ORDERBY_MAP = [
        'id' => 'id',
        'name' => 'first_name',
        'email' => 'email',
        'registered' => 'created_at',
    ];

    private const ROLES = ['customer', 'administrator', 'shop_manager'];

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
        $sql = 'SELECT * FROM customers' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): Customer => $this->hydrate($row), $rows);
    }

    /**


     * @param array<string, mixed> $f


     * @return int


     */


    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM customers' . $where,
            $params,
        );
    }

    public function find(int $id): ?Customer
    {
        $row = $this->connection->fetch('SELECT * FROM customers WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?Customer
    {
        $row = $this->connection->fetch('SELECT * FROM customers WHERE email = ?', [$email]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByUsername(string $username): ?Customer
    {
        $row = $this->connection->fetch('SELECT * FROM customers WHERE username = ?', [$username]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**


     * @param array<string, mixed> $payload


     * @return Customer


     */


    public function create(array $payload): Customer
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): Customer {
            $this->connection->execute(
                'INSERT INTO customers (email, password_hash, first_name, last_name, username, role, avatar_url, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $payload['email'],
                    $payload['password_hash'],
                    $payload['first_name'] ?? null,
                    $payload['last_name'] ?? null,
                    $payload['username'] ?? Slug::fromName($payload['email']),
                    $payload['role'] ?? 'customer',
                    $payload['avatar_url'] ?? null,
                    (int) ($payload['is_active'] ?? 1),
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM customers WHERE email = ?', [$payload['email']]);

            $customer = $this->find($id);
            if ($customer === null) {
                throw new \RuntimeException('Customer insert failed.');
            }

            return $customer;
        });
    }

    /**


     * @param array<string, mixed> $payload


     * @return Customer


     */


    public function update(int $id, array $payload): Customer
    {
        return $this->connection->transaction(function () use ($id, $payload): Customer {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_customer_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['password_hash'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['email']) && $updates['email'] !== $existing->email) {
                $exists = $this->connection->fetchColumn(
                    'SELECT COUNT(*) FROM customers WHERE email = ? AND id != ?',
                    [$updates['email'], $id],
                );
                if ((int) $exists > 0) {
                    throw WcApiException::invalidParam(['email' => 'An account with that email already exists.']);
                }
            }

            if (isset($updates['username']) && $updates['username'] !== $existing->username) {
                $exists = $this->connection->fetchColumn(
                    'SELECT COUNT(*) FROM customers WHERE username = ? AND id != ?',
                    [$updates['username'], $id],
                );
                if ((int) $exists > 0) {
                    throw WcApiException::invalidParam(['username' => 'An account with that username already exists.']);
                }
            }

            if (isset($updates['password'])) {
                if (!is_string($updates['password']) || strlen($updates['password']) < 8) {
                    throw WcApiException::invalidParam(['password' => 'Password must be at least 8 characters.']);
                }
                $updates['password_hash'] = password_hash($updates['password'], PASSWORD_BCRYPT);
                unset($updates['password']);
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE customers SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $customer = $this->find($id);
            if ($customer === null) {
                throw new \RuntimeException('Customer update failed.');
            }

            return $customer;
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
                throw new WcApiException('woocommerce_rest_customer_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute('DELETE FROM customer_addresses WHERE customer_id = ?', [$id]);
                $this->connection->execute('DELETE FROM customers WHERE id = ?', [$id]);
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE customers SET is_active = 0, updated_at = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), $id],
            );

            $deactivated = $this->find($id);
            if ($deactivated === null) {
                throw new \RuntimeException('Customer deactivation failed.');
            }

            return [$deactivated, false];
        });
    }

    public function verifyPassword(Customer $customer, string $password): bool
    {
        return password_verify($password, $customer->password_hash);
    }

    public function updateLastLogin(int $id): void
    {
        $this->connection->execute(
            'UPDATE customers SET last_login_at = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s'), $id],
        );
    }

    private const WRITABLE_COLUMNS = [
        'email', 'first_name', 'last_name', 'username', 'role', 'avatar_url',
        'is_active', 'password_hash', 'updated_at',
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
            $clauses[] = '(first_name LIKE ? ESCAPE \'\\\' OR last_name LIKE ? ESCAPE \'\\\' OR email LIKE ? ESCAPE \'\\\' OR username LIKE ? ESCAPE \'\\\')';
            array_push($params, $like, $like, $like, $like);
        }

        if (isset($f['email']) && $f['email'] !== '') {
            $clauses[] = 'email = ?';
            $params[] = $f['email'];
        }

        if (isset($f['username']) && $f['username'] !== '') {
            $clauses[] = 'username = ?';
            $params[] = $f['username'];
        }

        if (isset($f['role']) && $f['role'] !== '') {
            $clauses[] = 'role = ?';
            $params[] = $f['role'];
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

        if (isset($f['status'])) {
            $statuses = $f['status'];
            if ($statuses !== ['any']) {
                $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
                $clauses[] = sprintf('is_active IN (%s)', $placeholders);
                foreach ($statuses as $s) {
                    $params[] = $s === 'active' ? 1 : 0;
                }
            }
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
        $column = self::ORDERBY_MAP[$f['orderby'] ?? 'registered'] ?? 'created_at';

        return sprintf(' ORDER BY %s %s', $column, $direction);
    }

    /**


     * @param array<string, mixed> $f


     * @return array<string, mixed>


     */


    public function normalizeFilters(array $f): array
    {
        $out = [];

        if (isset($f['search']) && is_string($f['search'])) {
            $out['search'] = $f['search'];
        }

        if (isset($f['email']) && is_string($f['email'])) {
            $out['email'] = $f['email'];
        }

        if (isset($f['username']) && is_string($f['username'])) {
            $out['username'] = $f['username'];
        }

        if (isset($f['role']) && is_string($f['role'])) {
            if (!in_array($f['role'], self::ROLES, true)) {
                throw WcApiException::invalidParam(['role' => 'role is not one of ' . implode(',', self::ROLES)]);
            }
            $out['role'] = $f['role'];
        }

        if (isset($f['status'])) {
            $statuses = is_array($f['status']) ? $f['status'] : [$f['status']];
            foreach ($statuses as $s) {
                if (!in_array($s, ['any', 'active', 'inactive'], true)) {
                    throw WcApiException::invalidParam(['status' => 'status is not one of active, inactive']);
                }
            }
            $out['status'] = $statuses;
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


     * @return Customer


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\Customer



     */



    private function hydrate(array $row): \App\Domain\Model\Customer
    {
        $customer = new Customer();

        foreach ($row as $column => $value) {
            $customer->{$column} = $value;
        }

        return $customer;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}