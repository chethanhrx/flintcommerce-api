<?php

declare(strict_types=1);

namespace App\Tax;

use App\Domain\Model\TaxRate;
use App\Domain\Repository\TaxRateRepository;
use App\Support\BcMath;

final class TaxCalculator
{
    public function __construct(
        private readonly TaxRateRepository $taxRates,
        private readonly int $dp = 2,
        private string $roundingMode = 'line',
        private bool $pricesIncludeTax = false,
    ) {
    }

    /**
     * Calculate tax for order line items.
     *
     * @param array<int, array<string, mixed>> $lineItems
     * @param array<string, mixed> $payload
     * @return array{cart_tax: string, total_tax: string, item_taxes: array<int, array{subtotal_tax: string, total_tax: string, rate_ids: array<int, int>}>}
     */
    public function calculate(array $lineItems, array $payload): array
    {
        $billing = $payload['billing'] ?? [];
        $shipping = $payload['shipping'] ?? [];

        $country = $billing['country'] ?? '';
        $state = $billing['state'] ?? '';
        $postcode = $billing['postcode'] ?? '';
        $city = $billing['city'] ?? '';

        // Determine which tax classes are needed based on line items
        $taxClassIds = [0]; // default/standard
        foreach ($lineItems as $item) {
            if (!empty($item['tax_class_id'])) {
                $taxClassIds[] = (int) $item['tax_class_id'];
            }
        }
        $taxClassIds = array_unique($taxClassIds);

        // Fetch all applicable rates for needed tax classes
        $allRates = [];
        foreach ($taxClassIds as $taxClassId) {
            $rates = $this->taxRates->list($taxClassId, [], 500, 0);
            $allRates[$taxClassId] = $rates;
        }

        $cartTax = '0.00';
        $itemTaxes = [];

        foreach ($lineItems as $index => $item) {
            $taxClassId = (int) ($item['tax_class_id'] ?? 0);
            $rates = $allRates[$taxClassId] ?? [];

            $subtotal = $item['subtotal'] ?? '0.00';
            $quantity = (int) ($item['quantity'] ?? 1);

            // If prices include tax, calculate base price first
            $taxableAmount = $this->pricesIncludeTax
                ? $this->extractTaxableAmount($subtotal, $rates, $country, $state, $postcode, $city)
                : $subtotal;

            $matchedRates = $this->matchRates($rates, $country, $state, $postcode, $city);
            $itemTax = '0.00';
            $appliedRateIds = [];

            foreach ($matchedRates as $rate) {
                $rateValue = BcMath::div($rate->rate, '100', 6);

                if ($rate->compound) {
                    // Compound tax: apply on subtotal + previously applied taxes
                    $base = BcMath::add($taxableAmount, $itemTax, $this->dp);
                    $taxAmount = BcMath::mul($base, $rateValue, $this->dp);
                } else {
                    // Simple tax: apply on taxable amount only
                    $taxAmount = BcMath::mul($taxableAmount, $rateValue, $this->dp);
                }

                $itemTax = BcMath::add($itemTax, $taxAmount, $this->dp);
                $appliedRateIds[] = $rate->id;
            }

            $roundedItemTax = $this->round($itemTax);

            if ($this->roundingMode === 'line') {
                $itemTax = $roundedItemTax;
            }

            $cartTax = BcMath::add($cartTax, $itemTax, $this->dp);

            $itemTaxes[] = [
                'subtotal_tax' => $roundedItemTax,
                'total_tax' => $roundedItemTax,
                'rate_ids' => $appliedRateIds,
            ];
        }

        if ($this->roundingMode === 'order') {
            $cartTax = $this->round($cartTax);
        }

        return [
            'cart_tax' => $cartTax,
            'total_tax' => $cartTax,
            'item_taxes' => $itemTaxes,
        ];
    }

    /**
     * Calculate shipping tax.
     */
    public function calculateShippingTax(float $shippingTotal, int $shippingTaxClassId, array $address): string
    {
        $rates = $this->taxRates->list($shippingTaxClassId, [], 500, 0);

        $country = $address['country'] ?? '';
        $state = $address['state'] ?? '';
        $postcode = $address['postcode'] ?? '';
        $city = $address['city'] ?? '';

        $matchedRates = $this->matchRates($rates, $country, $state, $postcode, $city);

        $tax = '0.00';
        $base = (string) $shippingTotal;

        foreach ($matchedRates as $rate) {
            $rateValue = BcMath::div($rate->rate, '100', 6);

            if ($rate->compound) {
                $base = BcMath::add($base, $tax, $this->dp);
            }

            $taxAmount = BcMath::mul($base, $rateValue, $this->dp);
            $tax = BcMath::add($tax, $taxAmount, $this->dp);
        }

        return $this->round($tax);
    }

    /**
     * Match tax rates against address.
     *
     * @return array<int, TaxRate>
     */
    private function matchRates(array $rates, string $country, string $state, string $postcode, string $city): array
    {
        $matched = [];

        foreach ($rates as $rate) {
            $countryMatch = $rate->country === '' || strcasecmp($rate->country, $country) === 0;
            if (!$countryMatch) continue;

            $stateMatch = $rate->state === '' || strcasecmp($rate->state, $state) === 0;
            if (!$stateMatch) continue;

            $postcodeMatch = true;
            if ($rate->postcode !== '') {
                $patterns = array_map('trim', explode(',', $rate->postcode));
                $postcodeMatch = false;
                foreach ($patterns as $pattern) {
                    if ($this->matchPostcode($postcode, $pattern)) {
                        $postcodeMatch = true;
                        break;
                    }
                }
            }
            if (!$postcodeMatch) continue;

            $cityMatch = true;
            if ($rate->city !== '') {
                $patterns = array_map('trim', explode(',', $rate->city));
                $cityMatch = false;
                foreach ($patterns as $pattern) {
                    if (strcasecmp($city, $pattern) === 0) {
                        $cityMatch = true;
                        break;
                    }
                }
            }
            if (!$cityMatch) continue;

            $matched[] = $rate;
        }

        // Sort by priority (ascending) then by specificity (more specific first)
        usort($matched, function (TaxRate $a, TaxRate $b): int {
            if ($a->priority !== $b->priority) {
                return $a->priority <=> $b->priority;
            }
            // More specific matches first (non-empty fields)
            $aSpec = (int) ($a->city !== '') + (int) ($a->postcode !== '') + (int) ($a->state !== '') + (int) ($a->country !== '');
            $bSpec = (int) ($b->city !== '') + (int) ($b->postcode !== '') + (int) ($b->state !== '') + (int) ($b->country !== '');
            return $bSpec <=> $aSpec;
        });

        return $matched;
    }

    private function matchPostcode(string $postcode, string $pattern): bool
    {
        if ($pattern === '*') return true;
        if (str_contains($pattern, '...')) {
            // Range: 90210...90220
            [$from, $to] = explode('...', $pattern);
            return $postcode >= $from && $postcode <= $to;
        }
        if (str_contains($pattern, '*')) {
            // Wildcard: 9021*
            $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
            return (bool) preg_match($regex, $postcode);
        }
        return strcasecmp($postcode, $pattern) === 0;
    }

    private function extractTaxableAmount(string $subtotal, array $rates, string $country, string $state, string $postcode, string $city): string
    {
        $matched = $this->matchRates($rates, $country, $state, $postcode, $city);
        if ($matched === []) return $subtotal;

        $totalRate = '0.00';
        foreach ($matched as $rate) {
            $totalRate = BcMath::add($totalRate, BcMath::div($rate->rate, '100', 6), 6);
        }

        // P = B * (1 + r) where P is price including tax, B is base price
        // B = P / (1 + r)
        $divisor = BcMath::add('1.00', $totalRate, 6);
        return BcMath::div($subtotal, $divisor, $this->dp);
    }

    private function round(string $value): string
    {
        // Use number_format for rounding since BcMath doesn't have round
        return number_format((float) $value, $this->dp, '.', '');
    }

    public function setRoundingMode(string $mode): void
    {
        if (!in_array($mode, ['line', 'order'], true)) {
            throw new \InvalidArgumentException('Rounding mode must be "line" or "order"');
        }
        $this->roundingMode = $mode;
    }

    public function setPricesIncludeTax(bool $include): void
    {
        $this->pricesIncludeTax = $include;
    }
}