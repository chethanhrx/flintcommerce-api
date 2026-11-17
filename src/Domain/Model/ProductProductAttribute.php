<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ProductProductAttribute extends Model
{
    protected string $table = 'product_product_attributes';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'product_id', 'attribute_id', 'position', 'is_variation', 'is_visible', 'is_taxonomy', 'name', 'slug', 'options', 'created_at', 'updated_at',
    ];

    public $id;
    public $product_id;
    public $attribute_id;
    public $position;
    public $is_variation;
    public $is_visible;
    public $is_taxonomy;
    public $name;
    public $slug;
    public $options;
    public $created_at;
    public $updated_at;
}