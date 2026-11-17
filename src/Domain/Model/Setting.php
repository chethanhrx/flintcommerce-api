<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class Setting extends Model
{
    protected string $table = 'settings';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'group_id', 'id', 'label', 'description', 'type', 'value',
        'default_value', 'tip', 'placeholder', 'options', 'sort',
    ];

    public $group_id;
    public $id;
    public $label;
    public $description;
    public $type;
    public $value;
    public $default_value;
    public $tip;
    public $placeholder;
    public $options;
    public $sort;
}
