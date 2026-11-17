<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateSettings implements MigrationInterface
{
    public function id(): string
    {
        return '20261117120000_create_settings';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE setting_groups (
                id %s NOT NULL,
                label %s NOT NULL,
                description %s NOT NULL DEFAULT \'\',
                parent_id %s NULL,
                sort %s NOT NULL DEFAULT 0
            )',
            Sql::varchar($connection, 64),
            Sql::varchar($connection, 191),
            Sql::text($connection),
            Sql::varchar($connection, 64),
            Sql::bigInt($connection),
        ));

        $connection->execute('CREATE TABLE settings (
            group_id VARCHAR(64) NOT NULL,
            id VARCHAR(64) NOT NULL,
            label VARCHAR(191) NOT NULL,
            description TEXT NOT NULL DEFAULT \'\',
            type VARCHAR(32) NOT NULL DEFAULT \'text\',
            value TEXT NULL,
            default_value TEXT NULL,
            tip TEXT NULL,
            placeholder TEXT NULL,
            options TEXT NULL,
            sort INTEGER NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, id),
            CONSTRAINT fk_setting_group FOREIGN KEY (group_id) REFERENCES setting_groups (id) ON DELETE CASCADE
        )');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS settings');
        $connection->execute('DROP TABLE IF EXISTS setting_groups');
    }
}
