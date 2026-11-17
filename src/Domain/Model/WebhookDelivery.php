<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class WebhookDelivery extends Model
{
    protected string $table = 'webhook_deliveries';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'webhook_id', 'request_method', 'request_url', 'request_headers', 'request_body',
        'response_code', 'response_headers', 'response_body', 'duration_ms',
        'status', 'error_message', 'attempts', 'last_attempt_at',
        'date_created', 'date_created_gmt',
    ];

    public $id;
    public $webhook_id;
    public $request_method;
    public $request_url;
    public $request_headers;
    public $request_body;
    public $response_code;
    public $response_headers;
    public $response_body;
    public $duration_ms;
    public $status;
    public $error_message;
    public $attempts;
    public $last_attempt_at;
    public $date_created;
    public $date_created_gmt;
}