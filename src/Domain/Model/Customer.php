<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class Customer extends Model
{
    protected string $table = 'customers';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'email', 'password_hash', 'first_name', 'last_name', 'username', 'role',
        'avatar_url', 'is_active', 'last_login_at', 'created_at', 'updated_at',
    ];

    public $id;
    public $email;
    public $password_hash;
    public $first_name;
    public $last_name;
    public $username;
    public $role;
    public $avatar_url;
    public $is_active;
    public $last_login_at;
    public $created_at;
    public $updated_at;
}