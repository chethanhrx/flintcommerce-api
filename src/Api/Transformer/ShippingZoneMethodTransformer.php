<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ShippingZoneMethod;

final class ShippingZoneMethodTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    public function transform(\App\Domain\Model\ShippingZoneMethod $method): array
    {
        $methodUrl = $this->baseUrl . "/api/fc/v3/shipping/zones/{$method->zone_id}/methods/{$method->id}";

        $settings = [];
        if ($method->settings !== null && $method->settings !== '') {
            $decoded = json_decode($method->settings, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        return [
            'id' => (int) $method->id,
            'method_id' => (string) $method->method_id,
            'method_order' => (int) $method->method_order,
            'is_enabled' => (bool) $method->is_enabled,
            'settings' => $settings,
        ];
    }
}