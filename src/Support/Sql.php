<?php

declare(strict_types=1);

namespace App\Support;

use FlintPHP\Framework\Database\ConnectionInterface;

/**
 * Driver-aware DDL helpers so one migration works on SQLite, MySQL and PostgreSQL.
 */
final class Sql
{
    public static function driver(ConnectionInterface $connection): string
    {
        return (string) $connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** INTEGER PRIMARY KEY that auto-increments on every engine. */
    public static function pk(ConnectionInterface $connection): string
    {
        return match (self::driver($connection)) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'pgsql' => 'SERIAL PRIMARY KEY',
            default => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)',
        };
    }

    public static function text(ConnectionInterface $connection): string
    {
        return match (self::driver($connection)) {
            'sqlite' => 'TEXT',
            'pgsql' => 'TEXT',
            default => 'TEXT',
        };
    }

    public static function varchar(ConnectionInterface $connection, int $len): string
    {
        return match (self::driver($connection)) {
            'pgsql' => sprintf('VARCHAR(%d)', $len),
            default => sprintf('VARCHAR(%d)', $len),
        };
    }

    public static function decimal(ConnectionInterface $connection): string
    {
        return match (self::driver($connection)) {
            'sqlite' => 'NUMERIC',
            'pgsql' => 'NUMERIC(19,4)',
            default => 'DECIMAL(19,4)',
        };
    }

    public static function timestamp(ConnectionInterface $connection): string
    {
        return match (self::driver($connection)) {
            'sqlite' => 'TEXT',
            'pgsql' => 'TIMESTAMP',
            default => 'DATETIME',
        };
    }

    public static function bigInt(ConnectionInterface $connection): string
    {
        return match (self::driver($connection)) {
            'pgsql' => 'BIGINT',
            default => 'BIGINT',
        };
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
