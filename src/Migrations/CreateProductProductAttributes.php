<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProductProductAttributes implements MigrationInterface
{
    public function id(): string
    {
        return '20261116140002_create_product_product_attributes';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE product_product_attributes (
                id %s,
                product_id %s NOT NULL,
                attribute_id %s NOT NULL,
                position %s NOT NULL DEFAULT 0,
                is_variation %s NOT NULL DEFAULT 0,
                is_visible %s NOT NULL DEFAULT 1,
                is_taxonomy %s NOT NULL DEFAULT 1,
                name %s NULL,
                slug %s NULL,
                options %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_ppa_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
                CONSTRAINT fk_ppa_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes (id) ON DELETE CASCADE
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::bigInt($connection),
            Sql::bigInt($connection),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::text($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_ppa_product_id ON product_product_attributes (product_id)');
        $connection->execute('CREATE INDEX idx_ppa_attribute_id ON product_product_attributes (attribute_id)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS product_product_attributes');
    }
}