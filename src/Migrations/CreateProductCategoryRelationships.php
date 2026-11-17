<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProductCategoryRelationships implements MigrationInterface
{
    public function id(): string
    {
        return '20261116120300_create_product_category_relationships';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE product_category_relationships (
                product_id %s NOT NULL,
                category_id %s NOT NULL,
                PRIMARY KEY (product_id, category_id)
            )',
            Sql::bigInt($connection),
            Sql::bigInt($connection),
        ));
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE product_category_relationships');
    }
}
