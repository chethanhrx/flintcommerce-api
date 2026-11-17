<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ProductAttribute;

final class ProductAttributeTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(ProductAttribute $attribute): array
    {
        $attributeUrl = $this->baseUrl . "/api/fc/v3/products/attributes/{$attribute->id}";

        return [
            'id' => (int) $attribute->id,
            'name' => (string) $attribute->name,
            'slug' => (string) $attribute->slug,
            'type' => (string) $attribute->type,
            'order_by' => (string) $attribute->order_by,
            'has_archives' => (bool) $attribute->has_archives,
        ];
    }
}