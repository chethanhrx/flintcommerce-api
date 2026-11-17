<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\TaxClass;

final class TaxClassTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    public function transform(\App\Domain\Model\TaxClass $tc): array
    {
        $tcUrl = $this->baseUrl . "/api/fc/v3/taxes/classes/{$tc->id}";

        return [
            'id' => (int) $tc->id,
            'name' => (string) $tc->name,
            'slug' => (string) $tc->slug,
        ];
    }
}