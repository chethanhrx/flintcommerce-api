<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\WebhookDeliveryTransformer;
use App\Api\Transformer\WebhookTransformer;
use App\Domain\Repository\WebhookRepository;
use App\Support\Pagination;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class WebhookController
{
    public function __construct(
        private readonly \App\Domain\Repository\WebhookRepository $webhooks,
        private readonly \App\Api\Transformer\WebhookTransformer $transformer,
        private readonly \App\Api\Transformer\WebhookDeliveryTransformer $deliveryTransformer,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->webhooks->normalizeFilters($query);

        $total = $this->webhooks->count($filters);
        $items = $this->webhooks->list($filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($w): array => $this->transformer->transform($w),
            $items,
        );

        return WcResponse::collection(
            $payload,
            200,
            $pagination,
            $total,
            $this->pageUrl($request, $pagination, $total, +1),
            $this->pageUrl($request, $pagination, $total, -1),
        );
    }

    public function show(Request $request, int $id): Response
    {
        $webhook = $this->webhooks->find($id);

        if ($webhook === null) {
            throw new WcApiException('woocommerce_rest_webhook_invalid_id', 'Invalid ID.', 404);
        }

        return Response::json($this->transformer->transform($webhook), 200);
    }

    public function store(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['name']) || !is_string($payload['name']) || trim($payload['name']) === '') {
            $missing['name'] = 'name is required';
        }
        if (!isset($payload['topic']) || !is_string($payload['topic']) || trim($payload['topic']) === '') {
            $missing['topic'] = 'topic is required';
        }
        if (!isset($payload['delivery_url']) || !is_string($payload['delivery_url']) || trim($payload['delivery_url']) === '') {
            $missing['delivery_url'] = 'delivery_url is required';
        } elseif (!\App\Support\Security::isSafeUrl($payload['delivery_url'])) {
            $missing['delivery_url'] = 'delivery_url must be a valid and safe URL';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        if (isset($payload['status']) && !in_array($payload['status'], ['active', 'paused', 'disabled'], true)) {
            throw WcApiException::invalidParam(['status' => 'status must be active, paused, or disabled']);
        }

        $webhook = $this->webhooks->create($payload);

        return Response::json($this->transformer->transform($webhook), 201);
    }

    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $webhook = $this->webhooks->update($id, $payload);

        return Response::json($this->transformer->transform($webhook), 200);
    }

    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$webhook, $deleted] = $this->webhooks->delete($id, $force);
        $data = $this->transformer->transform($webhook);

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    public function batch(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $result = [
            'create' => [],
            'update' => [],
            'delete' => [],
        ];

        $created = $payload['create'] ?? [];
        if (!is_array($created)) {
            throw WcApiException::invalidParam(['create' => 'create must be an array']);
        }
        foreach (array_values($created) as $item) {
            $item = is_array($item) ? $item : [];
            $missing = [];
            if (!isset($item['name']) || !is_string($item['name']) || trim($item['name']) === '') {
                $missing['name'] = 'name is required';
            }
            if (!isset($item['topic']) || !is_string($item['topic']) || trim($item['topic']) === '') {
                $missing['topic'] = 'topic is required';
            }
            if (!isset($item['delivery_url']) || !is_string($item['delivery_url']) || trim($item['delivery_url']) === '') {
                $missing['delivery_url'] = 'delivery_url is required';
            } elseif (!filter_var($item['delivery_url'], FILTER_VALIDATE_URL)) {
                $missing['delivery_url'] = 'delivery_url must be a valid URL';
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $webhook = $this->webhooks->create($item);
            $result['create'][] = $this->transformer->transform($webhook);
        }

        $updated = $payload['update'] ?? [];
        if (!is_array($updated)) {
            throw WcApiException::invalidParam(['update' => 'update must be an array']);
        }
        foreach (array_values($updated) as $i => $item) {
            $item = is_array($item) ? $item : [];
            if (!isset($item['id']) || !is_numeric($item['id'])) {
                throw WcApiException::invalidParam(["update[$i]" => 'update requires a valid id']);
            }
            $webhook = $this->webhooks->update((int) $item['id'], $item);
            $result['update'][] = $this->transformer->transform($webhook);
        }

        $deleted = $payload['delete'] ?? [];
        if (!is_array($deleted)) {
            throw WcApiException::invalidParam(['delete' => 'delete must be an array']);
        }
        foreach (array_values($deleted) as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? null;
            }
            if (!is_numeric($item)) {
                throw WcApiException::invalidParam(['delete' => 'delete must contain valid ids']);
            }
            [$webhook, $wasDeleted] = $this->webhooks->delete((int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($webhook),
            ];
            unset($wasDeleted);
        }

        return Response::json($result, 200);
    }

    // --- Deliveries ---

    public function deliveriesIndex(Request $request, int $webhookId): Response
    {
        $webhook = $this->webhooks->find($webhookId);
        if ($webhook === null) {
            throw new WcApiException('woocommerce_rest_webhook_invalid_id', 'Invalid webhook ID.', 404);
        }

        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);

        $total = $this->webhooks->countDeliveries($webhookId);
        $items = $this->webhooks->listDeliveries($webhookId, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($d): array => $this->deliveryTransformer->transform($d),
            $items,
        );

        return WcResponse::collection(
            $payload,
            200,
            $pagination,
            $total,
            $this->pageUrl($request, $pagination, $total, +1),
            $this->pageUrl($request, $pagination, $total, -1),
        );
    }

    public function deliveriesShow(Request $request, int $webhookId, int $id): Response
    {
        $webhook = $this->webhooks->find($webhookId);
        if ($webhook === null) {
            throw new WcApiException('woocommerce_rest_webhook_invalid_id', 'Invalid webhook ID.', 404);
        }

        $delivery = $this->webhooks->findDelivery($id);
        if ($delivery === null || $delivery->webhook_id !== $webhookId) {
            throw new WcApiException('woocommerce_rest_webhook_delivery_invalid_id', 'Invalid delivery ID.', 404);
        }

        return Response::json($this->deliveryTransformer->transform($delivery), 200);
    }

    public function deliveriesRetry(Request $request, int $webhookId): Response
    {
        $webhook = $this->webhooks->find($webhookId);
        if ($webhook === null) {
            throw new WcApiException('woocommerce_rest_webhook_invalid_id', 'Invalid webhook ID.', 404);
        }

        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        if (!isset($payload['delivery_ids']) || !is_array($payload['delivery_ids'])) {
            throw WcApiException::missingParam(['delivery_ids' => 'delivery_ids is required and must be an array']);
        }

        $retried = [];
        foreach ($payload['delivery_ids'] as $deliveryId) {
            if (!is_numeric($deliveryId)) {
                continue;
            }
            $delivery = $this->webhooks->findDelivery((int) $deliveryId);
            if ($delivery === null || $delivery->webhook_id !== $webhookId) {
                continue;
            }
            $retried[] = $this->webhooks->updateDelivery((int) $deliveryId, [
                'status' => 'retrying',
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $payload = array_map(
            fn ($d): array => $this->deliveryTransformer->transform($d),
            $retried,
        );

        return Response::json($payload, 200);
    }

    private static function booleanParam(mixed $raw, string $key): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $v = is_string($raw) ? strtolower(trim($raw)) : $raw;

        return match ($v) {
            1, '1', 'true', 'yes' => true,
            0, '0', 'false', 'no' => false,
            default => throw WcApiException::invalidParam([$key => "$key must be boolean"]),
        };
    }

    private function pageUrl(Request $request, Pagination $pagination, int $total, int $delta): string
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $targetOffset = $pagination->offset + $delta * $pagination->perPage;

        if ($targetOffset < 0 || $targetOffset >= $total) {
            return '';
        }

        $page = intdiv($targetOffset, $pagination->perPage) + 1;
        unset($query['offset']);
        $query['page'] = (string) $page;

        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $this->baseUrl . $request->path() . ($parts === [] ? '' : '?' . implode('&', $parts));
    }
}