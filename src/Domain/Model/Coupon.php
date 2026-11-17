<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class Coupon extends Model
{
    protected string $table = 'coupons';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'code', 'amount', 'date_created', 'date_created_gmt', 'date_modified',
        'date_modified_gmt', 'discount_type', 'description', 'date_expires',
        'date_expires_gmt', 'usage_count', 'individual_use', 'product_ids',
        'excluded_product_ids', 'usage_limit', 'usage_limit_per_user',
        'limit_usage_to_x_items', 'free_shipping', 'product_categories',
        'excluded_product_categories', 'exclude_sale_items', 'minimum_amount',
        'maximum_amount', 'email_restrictions', 'used_by', 'created_at', 'updated_at',
    ];

    public $id;
    public $code;
    public $amount;
    public $date_created;
    public $date_created_gmt;
    public $date_modified;
    public $date_modified_gmt;
    public $discount_type;
    public $description;
    public $date_expires;
    public $date_expires_gmt;
    public $usage_count;
    public $individual_use;
    public $product_ids;
    public $excluded_product_ids;
    public $usage_limit;
    public $usage_limit_per_user;
    public $limit_usage_to_x_items;
    public $free_shipping;
    public $product_categories;
    public $excluded_product_categories;
    public $exclude_sale_items;
    public $minimum_amount;
    public $maximum_amount;
    public $email_restrictions;
    public $used_by;
    public $created_at;
    public $updated_at;
}