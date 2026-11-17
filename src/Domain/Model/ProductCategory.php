<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ProductCategory extends Model
{
    protected string $table = 'product_categories';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'name', 'slug', 'parent_id', 'description', 'display', 'image_url',
        'menu_order', 'created_at', 'updated_at',
    ];

    public $id;
    public $name;
    public $slug;
    public $parent_id;
    public $description;
    public $display;
    public $image_url;
    public $menu_order;
    /** Published-product count, joined in list views (not a table column). */
    public $count;
    public $created_at;
    public $updated_at;
}
