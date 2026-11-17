<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateCoupons implements MigrationInterface
{
    public function id(): string
    {
        return '20261116170002_create_coupons';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE coupons (
                id %s,
                code %s NOT NULL,
                amount %s NOT NULL DEFAULT \'0.00\',
                date_created %s NOT NULL,
                date_created_gmt %s NOT NULL,
                date_modified %s NOT NULL,
                date_modified_gmt %s NOT NULL,
                discount_type %s NOT NULL DEFAULT \'fixed_cart\',
                description %s NULL,
                date_expires %s NULL,
                date_expires_gmt %s NULL,
                usage_count %s NOT NULL DEFAULT 0,
                individual_use %s NOT NULL DEFAULT 0,
                product_ids %s NULL,
                excluded_product_ids %s NULL,
                usage_limit %s NULL,
                usage_limit_per_user %s NULL,
                limit_usage_to_x_items %s NULL,
                free_shipping %s NOT NULL DEFAULT 0,
                product_categories %s NULL,
                excluded_product_categories %s NULL,
                exclude_sale_items %s NOT NULL DEFAULT 0,
                minimum_amount %s NOT NULL DEFAULT \'0.00\',
                maximum_amount %s NOT NULL DEFAULT \'0.00\',
                email_restrictions %s NULL,
                used_by %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                UNIQUE (code)
            )',
            // 29 columns total
            Sql::pk($connection),                    // 1. id
            Sql::varchar($connection, 100),          // 2. code
            Sql::decimal($connection),               // 3. amount
            Sql::timestamp($connection),             // 4. date_created
            Sql::timestamp($connection),             // 5. date_created_gmt
            Sql::timestamp($connection),             // 6. date_modified
            Sql::timestamp($connection),             // 7. date_modified_gmt
            Sql::varchar($connection, 20),           // 8. discount_type
            Sql::text($connection),                  // 9. description
            Sql::timestamp($connection),             // 10. date_expires
            Sql::timestamp($connection),             // 11. date_expires_gmt
            Sql::bigInt($connection),                // 12. usage_count
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',  // 13. individual_use
            Sql::text($connection),                  // 14. product_ids
            Sql::text($connection),                  // 15. excluded_product_ids
            Sql::bigInt($connection),                // 16. usage_limit
            Sql::bigInt($connection),                // 17. usage_limit_per_user
            Sql::bigInt($connection),                // 18. limit_usage_to_x_items
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',  // 19. free_shipping
            Sql::text($connection),                  // 20. product_categories
            Sql::text($connection),                  // 21. excluded_product_categories
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',  // 22. exclude_sale_items
            Sql::decimal($connection),               // 23. minimum_amount
            Sql::decimal($connection),               // 24. maximum_amount
            Sql::text($connection),                  // 25. email_restrictions
            Sql::text($connection),                  // 26. used_by
            Sql::timestamp($connection),             // 27. created_at
            Sql::timestamp($connection),             // 28. updated_at
        ));

        $connection->execute('CREATE INDEX idx_coupons_code ON coupons (code)');
        $connection->execute('CREATE INDEX idx_coupons_date_expires ON coupons (date_expires)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS coupons');
    }
}