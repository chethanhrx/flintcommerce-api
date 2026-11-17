<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateCustomerAddresses implements MigrationInterface
{
    public function id(): string
    {
        return '20261116160001_create_customer_addresses';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE customer_addresses (
                id %s,
                customer_id %s NOT NULL,
                type %s NOT NULL,
                first_name %s NULL,
                last_name %s NULL,
                company %s NULL,
                address_1 %s NULL,
                address_2 %s NULL,
                city %s NULL,
                state %s NULL,
                postcode %s NULL,
                country %s NULL,
                phone %s NULL,
                is_default %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_address_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 100),
            Sql::varchar($connection, 100),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 2),
            Sql::varchar($connection, 255),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_customer_addresses_customer_id ON customer_addresses (customer_id)');
        $connection->execute('CREATE INDEX idx_customer_addresses_type ON customer_addresses (type)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS customer_addresses');
    }
}