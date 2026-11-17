<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ProductAttributeTerm extends Model
{
    protected string $table = 'product_attribute_terms';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'attribute_id', 'name', 'slug', 'menu_order', 'description', 'count', 'created_at', 'updated_at',
    ];

    public $id;
    public $attribute_id;
    public $name;
    public $slug;
    public $menu_order;
    public $description;
    public $count;
    public $created_at;
    public $updated_at;
}