<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\SettingGroup;

final class SettingGroupTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(SettingGroup $group): array
    {
        $groupUrl = $this->baseUrl . "/api/fc/v3/settings/{$group->id}";

        return [
            'id' => (string) $group->id,
            'label' => (string) $group->label,
            'description' => (string) $group->description,
            'parent_id' => $group->parent_id !== '' ? (string) $group->parent_id : null,
            '_links' => [
                'self' => [['href' => $groupUrl]],
                'collection' => [['href' => $this->baseUrl . '/api/fc/v3/settings']],
            ],
        ];
    }
}