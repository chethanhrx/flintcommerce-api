<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class TaxRate extends Model
{
    protected string $table = 'tax_rates';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'tax_class_id', 'country', 'state', 'postcode', 'city', 'rate',
        'name', 'priority', 'compound', 'shipping', 'order_', 'created_at', 'updated_at',
    ];

    public $id;
    public $tax_class_id;
    public $country;
    public $state;
    public $postcode;
    public $city;
    public $rate;
    public $name;
    public $priority;
    public $compound;
    public $shipping;
    public $order_;
    public $created_at;
    public $updated_at;
}