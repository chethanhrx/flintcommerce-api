<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ProductTag extends Model
{
    protected string $table = 'product_tags';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'name', 'slug', 'description', 'count', 'created_at', 'updated_at',
    ];

    public $id;
    public $name;
    public $slug;
    public $description;
    public $count;
    public $created_at;
    public $updated_at;
}