<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ShippingZoneLocation extends Model
{
    protected string $table = 'shipping_zone_locations';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'zone_id', 'type', 'code', 'created_at', 'updated_at',
    ];

    public $id;
    public $zone_id;
    public $type;
    public $code;
    public $created_at;
    public $updated_at;
}