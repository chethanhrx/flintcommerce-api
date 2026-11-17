<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateProductReviews implements MigrationInterface
{
    public function id(): string
    {
        return '20261116150001_create_product_reviews';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE product_reviews (
                id %s,
                product_id %s NOT NULL,
                reviewer_name %s NOT NULL,
                reviewer_email %s NOT NULL,
                reviewer_email_md5 %s NOT NULL,
                status %s NOT NULL DEFAULT \'approved\',
                rating %s NOT NULL DEFAULT 5,
                review %s NOT NULL,
                verified %s NOT NULL DEFAULT 0,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            )',
            Sql::pk($connection),
            Sql::bigInt($connection),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 255),
            Sql::varchar($connection, 32),
            Sql::varchar($connection, 20),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::text($connection),
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',
            Sql::timestamp($connection),
            Sql::timestamp($connection),
        ));

        $connection->execute('CREATE INDEX idx_product_reviews_product_id ON product_reviews (product_id)');
        $connection->execute('CREATE INDEX idx_product_reviews_status ON product_reviews (status)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS product_reviews');
    }
}