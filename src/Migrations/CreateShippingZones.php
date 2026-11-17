<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateShippingZones implements MigrationInterface
{
    public function id(): string
    {
        return '20261116190000_create_shipping_zones';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE shipping_zones (
                id %s,
                name %s NOT NULL,
                order_ %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL
            )',
            Sql::pk($connection),
            Sql::varchar($connection, 255),
            Sql::bigInt($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_shipping_zones_order ON shipping_zones (order_)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS shipping_zones');
    }
}