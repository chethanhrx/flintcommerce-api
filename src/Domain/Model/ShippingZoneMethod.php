<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ShippingZoneMethod extends Model
{
    protected string $table = 'shipping_zone_methods';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'zone_id', 'method_id', 'method_order', 'is_enabled', 'settings', 'created_at', 'updated_at',
    ];

    public $id;
    public $zone_id;
    public $method_id;
    public $method_order;
    public $is_enabled;
    public $settings;
    public $created_at;
    public $updated_at;
}