<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class OrderNote extends Model
{
    protected string $table = 'order_notes';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'order_id', 'note', 'customer_note', 'added_by_user_id', 'date_created', 'date_created_gmt',
    ];

    public $id;
    public $order_id;
    public $note;
    public $customer_note;
    public $added_by_user_id;
    public $date_created;
    public $date_created_gmt;
}