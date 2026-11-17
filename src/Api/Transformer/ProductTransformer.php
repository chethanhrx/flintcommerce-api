<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\Product;

/**
 * WC v3 product resource shape.
 *
 * Money: decimal strings without thousand separators ("8.95").
 * Booleans: native JSON booleans. Dates: ISO8601 `Y-m-d\TH:i:s`
 * (site + `_gmt` pair; site TZ = UTC until settings land).
 */
final class ProductTransformer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $dp = 2,
    ) {
    }

    /**
     * @param array<int, array{id: int, name: string, slug: string}> $categories
     * @return array<string, mixed>
     */
    public function transform(Product $product, array $categories = []): array
    {
        $regular = $product->regular_price;
        $sale = $product->sale_price;
        $onSale = $sale !== null && $sale !== '' && $this->saleWindowOpen($product);

        $price = $onSale ? $sale : ($regular ?? '');

        $stockQuantity = $product->stock_quantity;
        $manageStock = (bool) $product->manage_stock;

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'slug' => (string) $product->slug,
            'permalink' => $this->baseUrl . '/product/' . rawurlencode((string) $product->slug) . '/',
            'date_created' => $this->dt($product->created_at),
            'date_created_gmt' => $this->dt($product->created_at),
            'date_modified' => $this->dt($product->updated_at),
            'date_modified_gmt' => $this->dt($product->updated_at),
            'type' => (string) $product->type,
            'status' => (string) $product->status,
            'featured' => (bool) $product->featured,
            'catalog_visibility' => (string) $product->catalog_visibility,
            'description' => (string) ($product->description ?? ''),
            'short_description' => (string) ($product->short_description ?? ''),
            'sku' => (string) ($product->sku ?? ''),
            'price' => $price === null ? '' : $this->money($price),
            'regular_price' => $regular === null ? '' : $this->money($regular),
            'sale_price' => $sale === null || $sale === '' ? '' : $this->money($sale),
            'date_on_sale_from' => $this->nullDt($product->date_on_sale_from),
            'date_on_sale_from_gmt' => $this->nullDt($product->date_on_sale_from),
            'date_on_sale_to' => $this->nullDt($product->date_on_sale_to),
            'date_on_sale_to_gmt' => $this->nullDt($product->date_on_sale_to),
            'on_sale' => $onSale,
            'purchasable' => $product->status === 'publish' && $price !== null && $price !== '',
            'total_sales' => (int) $product->total_sales,
            'virtual' => (bool) $product->virtual,
            'downloadable' => (bool) $product->downloadable,
            'downloads' => [],
            'download_limit' => (int) $product->download_limit,
            'download_expiry' => (int) $product->download_expiry,
            'external_url' => (string) ($product->external_url ?? ''),
            'button_text' => (string) ($product->button_text ?? ''),
            'tax_status' => (string) $product->tax_status,
            'tax_class' => (string) $product->tax_class,
            'manage_stock' => $manageStock,
            'stock_quantity' => $manageStock ? ($stockQuantity === null ? null : (int) $stockQuantity) : null,
            'stock_status' => (string) $product->stock_status,
            'backorders' => (string) $product->backorders,
            'backorders_allowed' => $product->backorders === 'yes',
            'backordered' => $manageStock && $stockQuantity !== null && (int) $stockQuantity < 0,
            'low_stock_amount' => $product->low_stock_amount === null ? '' : (string) $product->low_stock_amount,
            'sold_individually' => (bool) $product->sold_individually,
            'weight' => $product->weight === null ? '' : (string) $product->weight,
            'dimensions' => [
                'length' => $product->length === null ? '' : (string) $product->length,
                'width' => $product->width === null ? '' : (string) $product->width,
                'height' => $product->height === null ? '' : (string) $product->height,
            ],
            'shipping_required' => ! (bool) $product->virtual,
            'shipping_taxable' => ! (bool) $product->virtual,
            'shipping_class' => (string) $product->shipping_class,
            'shipping_class_id' => (int) $product->shipping_class_id,
            'reviews_allowed' => (bool) $product->reviews_allowed,
            'average_rating' => number_format((float) ($product->average_rating ?? 0), 2, '.', ''),
            'rating_count' => (int) $product->rating_count,
            'related_ids' => [],
            'upsell_ids' => [],
            'cross_sell_ids' => [],
            'parent_id' => (int) $product->parent_id,
            'purchase_note' => (string) ($product->purchase_note ?? ''),
            'categories' => $categories,
            'tags' => [],
            'images' => [],
            'attributes' => [],
            'variations' => [],
            'grouped_products' => [],
            'menu_order' => (int) $product->menu_order,
            'price_html' => '',
            'meta_data' => [],
            'has_options' => false,
        ];
    }

    private function money(string|float|int $amount): string
    {
        return number_format((float) $amount, $this->dp, '.', '');
    }

    private function saleWindowOpen(Product $product): bool
    {
        $now = time();

        $from = $product->date_on_sale_from;
        $to = $product->date_on_sale_to;

        if ($from !== null && $from !== '' && strtotime((string) $from) > $now) {
            return false;
        }

        if ($to !== null && $to !== '' && strtotime((string) $to) < $now) {
            return false;
        }

        return true;
    }

    private function dt(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $ts = strtotime((string) $value);

        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s', $ts);
    }

    private function nullDt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ts = strtotime((string) $value);

        return $ts === false ? null : gmdate('Y-m-d\TH:i:s', $ts);
    }
}
