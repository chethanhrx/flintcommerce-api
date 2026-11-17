<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ShippingZone extends Model
{
    protected string $table = 'shipping_zones';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'name', 'order_', 'created_at', 'updated_at',
    ];

    public $id;
    public $name;
    public $order_;
    public $created_at;
    public $updated_at;
}