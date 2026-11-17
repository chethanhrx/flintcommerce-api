<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\Webhook;

final class WebhookTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(Webhook $webhook): array
    {
        $webhookUrl = $this->baseUrl . "/api/fc/v3/webhooks/{$webhook->id}";

        $fields = [];
        if ($webhook->fields !== null && $webhook->fields !== '') {
            $decoded = json_decode($webhook->fields, true);
            if (is_array($decoded)) {
                $fields = $decoded;
            }
        }

        return [
            'id' => (int) $webhook->id,
            'name' => (string) $webhook->name,
            'status' => (string) $webhook->status,
            'topic' => (string) $webhook->topic,
            'delivery_url' => (string) $webhook->delivery_url,
            'secret' => (string) $webhook->secret,
            'fields' => $fields,
            'date_created' => $this->formatDate($webhook->date_created),
            'date_created_gmt' => $this->formatDate($webhook->date_created_gmt),
            'date_modified' => $this->formatDate($webhook->date_modified),
            'date_modified_gmt' => $this->formatDate($webhook->date_modified_gmt),
            '_links' => [
                'self' => [['href' => $webhookUrl]],
                'collection' => [['href' => $this->baseUrl . '/api/fc/v3/webhooks']],
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