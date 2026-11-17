<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateShippingClasses implements MigrationInterface
{
    public function id(): string
    {
        return '20261116150002_create_shipping_classes';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE shipping_classes (
                id %s,
                name %s NOT NULL,
                slug %s NOT NULL,
                description %s NULL,
                count %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                UNIQUE (slug)
            )',
            Sql::pk($connection),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_shipping_classes_slug ON shipping_classes (slug)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS shipping_classes');
    }
}