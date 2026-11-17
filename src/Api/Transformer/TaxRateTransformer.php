<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\TaxRate;

final class TaxRateTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    public function transform(\App\Domain\Model\TaxRate $rate): array
    {
        $rateUrl = $this->baseUrl . "/api/fc/v3/taxes/classes/{$rate->tax_class_id}/rates/{$rate->id}";

        return [
            'id' => (int) $rate->id,
            'country' => (string) $rate->country,
            'state' => (string) $rate->state,
            'postcode' => (string) $rate->postcode,
            'city' => (string) $rate->city,
            'rate' => (string) $rate->rate,
            'name' => (string) $rate->name,
            'priority' => (int) $rate->priority,
            'compound' => (bool) $rate->compound,
            'shipping' => (bool) $rate->shipping,
            'order' => (int) $rate->order_,
        ];
    }
}