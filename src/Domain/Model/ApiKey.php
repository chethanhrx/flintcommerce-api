<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ApiKey extends Model
{
    protected string $table = 'api_keys';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'user_id', 'description', 'permissions', 'consumer_key', 'consumer_secret',
        'truncated_key', 'last_access', 'created_at', 'updated_at',
    ];

    public $id;
    public $user_id;
    public $description;
    public $permissions;
    public $consumer_key;
    public $consumer_secret;
    public $truncated_key;
    public $last_access;
    public $created_at;
    public $updated_at;
}
