<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ProductVariation;
use App\Support\Pagination;

final class ProductVariationTransformer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $dp,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(ProductVariation $variation): array
    {
        $variationUrl = $this->baseUrl . "/api/fc/v3/products/{$variation->product_id}/variations/{$variation->id}";

        $attributes = $this->transformAttributes($variation->attributes);
        $dimensions = $this->transformDimensions($variation);
        $image = $this->transformImage($variation->image);
        $downloads = $this->transformDownloads($variation->downloads);
        $metaData = $this->transformMetaData($variation->meta_data);

        return [
            'id' => (int) $variation->id,
            'date_created' => $this->formatDate($variation->created_at),
            'date_created_gmt' => $this->formatDate($variation->created_at),
            'date_modified' => $this->formatDate($variation->updated_at),
            'date_modified_gmt' => $this->formatDate($variation->updated_at),
            'description' => (string) ($variation->description ?? ''),
            'permalink' => $variationUrl,
            'sku' => (string) ($variation->sku ?? ''),
            'price' => $this->formatPrice($variation->price ?? $variation->regular_price),
            'regular_price' => $this->formatPrice($variation->regular_price),
            'sale_price' => $this->formatPrice($variation->sale_price),
            'date_on_sale_from' => $this->formatDate($variation->date_on_sale_from),
            'date_on_sale_from_gmt' => $this->formatDate($variation->date_on_sale_from),
            'date_on_sale_to' => $this->formatDate($variation->date_on_sale_to),
            'date_on_sale_to_gmt' => $this->formatDate($variation->date_on_sale_to),
            'on_sale' => $this->isOnSale($variation),
            'status' => (string) $variation->status,
            'purchasable' => $variation->status === 'publish' && $variation->stock_status !== 'outofstock',
            'virtual' => (bool) $variation->virtual,
            'downloadable' => (bool) $variation->downloadable,
            'downloads' => $downloads,
            'download_limit' => (int) $variation->download_limit,
            'download_expiry' => (int) $variation->download_expiry,
            'tax_status' => (string) $variation->tax_status,
            'tax_class' => (string) $variation->tax_class,
            'manage_stock' => (bool) $variation->manage_stock,
            'stock_quantity' => $variation->manage_stock && $variation->stock_quantity !== null ? (int) $variation->stock_quantity : null,
            'stock_status' => (string) $variation->stock_status,
            'backorders' => (string) $variation->backorders,
            'backorders_allowed' => (bool) $variation->backorders_allowed,
            'backordered' => (bool) $variation->backordered,
            'low_stock_amount' => $variation->low_stock_amount === null ? '' : (string) $variation->low_stock_amount,
            'weight' => $variation->weight === null ? '' : (string) $variation->weight,
            'dimensions' => $dimensions,
            'shipping_required' => !$variation->virtual,
            'shipping_taxable' => !$variation->virtual,
            'shipping_class' => (string) ($variation->shipping_class ?? ''),
            'shipping_class_id' => (int) $variation->shipping_class_id,
            'image' => $image,
            'attributes' => $attributes,
            'menu_order' => (int) $variation->menu_order,
            'meta_data' => $metaData,
        ];
    }

    private function formatPrice(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $this->dp, '.', '');
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function isOnSale(ProductVariation $variation): bool
    {
        if ($variation->sale_price === null || $variation->sale_price === '') {
            return false;
        }

        $regular = (float) ($variation->regular_price ?? 0);
        $sale = (float) $variation->sale_price;

        if ($sale >= $regular) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($variation->date_on_sale_from !== null) {
            try {
                $from = new \DateTimeImmutable($variation->date_on_sale_from);
                if ($from > $now) {
                    return false;
                }
            } catch (\Exception) {
            }
        }

        if ($variation->date_on_sale_to !== null) {
            try {
                $to = new \DateTimeImmutable($variation->date_on_sale_to);
                if ($to < $now) {
                    return false;
                }
            } catch (\Exception) {
            }
        }

        return true;
    }

    private function transformAttributes(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $result[] = [
                'id' => (int) ($attr['id'] ?? 0),
                'name' => (string) ($attr['name'] ?? ''),
                'option' => (string) ($attr['option'] ?? ''),
            ];
        }

        return $result;
    }

    private function transformDimensions(ProductVariation $variation): array
    {
        return [
            'length' => $variation->length === null ? '' : (string) $variation->length,
            'width' => $variation->width === null ? '' : (string) $variation->width,
            'height' => $variation->height === null ? '' : (string) $variation->height,
        ];
    }

    private function transformImage(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return [
            'id' => (int) ($decoded['id'] ?? 0),
            'src' => (string) ($decoded['src'] ?? ''),
            'name' => (string) ($decoded['name'] ?? ''),
            'alt' => (string) ($decoded['alt'] ?? ''),
        ];
    }

    private function transformDownloads(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $dl) {
            if (!is_array($dl)) {
                continue;
            }
            $result[] = [
                'id' => (int) ($dl['id'] ?? 0),
                'name' => (string) ($dl['name'] ?? ''),
                'file' => (string) ($dl['file'] ?? ''),
            ];
        }

        return $result;
    }

    private function transformMetaData(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $result[] = [
                'id' => (int) ($meta['id'] ?? 0),
                'key' => (string) ($meta['key'] ?? ''),
                'value' => $meta['value'] ?? '',
                'display_key' => (string) ($meta['display_key'] ?? ''),
                'display_value' => $meta['display_value'] ?? '',
            ];
        }

        return $result;
    }
}