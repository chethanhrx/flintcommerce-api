<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateWebhookDeliveries implements MigrationInterface
{
    public function id(): string
    {
        return '20261117130001_create_webhook_deliveries';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE webhook_deliveries (
                id %s,
                webhook_id %s NOT NULL,
                request_method %s NOT NULL DEFAULT \'POST\',
                request_url %s NOT NULL,
                request_headers %s NULL,
                request_body %s NULL,
                response_code %s NULL,
                response_headers %s NULL,
                response_body %s NULL,
                duration_ms %s NULL,
                status %s NOT NULL DEFAULT \'pending\',
                error_message %s NULL,
                attempts %s NOT NULL DEFAULT 1,
                last_attempt_at %s NULL,
                date_created %s NOT NULL,
                date_created_gmt %s NOT NULL,
                CONSTRAINT fk_delivery_webhook FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE,
                CONSTRAINT chk_delivery_status CHECK (status IN (\'pending\', \'delivered\', \'failed\', \'retrying\'))
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 10),
            Sql::varchar($connection, 500),
            Sql::text($connection),
            Sql::text($connection),
            Sql::varchar($connection, 3),
            Sql::text($connection),
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 16),
            Sql::text($connection),
            Sql::bigInt($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_webhook_deliveries_webhook_id ON webhook_deliveries (webhook_id)');
        $connection->execute('CREATE INDEX idx_webhook_deliveries_status ON webhook_deliveries (status)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS webhook_deliveries');
    }
}