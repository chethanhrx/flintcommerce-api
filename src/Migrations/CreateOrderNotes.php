<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateOrderNotes implements MigrationInterface
{
    public function id(): string
    {
        return '20261116180000_create_order_notes';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE order_notes (
                id %s,
                order_id %s NOT NULL,
                note %s NOT NULL,
                customer_note %s NOT NULL DEFAULT 0,
                added_by_user_id %s NULL,
                date_created %s NOT NULL,
                date_created_gmt %s NOT NULL,
                CONSTRAINT fk_note_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::text($connection),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::bigInt($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_order_notes_order_id ON order_notes (order_id)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS order_notes');
    }
}