<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateOrderItems implements MigrationInterface
{
    public function id(): string
    {
        return '20261116170001_create_order_items';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE order_items (
                id %s,
                order_id %s NOT NULL,
                name %s NOT NULL,
                product_id %s NOT NULL DEFAULT 0,
                variation_id %s NOT NULL DEFAULT 0,
                quantity %s NOT NULL DEFAULT 1,
                tax_class %s NULL,
                subtotal %s NOT NULL DEFAULT \'0.00\',
                subtotal_tax %s NOT NULL DEFAULT \'0.00\',
                total %s NOT NULL DEFAULT \'0.00\',
                total_tax %s NOT NULL DEFAULT \'0.00\',
                tax_class_id %s NOT NULL DEFAULT 0,
                tax_data %s NULL,
                meta_data %s NULL,
                sku %s NULL,
                price %s NOT NULL DEFAULT \'0.00\',
                image %s NULL,
                parent_item_id %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 255),
            Sql::bigInt($connection),
            Sql::bigInt($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 200),
            Sql::decimal($connection),
            Sql::decimal($connection),
            Sql::decimal($connection),
            Sql::decimal($connection),
            Sql::bigInt($connection),
            Sql::text($connection),
            Sql::text($connection),
            Sql::varchar($connection, 100),
            Sql::decimal($connection),
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_order_items_order_id ON order_items (order_id)');
        $connection->execute('CREATE INDEX idx_order_items_product_id ON order_items (product_id)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS order_items');
    }
}