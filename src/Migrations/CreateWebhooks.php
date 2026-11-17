<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateWebhooks implements MigrationInterface
{
    public function id(): string
    {
        return '20261117130000_create_webhooks';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE webhooks (
                id %s,
                name %s NOT NULL,
                status %s NOT NULL DEFAULT \'active\',
                topic %s NOT NULL,
                delivery_url %s NOT NULL,
                secret %s NOT NULL DEFAULT \'\',
                fields %s NULL,
                date_created %s NOT NULL,
                date_created_gmt %s NOT NULL,
                date_modified %s NOT NULL,
                date_modified_gmt %s NOT NULL,
                CONSTRAINT chk_webhook_status CHECK (status IN (\'active\', \'paused\', \'disabled\'))
            )',
            Sql::pk($connection),
            Sql::varchar($connection, 191),
            Sql::varchar($connection, 16),
            Sql::varchar($connection, 191),
            Sql::varchar($connection, 500),
            Sql::varchar($connection, 191),
            Sql::text($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS webhooks');
    }
}