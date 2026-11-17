<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProductCategories implements MigrationInterface
{
    public function id(): string
    {
        return '20261116120100_create_product_categories';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE product_categories (
                id %s,
                name %s NOT NULL,
                slug %s NOT NULL,
                parent_id %s NOT NULL DEFAULT 0,
                description %s NULL,
                display %s NOT NULL DEFAULT \'default\',
                image_url %s NULL,
                menu_order %s NOT NULL DEFAULT 0,
                count %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL
            )',
            Sql::pk($connection),
            Sql::varchar($connection, 200),
            Sql::varchar($connection, 200),
            Sql::bigInt($connection),
            Sql::text($connection),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 2048),
            'INT',
            Sql::bigInt($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE UNIQUE INDEX idx_product_categories_slug ON product_categories (slug)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE product_categories');
    }
}
