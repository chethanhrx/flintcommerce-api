<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Support\Sql;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\Migration\MigrationInterface;

final class CreateOrders implements MigrationInterface
{
    public function id(): string
    {
        return '20261116170000_create_orders';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(sprintf(
            'CREATE TABLE orders (
                id %s,
                parent_id %s NOT NULL DEFAULT 0,
                status %s NOT NULL DEFAULT \'pending\',
                currency %s NOT NULL DEFAULT \'USD\',
                version %s NOT NULL DEFAULT \'fc/v3\',
                prices_include_tax %s NOT NULL DEFAULT 0,
                date_created %s NOT NULL,
                date_created_gmt %s NOT NULL,
                date_modified %s NOT NULL,
                date_modified_gmt %s NOT NULL,
                discount_total %s NOT NULL DEFAULT \'0.00\',
                discount_tax %s NOT NULL DEFAULT \'0.00\',
                shipping_total %s NOT NULL DEFAULT \'0.00\',
                shipping_tax %s NOT NULL DEFAULT \'0.00\',
                cart_tax %s NOT NULL DEFAULT \'0.00\',
                total %s NOT NULL DEFAULT \'0.00\',
                total_tax %s NOT NULL DEFAULT \'0.00\',
                customer_id %s NOT NULL DEFAULT 0,
                order_key %s NULL,
                billing_first_name %s NULL,
                billing_last_name %s NULL,
                billing_company %s NULL,
                billing_address_1 %s NULL,
                billing_address_2 %s NULL,
                billing_city %s NULL,
                billing_state %s NULL,
                billing_postcode %s NULL,
                billing_country %s NULL,
                billing_email %s NULL,
                billing_phone %s NULL,
                shipping_first_name %s NULL,
                shipping_last_name %s NULL,
                shipping_company %s NULL,
                shipping_address_1 %s NULL,
                shipping_address_2 %s NULL,
                shipping_city %s NULL,
                shipping_state %s NULL,
                shipping_postcode %s NULL,
                shipping_country %s NULL,
                shipping_phone %s NULL,
                payment_method %s NULL,
                payment_method_title %s NULL,
                transaction_id %s NULL,
                customer_ip_address %s NULL,
                customer_user_agent %s NULL,
                created_via %s NULL,
                completed_at %s NULL,
                completed_at_gmt %s NULL,
                paid_at %s NULL,
                paid_at_gmt %s NULL,
                coupon_codes %s NULL,
                created_at %s NOT NULL,
                updated_at %s NOT NULL,
                CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
            )',
            // 43 columns total
            Sql::pk($connection),                    // 1. id
            Sql::bigInt($connection),                // 2. parent_id
            Sql::varchar($connection, 20),           // 3. status
            Sql::varchar($connection, 3),            // 4. currency
            Sql::varchar($connection, 10),           // 5. version
            Sql::driver($connection) === 'pgsql' ? 'SMALLINT' : 'TINYINT',  // 6. prices_include_tax
            Sql::timestamp($connection),             // 7. date_created
            Sql::timestamp($connection),             // 8. date_created_gmt
            Sql::timestamp($connection),             // 8. date_modified
            Sql::timestamp($connection),             // 9. date_modified_gmt
            Sql::decimal($connection),               // 10. discount_total
            Sql::decimal($connection),               // 11. discount_tax
            Sql::decimal($connection),               // 12. shipping_total
            Sql::decimal($connection),               // 13. shipping_tax
            Sql::decimal($connection),               // 14. cart_tax
            Sql::decimal($connection),               // 15. total
            Sql::decimal($connection),               // 16. total_tax
            Sql::bigInt($connection),                // 17. customer_id
            Sql::varchar($connection, 255),          // 18. order_key
            Sql::varchar($connection, 255),          // 19. billing_first_name
            Sql::varchar($connection, 255),          // 20. billing_last_name
            Sql::varchar($connection, 255),          // 21. billing_company
            Sql::varchar($connection, 255),          // 22. billing_address_1
            Sql::varchar($connection, 255),          // 23. billing_address_2
            Sql::varchar($connection, 100),          // 24. billing_city
            Sql::varchar($connection, 2),            // 25. billing_state
            Sql::varchar($connection, 255),          // 26. billing_postcode
            Sql::varchar($connection, 20),           // 27. billing_country
            Sql::varchar($connection, 255),          // 28. billing_email
            Sql::varchar($connection, 20),           // 29. billing_phone
            Sql::varchar($connection, 255),          // 30. shipping_first_name
            Sql::varchar($connection, 255),          // 31. shipping_last_name
            Sql::varchar($connection, 255),          // 32. shipping_company
            Sql::varchar($connection, 255),          // 33. shipping_address_1
            Sql::varchar($connection, 255),          // 34. shipping_address_2
            Sql::varchar($connection, 255),          // 35. shipping_city
            Sql::varchar($connection, 255),          // 36. shipping_state
            Sql::varchar($connection, 255),          // 37. shipping_postcode
            Sql::varchar($connection, 100),          // 38. shipping_country
            Sql::varchar($connection, 20),           // 39. shipping_phone
            Sql::text($connection),                  // 40. payment_method
            Sql::varchar($connection, 255),          // 41. payment_method_title
            Sql::varchar($connection, 255),          // 42. transaction_id
            Sql::varchar($connection, 255),          // 43. customer_ip_address
            Sql::varchar($connection, 255),          // 44. customer_user_agent
            Sql::varchar($connection, 255),          // 45. created_via
            Sql::timestamp($connection),             // 46. completed_at
            Sql::timestamp($connection),             // 47. completed_at_gmt
            Sql::timestamp($connection),             // 48. paid_at
            Sql::timestamp($connection),             // 49. paid_at_gmt
            Sql::text($connection),                  // 49. coupon_codes
            Sql::timestamp($connection),             // 50. created_at
            Sql::timestamp($connection),             // 51. updated_at
        ));
        // Total: 51 placeholders, 51 arguments

        $connection->execute('CREATE INDEX idx_orders_customer_id ON orders (customer_id)');
        $connection->execute('CREATE INDEX idx_orders_status ON orders (status)');
        $connection->execute('CREATE INDEX idx_orders_date_created ON orders (date_created)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS orders');
    }
}