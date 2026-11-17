<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateOrderRefunds implements MigrationInterface
{
    public function id(): string
    {
        return '20261116180001_create_order_refunds';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE order_refunds (
                id %s,
                order_id %s NOT NULL,
                amount %s NOT NULL DEFAULT \'0.00\',
                reason %s NULL,
                status %s NOT NULL DEFAULT \'pending\',
                refunded_by %s NULL,
                refunded_via %s NULL,
                date_created %s NOT NULL,
                date_created_gmt %s NOT NULL,
                CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::decimal($connection),
            Sql::text($connection),
            Sql::varchar($connection, 20),
            Sql::bigInt($connection),
            Sql::varchar($connection, 50),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_order_refunds_order_id ON order_refunds (order_id)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS order_refunds');
    }
}