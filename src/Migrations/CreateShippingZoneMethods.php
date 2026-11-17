<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateShippingZoneMethods implements MigrationInterface
{
    public function id(): string
    {
        return '20261116190002_create_shipping_zone_methods';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE shipping_zone_methods (
                id %s,
                zone_id %s NOT NULL,
                method_id %s NOT NULL,
                method_order %s NOT NULL DEFAULT 0,
                is_enabled %s NOT NULL DEFAULT 1,
                settings %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_method_zone FOREIGN KEY (zone_id) REFERENCES shipping_zones (id) ON DELETE CASCADE
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 100),
            Sql::bigInt($connection),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::text($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_zone_methods_zone_id ON shipping_zone_methods (zone_id)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS shipping_zone_methods');
    }
}