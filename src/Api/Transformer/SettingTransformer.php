<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\Setting;

final class SettingTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(Setting $setting): array
    {
        $settingUrl = $this->baseUrl . "/api/fc/v3/settings/{$setting->group_id}/{$setting->id}";
        $groupUrl = $this->baseUrl . "/api/fc/v3/settings/{$setting->group_id}";

        $options = [];
        if ($setting->options !== null && $setting->options !== '') {
            $decoded = json_decode($setting->options, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        $value = $this->formatValue($setting);

        return [
            'id' => (string) $setting->id,
            'label' => (string) $setting->label,
            'description' => (string) $setting->description,
            'type' => (string) $setting->type,
            'value' => $value,
            'default' => $this->formatDefault($setting),
            'tip' => (string) $setting->tip,
            'placeholder' => (string) $setting->placeholder,
            'options' => $options,
            '_links' => [
                'self' => [['href' => $settingUrl]],
                'collection' => [['href' => $groupUrl]],
            ],
        ];
    }

    private function formatValue(Setting $setting): mixed
    {
        if ($setting->value === null || $setting->value === '') {
            return $this->formatDefault($setting);
        }

        $stored = $setting->value;

        return match ($setting->type) {
            'checkbox' => (bool) $stored,
            'multiselect' => json_decode($stored, true) ?? [],
            'number', 'decimal' => (string) $stored,
            default => (string) $stored,
        };
    }

    private function formatDefault(Setting $setting): mixed
    {
        if ($setting->default_value === null || $setting->default_value === '') {
            return null;
        }

        $stored = $setting->default_value;

        return match ($setting->type) {
            'checkbox' => (bool) $stored,
            'multiselect' => json_decode($stored, true) ?? [],
            'number', 'decimal' => (string) $stored,
            default => (string) $stored,
        };
    }
}