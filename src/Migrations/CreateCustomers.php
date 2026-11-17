<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateCustomers implements MigrationInterface
{
    public function id(): string
    {
        return '20261116160000_create_customers';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE customers (
                id %s,
                email %s NOT NULL,
                password_hash %s NOT NULL,
                first_name %s NULL,
                last_name %s NULL,
                username %s NULL,
                role %s NOT NULL DEFAULT \'customer\',
                avatar_url %s NULL,
                is_active %s NOT NULL DEFAULT 1,
                last_login_at %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                UNIQUE (email),
                UNIQUE (username)
            )',
            Sql::pk($connection),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 512),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::timestamp($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_customers_email ON customers (email)');
        $connection->execute('CREATE INDEX idx_customers_username ON customers (username)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS customers');
    }
}