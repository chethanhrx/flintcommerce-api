<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateTaxRates implements MigrationInterface
{
    public function id(): string
    {
        return '20261116170004_create_tax_rates';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE tax_rates (
                id %s,
                tax_class_id %s NOT NULL DEFAULT 0,
                country %s NOT NULL,
                state %s NULL,
                postcode %s NULL,
                city %s NULL,
                rate %s NOT NULL DEFAULT \'0.0000\',
                name %s NULL,
                priority %s NOT NULL DEFAULT 1,
                compound %s NOT NULL DEFAULT 0,
                shipping %s NOT NULL DEFAULT 0,
                order_ %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 2),
            Sql::varchar($connection, 100),
            Sql::varchar($connection, 20),
            Sql::varchar($connection, 100),
            Sql::decimal($connection),
            Sql::varchar($connection, 255),
            Sql::bigInt($connection),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::bigInt($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_tax_rates_tax_class_id ON tax_rates (tax_class_id)');
        $connection->execute('CREATE INDEX idx_tax_rates_country ON tax_rates (country)');
        $connection->execute('CREATE INDEX idx_tax_rates_state ON tax_rates (state)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS tax_rates');
    }
}