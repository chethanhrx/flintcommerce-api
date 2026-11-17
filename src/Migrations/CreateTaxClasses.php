<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateTaxClasses implements MigrationInterface
{
    public function id(): string
    {
        return '20261116170003_create_tax_classes';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE tax_classes (
                id %s,
                name %s NOT NULL,
                slug %s NOT NULL,
                description %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                UNIQUE (slug)
            )',
            Sql::pk($connection),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::text($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_tax_classes_slug ON tax_classes (slug)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS tax_classes');
    }
}