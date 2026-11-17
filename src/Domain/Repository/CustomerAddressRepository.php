<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\CustomerAddress;
use FlintPHP\Framework\Database\ConnectionInterface;

final class CustomerAddressRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $customerId, string $type = ''): array
    {
        if ($type !== '') {
            $rows = $this->connection->fetchAll(
                'SELECT * FROM customer_addresses WHERE customer_id = ? AND type = ? ORDER BY is_default DESC, created_at DESC',
                [$customerId, $type],
            );
        } else {
            $rows = $this->connection->fetchAll(
                'SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC',
                [$customerId],
            );
        }

        return array_map(fn (array $row): CustomerAddress => $this->hydrate($row), $rows);
    }

    public function find(int $customerId, int $id): ?CustomerAddress
    {
        $row = $this->connection->fetch(
            'SELECT * FROM customer_addresses WHERE customer_id = ? AND id = ?',
            [$customerId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findDefault(int $customerId, string $type): ?CustomerAddress
    {
        $row = $this->connection->fetch(
            'SELECT * FROM customer_addresses WHERE customer_id = ? AND type = ? AND is_default = 1',
            [$customerId, $type],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $customerId, array $payload): CustomerAddress
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($customerId, $payload, $now): CustomerAddress {
            if (($payload['is_default'] ?? 0) === 1) {
                $this->connection->execute(
                    'UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ? AND type = ?',
                    [$customerId, $payload['type']],
                );
            }

            $this->connection->execute(
                'INSERT INTO customer_addresses (
                    customer_id, type, first_name, last_name, company, address_1, address_2,
                    city, state, postcode, country, phone, is_default, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $customerId,
                    $payload['type'],
                    $payload['first_name'] ?? null,
                    $payload['last_name'] ?? null,
                    $payload['company'] ?? null,
                    $payload['address_1'] ?? null,
                    $payload['address_2'] ?? null,
                    $payload['city'] ?? null,
                    $payload['state'] ?? null,
                    $payload['postcode'] ?? null,
                    $payload['country'] ?? null,
                    $payload['phone'] ?? null,
                    (int) ($payload['is_default'] ?? 0),
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM customer_addresses WHERE customer_id = ? ORDER BY id DESC LIMIT 1',
                [$customerId],
            );

            $address = $this->find($customerId, $id);
            if ($address === null) {
                throw new \RuntimeException('Address insert failed.');
            }

            return $address;
        });
    }

    public function update(int $customerId, int $id, array $payload): CustomerAddress
    {
        return $this->connection->transaction(function () use ($customerId, $id, $payload): CustomerAddress {
            $existing = $this->find($customerId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_customer_address_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['customer_id'], $updates['date_created'], $updates['date_modified']);

            if (($updates['is_default'] ?? 0) === 1) {
                $this->connection->execute(
                    'UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ? AND type = ? AND id != ?',
                    [$customerId, $existing->type, $id],
                );
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE customer_addresses SET $set WHERE customer_id = ? AND id = ?",
                    [...array_values($updates), $customerId, $id],
                );
            }

            $address = $this->find($customerId, $id);
            if ($address === null) {
                throw new \RuntimeException('Address update failed.');
            }

            return $address;
        });
    }

    public function delete(int $customerId, int $id): void
    {
        $this->connection->execute(
            'DELETE FROM customer_addresses WHERE customer_id = ? AND id = ?',
            [$customerId, $id],
        );
    }

    private const WRITABLE_COLUMNS = [
        'type', 'first_name', 'last_name', 'company', 'address_1', 'address_2',
        'city', 'state', 'postcode', 'country', 'phone', 'is_default', 'updated_at',
    ];

    /**


     * @param array<string, mixed> $row


     * @return CustomerAddress


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\CustomerAddress



     */



    private function hydrate(array $row): \App\Domain\Model\CustomerAddress
    {
        $address = new CustomerAddress();

        foreach ($row as $column => $value) {
            $address->{$column} = $value;
        }

        return $address;
    }
}