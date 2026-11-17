<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ProductAttribute extends Model
{
    protected string $table = 'product_attributes';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'name', 'slug', 'type', 'order_by', 'has_archives', 'created_at', 'updated_at',
    ];

    public $id;
    public $name;
    public $slug;
    public $type;
    public $order_by;
    public $has_archives;
    public $created_at;
    public $updated_at;
}