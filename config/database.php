<?php

declare(strict_types=1);

/**
 * Database connection config (namespaced as `database.*` — consumed by DatabaseBootstrapper).
 */

use FlintPHP\Framework\Config\Env;

$env = Env::load(dirname(__DIR__) . '/.env');

$driver = $env['DB_DRIVER'] ?? 'sqlite';

$driverConfig = [
    'driver' => $driver,
];

if ($driver === 'sqlite') {
    $database = $env['DB_DATABASE'] ?? dirname(__DIR__) . '/storage/app.sqlite';

    // Resolve relative sqlite paths against the project root so workers,
    // CLI and the web server agree on the same file regardless of cwd.
    if (!str_starts_with($database, '/') && !str_starts_with($database, ':memory:')) {
        $database = dirname(__DIR__) . '/' . ltrim($database, '/');
    }

    $driverConfig['database'] = $database;
}

if ($driver === 'mysql' || $driver === 'pgsql') {
    $driverConfig['host'] = $env['DB_HOST'] ?? '127.0.0.1';
    $driverConfig['port'] = (int) ($env['DB_PORT'] ?? '3306');
    $driverConfig['database'] = $env['DB_DATABASE'] ?? 'woocommerce';
    $driverConfig['username'] = $env['DB_USERNAME'] ?? 'root';
    $driverConfig['password'] = $env['DB_PASSWORD'] ?? '';
    if ($driver === 'mysql') {
        $driverConfig['charset'] = 'utf8mb4';
    }
}

return $driverConfig;
