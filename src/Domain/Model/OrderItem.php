<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class OrderItem extends Model
{
    protected string $table = 'order_items';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'order_id', 'name', 'product_id', 'variation_id', 'quantity',
        'tax_class', 'subtotal', 'subtotal_tax', 'total', 'total_tax',
        'tax_class_id', 'tax_data', 'meta_data', 'sku', 'price',
        'image', 'parent_item_id', 'created_at', 'updated_at',
    ];

    public $id;
    public $order_id;
    public $name;
    public $product_id;
    public $variation_id;
    public $quantity;
    public $tax_class;
    public $subtotal;
    public $subtotal_tax;
    public $total;
    public $total_tax;
    public $tax_class_id;
    public $tax_data;
    public $meta_data;
    public $sku;
    public $price;
    public $image;
    public $parent_item_id;
    public $created_at;
    public $updated_at;
}