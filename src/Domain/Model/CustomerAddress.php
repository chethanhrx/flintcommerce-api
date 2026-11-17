<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class CustomerAddress extends Model
{
    protected string $table = 'customer_addresses';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'customer_id', 'type', 'first_name', 'last_name', 'company', 'address_1',
        'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'is_default',
        'created_at', 'updated_at',
    ];

    public $id;
    public $customer_id;
    public $type;
    public $first_name;
    public $last_name;
    public $company;
    public $address_1;
    public $address_2;
    public $city;
    public $state;
    public $postcode;
    public $country;
    public $phone;
    public $is_default;
    public $created_at;
    public $updated_at;
}