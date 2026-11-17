<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ProductAttributeTerm;

final class ProductAttributeTermTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(ProductAttributeTerm $term): array
    {
        $termUrl = $this->baseUrl . "/api/fc/v3/products/attributes/{$term->attribute_id}/terms/{$term->id}";

        return [
            'id' => (int) $term->id,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'menu_order' => (int) $term->menu_order,
            'description' => (string) $term->description,
            'count' => (int) $term->count,
            '_links' => [
                'self' => [
                    [
                        'href' => $termUrl,
                    ],
                ],
                'collection' => [
                    [
                        'href' => $this->baseUrl . "/api/fc/v3/products/attributes/{$term->attribute_id}/terms",
                    ],
                ],
            ],
        ];
    }
}