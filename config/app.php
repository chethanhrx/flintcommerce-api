<?php

declare(strict_types=1);

/**
 * Application configuration (namespaced as `app.*`).
 *
 * Values come from the environment (via .env or real env vars).
 */

use FlintPHP\Framework\Config\Env;

$env = Env::load(dirname(__DIR__) . '/.env');

return [
    'name' => 'FlintCommerce API',
    'env' => $env['APP_ENV'] ?? 'production',
    'debug' => ($env['APP_DEBUG'] ?? 'false') === 'true',
    'url' => $env['APP_URL'] ?? 'http://localhost:8000',
    'timezone' => $env['APP_TIMEZONE'] ?? 'UTC',

    // WooCommerce-compatible money formatting.
    'wc' => [
        'dp' => (int) ($env['WC_PRICE_DECIMALS'] ?? '2'),
        'currency' => $env['WC_CURRENCY'] ?? 'USD',
    ],

    // Tax calculation configuration.
    'tax' => [
        'decimals' => (int) ($env['TAX_DECIMALS'] ?? '2'),
        'rounding_mode' => $env['TAX_ROUNDING_MODE'] ?? 'line',
        'prices_include_tax' => ($env['TAX_PRICES_INCLUDE_TAX'] ?? 'false') === 'true',
    ],

    // Webhook delivery worker configuration.
    'webhook' => [
        'max_attempts' => (int) ($env['WEBHOOK_MAX_ATTEMPTS'] ?? '5'),
        'base_delay_ms' => (int) ($env['WEBHOOK_BASE_DELAY_MS'] ?? '1000'),
        'max_delay_ms' => (int) ($env['WEBHOOK_MAX_DELAY_MS'] ?? '300000'),
        'timeout_ms' => (int) ($env['WEBHOOK_TIMEOUT_MS'] ?? '10000'),
    ],
];
