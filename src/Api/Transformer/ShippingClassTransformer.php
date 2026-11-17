<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ShippingClass;

final class ShippingClassTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(ShippingClass $sc): array
    {
        $scUrl = $this->baseUrl . "/api/fc/v3/products/shipping_classes/{$sc->id}";

        return [
            'id' => (int) $sc->id,
            'name' => (string) $sc->name,
            'slug' => (string) $sc->slug,
            'description' => (string) $sc->description,
            'count' => (int) $sc->count,
            '_links' => [
                'self' => [
                    [
                        'href' => $scUrl,
                    ],
                ],
                'collection' => [
                    [
                        'href' => $this->baseUrl . '/api/fc/v3/products/shipping_classes',
                    ],
                ],
            ],
        ];
    }
}