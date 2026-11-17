<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class TaxClass extends Model
{
    protected string $table = 'tax_classes';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'name', 'slug', 'description', 'created_at', 'updated_at',
    ];

    public $id;
    public $name;
    public $slug;
    public $description;
    public $created_at;
    public $updated_at;
}