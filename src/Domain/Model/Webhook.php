<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class Webhook extends Model
{
    protected string $table = 'webhooks';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'name', 'status', 'topic', 'delivery_url', 'secret', 'fields',
        'date_created', 'date_created_gmt', 'date_modified', 'date_modified_gmt',
    ];

    public $id;
    public $name;
    public $status;
    public $topic;
    public $delivery_url;
    public $secret;
    public $fields;
    public $date_created;
    public $date_created_gmt;
    public $date_modified;
    public $date_modified_gmt;
}