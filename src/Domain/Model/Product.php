<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

/**
 * Product model. Column properties are public (framework ORM convention —
 * hydration/extract use public reflection); fillable stays protected metadata.
 */
final class Product extends Model
{
    protected string $table = 'products';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'name', 'slug', 'type', 'status', 'featured', 'catalog_visibility',
        'description', 'short_description', 'sku', 'price', 'regular_price', 'sale_price',
        'date_on_sale_from', 'date_on_sale_to', 'total_sales', 'virtual', 'downloadable',
        'download_limit', 'download_expiry', 'external_url', 'button_text',
        'tax_status', 'tax_class', 'tax_class_id', 'manage_stock', 'stock_quantity', 'stock_status',
        'backorders', 'low_stock_amount', 'sold_individually', 'weight',
        'length', 'width', 'height', 'shipping_class', 'shipping_class_id',
        'reviews_allowed', 'average_rating', 'rating_count', 'parent_id', 'purchase_note',
        'menu_order', 'created_at', 'updated_at',
    ];

    public $id;
    public $name;
    public $slug;
    public $type;
    public $status;
    public $featured;
    public $catalog_visibility;
    public $description;
    public $short_description;
    public $sku;
    public $price;
    public $regular_price;
    public $sale_price;
    public $date_on_sale_from;
    public $date_on_sale_to;
    public $total_sales;
    public $virtual;
    public $downloadable;
    public $download_limit;
    public $download_expiry;
    public $external_url;
    public $button_text;
    public $tax_status;
    public $tax_class;
    public $tax_class_id;
    public $manage_stock;
    public $stock_quantity;
    public $stock_status;
    public $backorders;
    public $low_stock_amount;
    public $sold_individually;
    public $weight;
    public $length;
    public $width;
    public $height;
    public $shipping_class;
    public $shipping_class_id;
    public $reviews_allowed;
    public $average_rating;
    public $rating_count;
    public $parent_id;
    public $purchase_note;
    public $menu_order;
    public $created_at;
    public $updated_at;
}
