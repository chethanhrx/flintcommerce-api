<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProductVariations implements MigrationInterface
{
    public function id(): string
    {
        return '20261116130000_create_product_variations';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE product_variations (
                id %s,
                product_id %s NOT NULL,
                status %s NOT NULL DEFAULT \'publish\',
                sku %s NULL,
                price %s NULL,
                regular_price %s NULL,
                sale_price %s NULL,
                date_on_sale_from %s NULL,
                date_on_sale_to %s NULL,
                virtual %s NOT NULL DEFAULT 0,
                downloadable %s NOT NULL DEFAULT 0,
                downloads %s NULL,
                download_limit %s NOT NULL DEFAULT -1,
                download_expiry %s NOT NULL DEFAULT -1,
                tax_status %s NOT NULL DEFAULT \'taxable\',
                tax_class %s NULL,
                manage_stock %s NOT NULL DEFAULT 0,
                stock_quantity %s NULL,
                stock_status %s NOT NULL DEFAULT \'instock\',
                backorders %s NOT NULL DEFAULT \'no\',
                backorders_allowed %s NOT NULL DEFAULT 0,
                backordered %s NOT NULL DEFAULT 0,
                low_stock_amount %s NULL,
                weight %s NULL,
                length %s NULL,
                width %s NULL,
                height %s NULL,
                shipping_class %s NULL,
                shipping_class_id %s NOT NULL DEFAULT 0,
                image %s NULL,
                attributes %s NULL,
                menu_order %s NOT NULL DEFAULT 0,
                meta_data %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_variation_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            )',
Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 100),
            Sql::decimal($connection),
            Sql::decimal($connection),
            Sql::decimal($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 200),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::bigInt($connection),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 20),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::bigInt($connection),
            Sql::text($connection),
            Sql::text($connection),
            Sql::text($connection),
            Sql::text($connection),
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::text($connection),
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::text($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_product_variations_product_id ON product_variations (product_id)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS product_variations');
    }
}