<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\Setting;
use App\Domain\Model\SettingGroup;
use FlintPHP\Framework\Database\ConnectionInterface;

final class SettingRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function groups(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM setting_groups ORDER BY sort, id'
        );

        return array_map(fn (array $row): SettingGroup => $this->hydrateGroup($row), $rows);
    }

    public function findGroup(string $id): ?SettingGroup
    {
        $row = $this->connection->fetch('SELECT * FROM setting_groups WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrateGroup($row);
    }

    public function settingsFor(string $groupId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM settings WHERE group_id = ? ORDER BY sort, id',
            [$groupId]
        );

        return array_map(fn (array $row): Setting => $this->hydrateSetting($row), $rows);
    }

    public function find(string $groupId, string $id): ?Setting
    {
        $row = $this->connection->fetch(
            'SELECT * FROM settings WHERE group_id = ? AND id = ?',
            [$groupId, $id]
        );

        return $row === null ? null : $this->hydrateSetting($row);
    }

    public function updateValue(string $groupId, string $id, mixed $value): Setting
    {
        return $this->connection->transaction(function () use ($groupId, $id, $value): Setting {
            $setting = $this->find($groupId, $id);
            if ($setting === null) {
                throw new WcApiException('woocommerce_rest_setting_invalid_id', 'Invalid setting ID.', 404);
            }

            $castValue = $this->castValue($setting->type, $value, $setting->options);

            $this->connection->execute(
                'UPDATE settings SET value = ?, updated_at = ? WHERE group_id = ? AND id = ?',
                [$castValue, gmdate('Y-m-d H:i:s'), $groupId, $id],
            );

            $updated = $this->find($groupId, $id);
            if ($updated === null) {
                throw new \RuntimeException('Setting update failed.');
            }

            return $updated;
        });
    }

    /**
     * Cast and validate a value according to the setting type.
     *
     * @param string $type       Setting type (text, email, textarea, select, multiselect, checkbox, number, decimal, password, color, date, etc.)
     * @param mixed  $value      Input value
     * @param string|null $options JSON string of options for select/multiselect/radio
     * @return string|int|null   Stored value
     */
    private function castValue(string $type, mixed $value, ?string $options): mixed
    {
        // Null handling: if value is null or empty string, store NULL (will fall back to default in transformer)
        if ($value === null || $value === '') {
            return null;
        }

        $optionsArray = $options !== null && $options !== '' ? json_decode($options, true) : [];

        return match ($type) {
            'checkbox' => $this->castCheckbox($value),
            'number' => $this->castNumber($value),
            'decimal' => $this->castDecimal($value),
            'select', 'radio' => $this->castSelect($value, $optionsArray),
            'multiselect' => $this->castMultiselect($value, $optionsArray),
            'email' => $this->castEmail($value),
            default => (string) $value,
        };
    }

    private function castCheckbox(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $v = is_string($value) ? strtolower(trim($value)) : $value;

        return match ($v) {
            1, '1', 'true', 'yes', 'on' => 1,
            0, '0', 'false', 'no', 'off' => 0,
            default => throw WcApiException::invalidParam(['value' => 'value must be a boolean']),
        };
    }

    private function castNumber(mixed $value): string
    {
        if (!is_numeric($value)) {
            throw WcApiException::invalidParam(['value' => 'value must be a number']);
        }

        return (string) (int) $value;
    }

    private function castDecimal(mixed $value): string
    {
        if (!is_numeric($value)) {
            throw WcApiException::invalidParam(['value' => 'value must be a number']);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function castSelect(mixed $value, array $options): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw WcApiException::invalidParam(['value' => 'value must be a string or number']);
        }

        $validValues = array_column($options, 'value');
        if ($validValues !== [] && !in_array((string) $value, $validValues, true)) {
            throw WcApiException::invalidParam(['value' => 'value is not a valid option']);
        }

        return (string) $value;
    }

    private function castMultiselect(mixed $value, array $options): string
    {
        if (!is_array($value)) {
            throw WcApiException::invalidParam(['value' => 'value must be an array']);
        }

        $validValues = array_column($options, 'value');
        foreach ($value as $v) {
            if (!is_string($v) && !is_int($v) && !is_float($v)) {
                throw WcApiException::invalidParam(['value' => 'value items must be strings or numbers']);
            }
            if ($validValues !== [] && !in_array((string) $v, $validValues, true)) {
                throw WcApiException::invalidParam(['value' => 'value contains invalid option']);
            }
        }

        $encoded = json_encode(array_map('strval', $value));
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode multiselect value as JSON');
        }

        return $encoded;
    }

    private function castEmail(mixed $value): string
    {
        $v = (string) $value;
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            throw WcApiException::invalidParam(['value' => 'value must be a valid email address']);
        }

        return $v;
    }

    /**


     * @param array<string, mixed> $row


     * @return SettingGroup


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\SettingGroup



     */



    private function hydrateGroup(array $row): \App\Domain\Model\SettingGroup
    {
        $group = new SettingGroup();
        foreach ($row as $column => $value) {
            $group->{$column} = $value;
        }
        return $group;
    }

    /**


     * @param array<string, mixed> $row


     * @return Setting


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\Setting



     */



    private function hydrateSetting(array $row): \App\Domain\Model\Setting
    {
        $setting = new Setting();
        foreach ($row as $column => $value) {
            $setting->{$column} = $value;
        }
        return $setting;
    }
}