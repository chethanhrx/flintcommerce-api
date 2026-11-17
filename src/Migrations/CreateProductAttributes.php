<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProductAttributes implements MigrationInterface
{
    public function id(): string
    {
        return '20261116140000_create_product_attributes';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE product_attributes (
                id %s,
                name %s NOT NULL,
                slug %s NOT NULL,
                type %s NOT NULL DEFAULT \'select\',
                order_by %s NOT NULL DEFAULT \'menu_order\',
                has_archives %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                UNIQUE (slug)
            )',
            Sql::pk($connection),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 20),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_product_attributes_slug ON product_attributes (slug)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS product_attributes');
    }
}