<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProducts implements MigrationInterface
{
    public function id(): string
    {
        return '20261116120200_create_products';
    }

    public function up(ConnectionInterface $connection): void
    {
        $driver = Sql::driver($connection);
        $isPgsql = $driver === 'pgsql';
        $boolType = $isPgsql ? 'SMALLINT' : 'TINYINT';

        $pk = Sql::pk($connection);
        $varchar500 = Sql::varchar($connection, 500);
        $varchar20 = Sql::varchar($connection, 20);
        $varchar100 = Sql::varchar($connection, 100);
        $varchar2048 = Sql::varchar($connection, 2048);
        $varchar200 = Sql::varchar($connection, 200);
        $text = Sql::text($connection);
        $decimal = Sql::decimal($connection);
        $timestamp = Sql::timestamp($connection);
        $bigInt = Sql::bigInt($connection);

        $sql = "CREATE TABLE products (
            id $pk,
            name $varchar500 NOT NULL,
            slug $varchar500 NOT NULL,
            type $varchar20 NOT NULL DEFAULT 'simple',
            status $varchar20 NOT NULL DEFAULT 'publish',
            featured $boolType NOT NULL DEFAULT 0,
            catalog_visibility $varchar20 NOT NULL DEFAULT 'visible',
            description $text NULL,
            short_description $text NULL,
            sku $varchar100 NULL,
            price $decimal NULL,
            regular_price $decimal NULL,
            sale_price $decimal NULL,
            date_on_sale_from $timestamp NULL,
            date_on_sale_to $timestamp NULL,
            total_sales INT NOT NULL DEFAULT 0,
            virtual $boolType NOT NULL DEFAULT 0,
            downloadable $boolType NOT NULL DEFAULT 0,
            download_limit INT NOT NULL DEFAULT -1,
            download_expiry INT NOT NULL DEFAULT -1,
            external_url $varchar2048 NULL,
            button_text $varchar100 NULL,
            tax_status $varchar20 NOT NULL DEFAULT 'taxable',
            tax_class $varchar100 NOT NULL DEFAULT '',
            manage_stock $boolType NOT NULL DEFAULT 0,
            stock_quantity INT NULL,
            stock_status $varchar20 NOT NULL DEFAULT 'instock',
            backorders $varchar20 NOT NULL DEFAULT 'no',
            low_stock_amount INT NULL,
            sold_individually $boolType NOT NULL DEFAULT 0,
            weight $text NULL,
            length $text NULL,
            width $text NULL,
            height $text NULL,
            shipping_class $varchar200 NOT NULL DEFAULT '',
            shipping_class_id $bigInt NOT NULL DEFAULT 0,
            reviews_allowed $boolType NOT NULL DEFAULT 1,
            average_rating $decimal NOT NULL DEFAULT 0,
            rating_count $bigInt NOT NULL DEFAULT 0,
            parent_id $bigInt NOT NULL DEFAULT 0,
            purchase_note $text NULL,
            menu_order $bigInt NOT NULL DEFAULT 0,
            created_at $timestamp NOT NULL,
            updated_at $timestamp NOT NULL
        )";

        $connection->execute($sql);

        $connection->execute('CREATE UNIQUE INDEX idx_products_slug ON products (slug)');
        $connection->execute('CREATE INDEX idx_products_status ON products (status)');
        $connection->execute('CREATE INDEX idx_products_sku ON products (sku)');
        $connection->execute('CREATE INDEX idx_products_created_at ON products (created_at)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE products');
    }
}