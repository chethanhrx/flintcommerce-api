<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ProductCategory;

/**
 * WC v3 product category resource shape.
 */
final class CategoryTransformer
{
    public function __construct(
        private readonly int $dp = 2,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(ProductCategory $category, int $count): array
    {
        $image = $category->image_url;

        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'slug' => (string) $category->slug,
            'parent' => (int) $category->parent_id,
            'description' => (string) ($category->description ?? ''),
            'display' => (string) $category->display,
            'image' => $image === null || $image === ''
                ? null
                : [
                    'id' => null,
                    'date_created' => $this->dt($category->created_at),
                    'date_created_gmt' => $this->dt($category->created_at),
                    'date_modified' => $this->dt($category->updated_at),
                    'date_modified_gmt' => $this->dt($category->updated_at),
                    'src' => (string) $image,
                    'name' => (string) $category->name,
                    'alt' => '',
                ],
            'menu_order' => (int) $category->menu_order,
            'count' => $count,
            'meta_data' => [],
        ];
    }

    private function dt(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $ts = strtotime((string) $value);

        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s', $ts);
    }
}
