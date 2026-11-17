<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateShippingZoneLocations implements MigrationInterface
{
    public function id(): string
    {
        return '20261116190001_create_shipping_zone_locations';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE shipping_zone_locations (
                id %s,
                zone_id %s NOT NULL,
                type %s NOT NULL,
                code %s NOT NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_zone_location FOREIGN KEY (zone_id) REFERENCES shipping_zones (id) ON DELETE CASCADE,
                UNIQUE (zone_id, type, code)
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 100),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_zone_locations_zone_id ON shipping_zone_locations (zone_id)');
        $connection->execute('CREATE INDEX idx_zone_locations_type_code ON shipping_zone_locations (type, code)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS shipping_zone_locations');
    }
}