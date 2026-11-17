<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ShippingZone;

final class ShippingZoneTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    public function transform(\App\Domain\Model\ShippingZone $zone): array
    {
        $zoneUrl = $this->baseUrl . "/api/fc/v3/shipping/zones/{$zone->id}";

        return [
            'id' => (int) $zone->id,
            'name' => (string) $zone->name,
            'order' => (int) $zone->order_,
            '_links' => [
                'self' => [
                    [
                        'href' => $zoneUrl,
                    ],
                ],
                'collection' => [
                    [
                        'href' => $this->baseUrl . '/api/fc/v3/shipping/zones',
                    ],
                ],
                'locations' => [
                    [
                        'href' => $this->baseUrl . "/api/fc/v3/shipping/zones/{$zone->id}/locations",
                    ],
                ],
                'methods' => [
                    [
                        'href' => $this->baseUrl . "/api/fc/v3/shipping/zones/{$zone->id}/methods",
                    ],
                ],
            ],
        ];
    }
}