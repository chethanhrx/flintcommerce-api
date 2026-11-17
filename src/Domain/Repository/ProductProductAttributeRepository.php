<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ProductProductAttribute;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ProductProductAttributeRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $productId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM product_product_attributes WHERE product_id = ? ORDER BY position ASC',
            [$productId],
        );

        return array_map(fn (array $row): ProductProductAttribute => $this->hydrate($row), $rows);
    }

    public function find(int $productId, int $id): ?ProductProductAttribute
    {
        $row = $this->connection->fetch(
            'SELECT * FROM product_product_attributes WHERE product_id = ? AND id = ?',
            [$productId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function sync(int $productId, array $payload): void
    {
        $this->connection->transaction(function () use ($productId, $payload): void {
            $this->connection->execute(
                'DELETE FROM product_product_attributes WHERE product_id = ?',
                [$productId],
            );

            $position = 0;
            foreach ($payload as $attr) {
                $attr = is_array($attr) ? $attr : [];
                $attributeId = $attr['id'] ?? 0;

                if ($attributeId <= 0) {
                    continue;
                }

                $this->connection->execute(
                    'INSERT INTO product_product_attributes (
                        product_id, attribute_id, position, is_variation, is_visible, is_taxonomy, name, slug, options, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $productId,
                        $attributeId,
                        $position++,
                        (int) ($attr['is_variation'] ?? 0),
                        (int) ($attr['is_visible'] ?? 1),
                        (int) ($attr['is_taxonomy'] ?? 1),
                        $attr['name'] ?? null,
                        $attr['slug'] ?? null,
                        $attr['options'] ?? null,
                        gmdate('Y-m-d H:i:s'),
                        gmdate('Y-m-d H:i:s'),
                    ],
                );
            }
        });
    }

    /**


     * @param array<string, mixed> $row


     * @return ProductProductAttribute


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\ProductProductAttribute



     */



    private function hydrate(array $row): \App\Domain\Model\ProductProductAttribute
    {
        $ppa = new ProductProductAttribute();

        foreach ($row as $column => $value) {
            $ppa->{$column} = $value;
        }

        return $ppa;
    }
}