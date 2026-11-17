<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ProductVariation extends Model
{
    protected string $table = 'product_variations';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'product_id',
        'status',
        'sku',
        'price',
        'regular_price',
        'sale_price',
        'date_on_sale_from',
        'date_on_sale_to',
        'virtual',
        'downloadable',
        'downloads',
        'download_limit',
        'download_expiry',
        'tax_status',
        'tax_class',
        'manage_stock',
        'stock_quantity',
        'stock_status',
        'backorders',
        'backorders_allowed',
        'backordered',
        'low_stock_amount',
        'weight',
        'length',
        'width',
        'height',
        'shipping_class',
        'shipping_class_id',
        'image',
        'attributes',
        'menu_order',
        'meta_data',
        'created_at',
        'updated_at',
    ];

    public $id;
    public $product_id;
    public $status;
    public $sku;
    public $price;
    public $regular_price;
    public $sale_price;
    public $date_on_sale_from;
    public $date_on_sale_to;
    public $virtual;
    public $downloadable;
    public $downloads;
    public $download_limit;
    public $download_expiry;
    public $tax_status;
    public $tax_class;
    public $manage_stock;
    public $stock_quantity;
    public $stock_status;
    public $backorders;
    public $backorders_allowed;
    public $backordered;
    public $low_stock_amount;
    public $weight;
    public $length;
    public $width;
    public $height;
    public $shipping_class;
    public $shipping_class_id;
    public $image;
    public $attributes;
    public $menu_order;
    public $meta_data;
    public $created_at;
    public $updated_at;
}