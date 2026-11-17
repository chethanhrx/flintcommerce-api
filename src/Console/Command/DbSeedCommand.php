<?php

declare(strict_types=1);

namespace App\Console\Command;

use FlintPHP\Framework\Console\CommandInterface;
use FlintPHP\Framework\Console\InputInterface;
use FlintPHP\Framework\Console\OutputInterface;
use FlintPHP\Framework\Database\ConnectionInterface;

/**
 * db:seed — dev fixture data (categories + products).
 */
final class DbSeedCommand implements CommandInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function name(): string
    {
        return 'db:seed';
    }

    public function description(): string
    {
        return 'Insert development sample data (categories + products)';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $existing = (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM products');

        if ($existing > 0) {
            $output->writeLn('Seed skipped: products table is not empty.');

            return 0;
        }

        $categories = [
            ['Appetizers', 'appetizers', 0, 0],
            ['Main Courses', 'main-courses', 0, 1],
        ];

        $categoryIds = [];

        foreach ($categories as [$name, $slug, $parent, $menuOrder]) {
            $this->connection->execute(
                'INSERT INTO product_categories (name, slug, parent_id, description, display, menu_order, created_at, updated_at)
                 VALUES (?, ?, ?, \'\', \'default\', ?, ?, ?)',
                [$name, $slug, $parent, $menuOrder, $now, $now],
            );
            $categoryIds[] = (int) $this->connection->fetchColumn(
                'SELECT id FROM product_categories WHERE slug = ?',
                [$slug],
            );
        }

        $products = [
            ['Cheese and garlic potato skins', 'cheese-and-garlic-potato-skins', 'potato-skins-401', '8.95', '8.95', '', 25],
            ['Deep fried spicy squid', 'deep-fried-spicy-squid', 'squid-101', '12.50', '15.00', '10.95', 8],
            ['Grilled sea bass', 'grilled-sea-bass', 'sea-bass-102', '18.20', '18.20', '', 3],
            ['Thai basil chicken', 'thai-basil-chicken', 'thai-chicken-103', '11.00', '11.00', '', 0],
        ];

        foreach ($products as $i => [$name, $slug, $sku, $price, $regular, $sale, $stock]) {
            $this->connection->execute(
                'INSERT INTO products (
                    name, slug, type, status, sku, price, regular_price, sale_price,
                    manage_stock, stock_quantity, stock_status, menu_order, created_at, updated_at
                ) VALUES (?, ?, \'simple\', \'publish\', ?, ?, ?, ?, 1, ?, \'instock\', ?, ?, ?)',
                [$name, $slug, $sku, $price, $regular, $sale !== '' ? $sale : null, $stock, $i, $now, $now],
            );

            $productId = (int) $this->connection->fetchColumn('SELECT id FROM products WHERE slug = ?', [$slug]);
            $categoryId = $categoryIds[$i % 2];

            $this->connection->execute(
                'INSERT INTO product_category_relationships (product_id, category_id) VALUES (?, ?)',
                [$productId, $categoryId],
            );
        }

        $output->writeLn('Seeded 2 categories and 4 products.');

        return 0;
    }
}
