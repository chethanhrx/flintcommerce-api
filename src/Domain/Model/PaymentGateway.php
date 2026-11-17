<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class PaymentGateway extends Model
{
    protected string $table = 'payment_gateways';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'id_key', 'title', 'description', 'order_', 'enabled',
        'method_title', 'method_description', 'method_supports', 'settings',
        'created_at', 'updated_at',
    ];

    public $id;
    public $id_key;
    public $title;
    public $description;
    public $order_;
    public $enabled;
    public $method_title;
    public $method_description;
    public $method_supports;
    public $settings;
    public $created_at;
    public $updated_at;
}