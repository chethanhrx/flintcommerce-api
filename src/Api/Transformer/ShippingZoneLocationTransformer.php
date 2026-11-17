<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ShippingZoneLocation;

final class ShippingZoneLocationTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    public function transform(\App\Domain\Model\ShippingZoneLocation $location): array
    {
        $locationUrl = $this->baseUrl . "/api/fc/v3/shipping/zones/{$location->zone_id}/locations/{$location->id}";

        return [
            'id' => (int) $location->id,
            'zone_id' => (int) $location->zone_id,
            'type' => (string) $location->type,
            'code' => (string) $location->code,
        ];
    }
}