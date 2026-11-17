<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\PaymentGateway;

final class PaymentGatewayTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(PaymentGateway $gateway): array
    {
        $gatewayUrl = $this->baseUrl . "/api/fc/v3/payment_gateways/{$gateway->id}";

        $settings = [];
        if ($gateway->settings !== null && $gateway->settings !== '') {
            $decoded = json_decode($gateway->settings, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $methodSupports = [];
        if ($gateway->method_supports !== null && $gateway->method_supports !== '') {
            $decoded = json_decode($gateway->method_supports, true);
            if (is_array($decoded)) {
                $methodSupports = $decoded;
            }
        }

        return [
            'id' => (int) $gateway->id,
            'id_key' => (string) $gateway->id_key,
            'title' => (string) $gateway->title,
            'description' => (string) $gateway->description,
            'order' => (int) $gateway->order_,
            'enabled' => (bool) $gateway->enabled,
            'method_title' => (string) $gateway->method_title,
            'method_description' => (string) $gateway->method_description,
            'method_supports' => $methodSupports,
            'settings' => $settings,
        ];
    }
}