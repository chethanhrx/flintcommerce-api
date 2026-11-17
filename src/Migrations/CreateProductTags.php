<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProductTags implements MigrationInterface
{
    public function id(): string
    {
        return '20261116150000_create_product_tags';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE product_tags (
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

        $connection->execute('CREATE INDEX idx_product_tags_slug ON product_tags (slug)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS product_tags');
    }
}