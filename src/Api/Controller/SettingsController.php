<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\SettingGroupTransformer;
use App\Api\Transformer\SettingTransformer;
use App\Domain\Repository\SettingRepository;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class SettingsController
{
    public function __construct(
        private readonly \App\Domain\Repository\SettingRepository $settings,
        private readonly \App\Api\Transformer\SettingGroupTransformer $groupTransformer,
        private readonly \App\Api\Transformer\SettingTransformer $settingTransformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $groups = $this->settings->groups();

        $payload = array_map(
            fn ($g): array => $this->groupTransformer->transform($g),
            $groups,
        );

        return Response::json($payload, 200);
    }

    public function group(Request $request, string $group): Response
    {
        $groupObj = $this->settings->findGroup($group);

        if ($groupObj === null) {
            throw new WcApiException('woocommerce_rest_setting_invalid_group', 'Invalid group.', 404);
        }

        $items = $this->settings->settingsFor($group);

        $payload = array_map(
            fn ($s): array => $this->settingTransformer->transform($s),
            $items,
        );

        return Response::json($payload, 200);
    }

    public function show(Request $request, string $group, string $id): Response
    {
        $groupObj = $this->settings->findGroup($group);

        if ($groupObj === null) {
            throw new WcApiException('woocommerce_rest_setting_invalid_group', 'Invalid group.', 404);
        }

        $setting = $this->settings->find($group, $id);

        if ($setting === null) {
            throw new WcApiException('woocommerce_rest_setting_invalid_id', 'Invalid setting ID.', 404);
        }

        return Response::json($this->settingTransformer->transform($setting), 200);
    }

    public function update(Request $request, string $group, string $id): Response
    {
        $groupObj = $this->settings->findGroup($group);

        if ($groupObj === null) {
            throw new WcApiException('woocommerce_rest_setting_invalid_group', 'Invalid group.', 404);
        }

        $setting = $this->settings->find($group, $id);

        if ($setting === null) {
            throw new WcApiException('woocommerce_rest_setting_invalid_id', 'Invalid setting ID.', 404);
        }

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        if (!isset($payload['value'])) {
            throw WcApiException::missingParam(['value' => 'value is required']);
        }

        $updated = $this->settings->updateValue($group, $id, $payload['value']);

        return Response::json($this->settingTransformer->transform($updated), 200);
    }

    public function batch(Request $request, string $group): Response
    {
        $groupObj = $this->settings->findGroup($group);

        if ($groupObj === null) {
            throw new WcApiException('woocommerce_rest_setting_invalid_group', 'Invalid group.', 404);
        }

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $result = ['update' => []];

        $updated = $payload['update'] ?? [];
        if (!is_array($updated)) {
            throw WcApiException::invalidParam(['update' => 'update must be an array']);
        }

        foreach (array_values($updated) as $i => $item) {
            $item = is_array($item) ? $item : [];
            if (!isset($item['id']) || !is_string($item['id']) || $item['id'] === '') {
                throw WcApiException::invalidParam(["update[$i]" => 'update requires a valid id']);
            }

            if (!isset($item['value'])) {
                throw WcApiException::missingParam(["update[$i]" => 'value is required']);
            }

            $setting = $this->settings->updateValue($group, $item['id'], $item['value']);
            $result['update'][] = $this->settingTransformer->transform($setting);
        }

        return Response::json($result, 200);
    }
}