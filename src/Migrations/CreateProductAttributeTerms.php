<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProductAttributeTerms implements MigrationInterface
{
    public function id(): string
    {
        return '20261116140001_create_product_attribute_terms';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE product_attribute_terms (
                id %s,
                attribute_id %s NOT NULL,
                name %s NOT NULL,
                slug %s NOT NULL,
                menu_order %s NOT NULL DEFAULT 0,
                description %s NULL,
                count %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_term_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes (id) ON DELETE CASCADE,
                UNIQUE (attribute_id, slug)
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::bigInt($connection),
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_product_attribute_terms_attribute_id ON product_attribute_terms (attribute_id)');
        $connection->execute('CREATE INDEX idx_product_attribute_terms_slug ON product_attribute_terms (slug)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS product_attribute_terms');
    }
}