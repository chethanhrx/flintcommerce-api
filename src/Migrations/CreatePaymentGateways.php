<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreatePaymentGateways implements MigrationInterface
{
    public function id(): string
    {
        return '20261116190003_create_payment_gateways';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE payment_gateways (
                id %s,
                id_key %s NOT NULL,
                title %s NOT NULL,
                description %s NULL,
                order_ %s NOT NULL DEFAULT 0,
                enabled %s NOT NULL DEFAULT 1,
                method_title %s NULL,
                method_description %s NULL,
                method_supports %s NULL,
                settings %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                UNIQUE (id_key)
            )',
            Sql::pk($connection),
            Sql::varchar($connection, 100),
            Sql::varchar($connection, 255),
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::varchar($connection, 255),
            Sql::text($connection),
            Sql::text($connection),
            Sql::text($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_payment_gateways_id_key ON payment_gateways (id_key)');
        $connection->execute('CREATE INDEX idx_payment_gateways_enabled ON payment_gateways (enabled)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS payment_gateways');
    }
}