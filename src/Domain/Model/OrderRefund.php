<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class OrderRefund extends Model
{
    protected string $table = 'order_refunds';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'order_id', 'amount', 'reason', 'status', 'refunded_by', 'refunded_via', 'date_created', 'date_created_gmt',
    ];

    public $id;
    public $order_id;
    public $amount;
    public $reason;
    public $status;
    public $refunded_by;
    public $refunded_via;
    public $date_created;
    public $date_created_gmt;
}