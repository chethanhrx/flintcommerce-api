<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Domain\Model\ShippingZone;
use App\Domain\Model\ShippingZoneLocation;
use App\Domain\Model\ShippingZoneMethod;
use App\Domain\Repository\ShippingZoneRepository;
use App\Domain\Repository\ShippingZoneLocationRepository;
use App\Domain\Repository\ShippingZoneMethodRepository;
use App\Support\BcMath;

final class ShippingCalculator
{
    public function __construct(
        private readonly ShippingZoneRepository $zones,
        private readonly ShippingZoneLocationRepository $locations,
        private readonly ShippingZoneMethodRepository $methods,
        private readonly int $dp = 2,
    ) {
    }

    /**
     * Calculate shipping cost for an order.
     *
     * @param array<int, array<string, mixed>> $lineItems
     * @param array<string, mixed> $shippingAddress
     * @param float $orderSubtotal
     * @return string
     */
    public function calculate(array $lineItems, array $shippingAddress, float $orderSubtotal): string
    {
        $zone = $this->findMatchingZone($shippingAddress);
        if ($zone === null) {
            return '0.00';
        }

        $zoneMethods = $this->methods->list($zone->id, [], 100, 0);

        $total = '0.00';
        foreach ($zoneMethods as $method) {
            if (!$method->is_enabled) continue;

            $settings = [];
            if ($method->settings !== null && $method->settings !== '') {
                $decoded = json_decode($method->settings, true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }

            $cost = $this->calculateMethodCost($method, $settings, $lineItems, $shippingAddress, $orderSubtotal);

            if ($cost !== null) {
                $total = BcMath::add($total, $cost, $this->dp);
            }
        }

        return $total;
    }

    /**
     * Get available shipping methods for checkout display.
     *
     * @return array<int, array{id: int, title: string, cost: string, method_id: string}>
     */
    public function getAvailableMethods(array $lineItems, array $shippingAddress, float $orderSubtotal): array
    {
        $zone = $this->findMatchingZone($shippingAddress);
        if ($zone === null) {
            return [];
        }

        $zoneMethods = $this->methods->list($zone->id, [], 100, 0);
        $methods = [];

        foreach ($zoneMethods as $method) {
            if (!$method->is_enabled) continue;

            $settings = [];
            if ($method->settings !== null && $method->settings !== '') {
                $decoded = json_decode($method->settings, true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }

            $cost = $this->calculateMethodCost($method, $settings, $lineItems, $shippingAddress, $orderSubtotal);

            if ($cost !== null) {
                $methods[] = [
                    'id' => $method->id,
                    'title' => $method->method_id,
                    'cost' => $cost,
                    'method_id' => $method->method_id,
                ];
            }
        }

        usort($methods, fn (array $a, array $b): int => (float) $a['cost'] <=> (float) $b['cost']);

        return $methods;
    }

    private function findMatchingZone(array $address): ?ShippingZone
    {
        $country = $address['country'] ?? '';
        $state = $address['state'] ?? '';
        $postcode = $address['postcode'] ?? '';
        $city = $address['city'] ?? '';

        $zones = $this->zones->list([], 100, 0);

        $matched = [];
        foreach ($zones as $zone) {
            $zoneLocations = $this->locations->list($zone->id, [], 100, 0);
            if ($this->zoneMatches($zoneLocations, $country, $state, $postcode, $city)) {
                $matched[] = $zone;
            }
        }

        if ($matched === []) {
            return null;
        }

        usort($matched, fn (ShippingZone $a, ShippingZone $b): int => $a->order_ <=> $b->order_);

        return $matched[0];
    }

    private function zoneMatches(array $locations, string $country, string $state, string $postcode, string $city): bool
    {
        if ($locations === []) {
            return true; // No restrictions = rest of world
        }

        foreach ($locations as $location) {
            $type = $location->type;
            $code = $location->code;

            if ($type === 'country' && strcasecmp($code, $country) === 0) {
                return true;
            }
            if ($type === 'state' && strcasecmp($code, $state) === 0) {
                return true;
            }
            if ($type === 'postcode' && $this->matchPostcode($postcode, $code)) {
                return true;
            }
            if ($type === 'city' && strcasecmp($code, $city) === 0) {
                return true;
            }
        }

        return false;
    }

    private function matchPostcode(string $postcode, string $pattern): bool
    {
        if ($pattern === '*') return true;
        if (str_contains($pattern, '...')) {
            [$from, $to] = explode('...', $pattern);
            return $postcode >= $from && $postcode <= $to;
        }
        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
            return (bool) preg_match($regex, $postcode);
        }
        return strcasecmp($postcode, $pattern) === 0;
    }

    private function calculateMethodCost(
        ShippingZoneMethod $method,
        array $settings,
        array $lineItems,
        array $shippingAddress,
        float $orderSubtotal
    ): ?string
    {
        $methodId = $method->method_id;

        switch ($methodId) {
            case 'flat_rate':
                return $this->calculateFlatRate($settings, $lineItems, $orderSubtotal);

            case 'free_shipping':
                return $this->calculateFreeShipping($settings, $orderSubtotal);

            case 'local_pickup':
                return '0.00';

            case 'weight_based':
                return $this->calculateWeightBased($settings, $lineItems);

            case 'dimension_based':
                return $this->calculateDimensionBased($settings, $lineItems);

            default:
                return $this->calculateFlatRate($settings, $lineItems, $orderSubtotal);
        }
    }

    private function calculateFlatRate(array $settings, array $lineItems, float $orderSubtotal): string
    {
        $cost = $settings['cost'] ?? '0.00';
        $type = $settings['type'] ?? 'order';

        if ($type === 'order') {
            return $cost;
        }

        if ($type === 'item') {
            $count = array_sum(array_map(fn ($item): int => (int) ($item['quantity'] ?? 1), $lineItems));
            return BcMath::mul($cost, (string) $count, 2);
        }

        return $cost;
    }

    private function calculateFreeShipping(array $settings, float $orderSubtotal): ?string
    {
        $minAmount = (float) ($settings['min_amount'] ?? 0);
        $requires = $settings['requires'] ?? 'min_amount';

        if ($requires === 'coupon' || $requires === 'both') {
            return null;
        }

        if ($orderSubtotal >= $minAmount) {
            return '0.00';
        }

        return null;
    }

    private function calculateWeightBased(array $settings, array $lineItems): string
    {
        $totalWeight = 0.0;
        foreach ($lineItems as $item) {
            $weight = (float) ($item['weight'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 1);
            $totalWeight += $weight * $qty;
        }

        $cost = '0.00';
        $rules = $settings['rules'] ?? [];

        foreach ($rules as $rule) {
            $maxWeight = (float) ($rule['max_weight'] ?? INF);
            $ruleCost = $rule['cost'] ?? '0.00';

            if ($totalWeight <= $maxWeight) {
                $cost = $ruleCost;
                break;
            }
        }

        return $cost;
    }

    private function calculateDimensionBased(array $settings, array $lineItems): string
    {
        return $settings['cost'] ?? '0.00';
    }
}