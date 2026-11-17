<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class SettingGroup extends Model
{
    protected string $table = 'setting_groups';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'label', 'description', 'parent_id', 'sort'];

    public $id;
    public $label;
    public $description;
    public $parent_id;
    public $sort;
}
