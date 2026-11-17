<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ProductTag;

final class ProductTagTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(ProductTag $tag): array
    {
        $tagUrl = $this->baseUrl . "/api/fc/v3/products/tags/{$tag->id}";

        return [
            'id' => (int) $tag->id,
            'name' => (string) $tag->name,
            'slug' => (string) $tag->slug,
            'description' => (string) $tag->description,
            'count' => (int) $tag->count,
            '_links' => [
                'self' => [
                    [
                        'href' => $tagUrl,
                    ],
                ],
                'collection' => [
                    [
                        'href' => $this->baseUrl . '/api/fc/v3/products/tags',
                    ],
                ],
            ],
        ];
    }
}