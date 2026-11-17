<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\WebhookDelivery;

final class WebhookDeliveryTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(WebhookDelivery $delivery): array
    {
        $deliveryUrl = $this->baseUrl . "/api/fc/v3/webhooks/{$delivery->webhook_id}/deliveries/{$delivery->id}";

        $requestHeaders = [];
        if ($delivery->request_headers !== null && $delivery->request_headers !== '') {
            $decoded = json_decode($delivery->request_headers, true);
            if (is_array($decoded)) {
                $requestHeaders = $decoded;
            }
        }

        $responseHeaders = [];
        if ($delivery->response_headers !== null && $delivery->response_headers !== '') {
            $decoded = json_decode($delivery->response_headers, true);
            if (is_array($decoded)) {
                $responseHeaders = $decoded;
            }
        }

        $requestBody = null;
        if ($delivery->request_body !== null && $delivery->request_body !== '') {
            $decoded = json_decode($delivery->request_body, true);
            if (is_array($decoded) || is_string($decoded)) {
                $requestBody = $decoded;
            }
        }

        $responseBody = null;
        if ($delivery->response_body !== null && $delivery->response_body !== '') {
            $decoded = json_decode($delivery->response_body, true);
            if (is_array($decoded) || is_string($decoded)) {
                $responseBody = $decoded;
            }
        }

        return [
            'id' => (int) $delivery->id,
            'webhook_id' => (int) $delivery->webhook_id,
            'request_method' => (string) $delivery->request_method,
            'request_url' => (string) $delivery->request_url,
            'request_headers' => $requestHeaders,
            'request_body' => $requestBody,
            'response_code' => $delivery->response_code !== null ? (int) $delivery->response_code : null,
            'response_headers' => $responseHeaders,
            'response_body' => $responseBody,
            'duration_ms' => $delivery->duration_ms !== null ? (int) $delivery->duration_ms : null,
            'status' => (string) $delivery->status,
            'error_message' => $delivery->error_message !== null ? (string) $delivery->error_message : null,
            'attempts' => (int) $delivery->attempts,
            'last_attempt_at' => $this->formatDate($delivery->last_attempt_at),
            'date_created' => $this->formatDate($delivery->date_created),
            'date_created_gmt' => $this->formatDate($delivery->date_created_gmt),
            '_links' => [
                'self' => [['href' => $deliveryUrl]],
                'collection' => [['href' => $this->baseUrl . "/api/fc/v3/webhooks/{$delivery->webhook_id}/deliveries"]],
                'webhook' => [['href' => $this->baseUrl . "/api/fc/v3/webhooks/{$delivery->webhook_id}"]],
            ],
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}