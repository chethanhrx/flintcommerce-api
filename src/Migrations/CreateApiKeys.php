<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateApiKeys implements MigrationInterface
{
    public function id(): string
    {
        return '20261116120000_create_api_keys';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE api_keys (
                id %s,
                user_id %s NULL,
                description %s NOT NULL DEFAULT \'\',
                permissions %s NOT NULL DEFAULT \'read\',
                consumer_key %s NOT NULL,
                consumer_secret %s NOT NULL,
                truncated_key %s NOT NULL,
                last_access %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 200),
            Sql::varchar($connection, 10),
            Sql::varchar($connection, 64),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 7),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $driver = Sql::driver($connection);
        if ($driver === 'sqlite') {
            $connection->execute('CREATE UNIQUE INDEX idx_api_keys_consumer_key ON api_keys (consumer_key)');
        } else {
            $connection->execute('CREATE UNIQUE INDEX idx_api_keys_consumer_key ON api_keys (consumer_key)');
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE api_keys');
    }
}
