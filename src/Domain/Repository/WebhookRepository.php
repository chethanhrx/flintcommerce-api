<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\Webhook;
use App\Domain\Model\WebhookDelivery;
use FlintPHP\Framework\Database\ConnectionInterface;

final class WebhookRepository
{
    private const ORDERBY_MAP = [
        'id' => 'id',
        'name' => 'name',
        'topic' => 'topic',
        'date_created' => 'date_created',
    ];

    private const VALID_STATUSES = ['active', 'paused', 'disabled'];
    private const VALID_DELIVERY_STATUSES = ['pending', 'delivered', 'failed', 'retrying'];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT * FROM webhooks' . $where
            . $this->buildOrderBy($filters)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): Webhook => $this->hydrate($row), $rows);
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM webhooks' . $where,
            $params,
        );
    }

    public function find(int $id): ?Webhook
    {
        $row = $this->connection->fetch('SELECT * FROM webhooks WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**


     * @param array<string, mixed> $payload


     * @return Webhook


     */


    public function create(array $payload): Webhook
    {
        $now = gmdate('Y-m-d H:i:s');
        $secret = $payload['secret'] ?? bin2hex(random_bytes(32));

        return $this->connection->transaction(function () use ($payload, $now, $secret): Webhook {
            $this->connection->execute(
                'INSERT INTO webhooks (
                    name, status, topic, delivery_url, secret, fields,
                    date_created, date_created_gmt, date_modified, date_modified_gmt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $payload['name'],
                    $payload['status'] ?? 'active',
                    $payload['topic'],
                    $payload['delivery_url'],
                    $secret,
                    $payload['fields'] ?? null,
                    $now,
                    $now,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM webhooks ORDER BY id DESC LIMIT 1');

            $webhook = $this->find($id);
            if ($webhook === null) {
                throw new \RuntimeException('Webhook insert failed.');
            }

            return $webhook;
        });
    }

    /**


     * @param array<string, mixed> $payload


     * @return Webhook


     */


    public function update(int $id, array $payload): Webhook
    {
        return $this->connection->transaction(function () use ($id, $payload): Webhook {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_webhook_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['secret'], $updates['date_created'], $updates['date_created_gmt']);

            if (isset($updates['status']) && !in_array($updates['status'], self::VALID_STATUSES, true)) {
                throw WcApiException::invalidParam(['status' => 'status must be active, paused, or disabled']);
            }

            if (isset($updates['topic']) && !is_string($updates['topic'])) {
                throw WcApiException::invalidParam(['topic' => 'topic must be a string']);
            }

            if (isset($updates['delivery_url']) && !filter_var($updates['delivery_url'], FILTER_VALIDATE_URL)) {
                throw WcApiException::invalidParam(['delivery_url' => 'delivery_url must be a valid URL']);
            }

            if (array_key_exists('fields', $updates)) {
                $updates['fields'] = $updates['fields'] === null ? null : json_encode($updates['fields']);
            }

            $updates['date_modified'] = gmdate('Y-m-d H:i:s');
            $updates['date_modified_gmt'] = gmdate('Y-m-d H:i:s');

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE webhooks SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $webhook = $this->find($id);
            if ($webhook === null) {
                throw new \RuntimeException('Webhook update failed.');
            }

            return $webhook;
        });
    }

    /**


     * @return array


     */


    public function delete(int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($id, $force): array {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_webhook_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute('DELETE FROM webhooks WHERE id = ?', [$id]);
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE webhooks SET status = \'disabled\', date_modified = ?, date_modified_gmt = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id],
            );

            $disabled = $this->find($id);
            if ($disabled === null) {
                throw new \RuntimeException('Webhook disable failed.');
            }

            return [$disabled, false];
        });
    }

    /**
     * Create a delivery record for a webhook event.
     */
    public function createDelivery(int $webhookId, array $data): WebhookDelivery
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($webhookId, $data, $now): WebhookDelivery {
            $this->connection->execute(
                'INSERT INTO webhook_deliveries (
                    webhook_id, request_method, request_url, request_headers, request_body,
                    status, date_created, date_created_gmt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $webhookId,
                    $data['request_method'] ?? 'POST',
                    $data['request_url'],
                    $data['request_headers'] ?? null,
                    $data['request_body'] ?? null,
                    'pending',
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM webhook_deliveries ORDER BY id DESC LIMIT 1');

            $delivery = $this->findDelivery($id);
            if ($delivery === null) {
                throw new \RuntimeException('Delivery insert failed.');
            }

            return $delivery;
        });
    }

    public function findDelivery(int $id): ?WebhookDelivery
    {
        $row = $this->connection->fetch('SELECT * FROM webhook_deliveries WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrateDelivery($row);
    }

    public function updateDelivery(int $id, array $updates): WebhookDelivery
    {
        return $this->connection->transaction(function () use ($id, $updates): WebhookDelivery {
            $delivery = $this->findDelivery($id);
            if ($delivery === null) {
                throw new WcApiException('woocommerce_rest_webhook_delivery_invalid_id', 'Invalid delivery ID.', 404);
            }

            $allowed = ['response_code', 'response_headers', 'response_body', 'duration_ms', 'status', 'error_message', 'attempts', 'last_attempt_at'];
            $filtered = array_intersect_key($updates, array_flip($allowed));

            if (isset($filtered['status']) && !in_array($filtered['status'], self::VALID_DELIVERY_STATUSES, true)) {
                throw WcApiException::invalidParam(['status' => 'invalid delivery status']);
            }

            if ($filtered !== []) {
                $columns = array_keys($filtered);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE webhook_deliveries SET $set WHERE id = ?",
                    [...array_values($filtered), $id],
                );
            }

            $updated = $this->findDelivery($id);
            if ($updated === null) {
                throw new \RuntimeException('Delivery update failed.');
            }

            return $updated;
        });
    }

    public function listDeliveries(int $webhookId, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$webhookId, $limit, $offset],
        );

        return array_map(fn (array $row): WebhookDelivery => $this->hydrateDelivery($row), $rows);
    }

    public function countDeliveries(int $webhookId): int
    {
        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_id = ?',
            [$webhookId],
        );
    }

    /**
     * Get pending/failed deliveries ready for retry (for worker).
     */
    public function getPendingDeliveries(int $limit = 100): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM webhook_deliveries
             WHERE status IN (\'pending\', \'retrying\', \'failed\')
             AND attempts < 5
             ORDER BY last_attempt_at ASC NULLS FIRST, id ASC
             LIMIT ?',
            [$limit],
        );

        return array_map(fn (array $row): WebhookDelivery => $this->hydrateDelivery($row), $rows);
    }

    public function normalizeFilters(array $filters): array
    {
        $out = [];

        if (isset($filters['status']) && !in_array($filters['status'], self::VALID_STATUSES, true)) {
            throw WcApiException::invalidParam(['status' => 'status must be active, paused, or disabled']);
        }
        if (isset($filters['status'])) {
            $out['status'] = $filters['status'];
        }

        if (isset($filters['topic']) && $filters['topic'] !== '') {
            $out['topic'] = $filters['topic'];
        }

        if (isset($filters['orderby']) && !array_key_exists($filters['orderby'], self::ORDERBY_MAP)) {
            throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']);
        }
        if (isset($filters['orderby'])) {
            $out['orderby'] = $filters['orderby'];
        }

        if (isset($filters['order']) && !in_array(strtolower((string) $filters['order']), ['asc', 'desc'], true)) {
            throw WcApiException::invalidParam(['order' => 'order must be asc or desc']);
        }
        if (isset($filters['order'])) {
            $out['order'] = strtolower((string) $filters['order']);
        }

        return $out;
    }

    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (isset($filters['status'])) {
            $clauses[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if (isset($filters['topic'])) {
            $clauses[] = 'topic = ?';
            $params[] = $filters['topic'];
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    private function buildOrderBy(array $filters): string
    {
        $direction = strtolower($filters['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $column = self::ORDERBY_MAP[$filters['orderby'] ?? 'date_created'] ?? 'date_created';

        return sprintf(' ORDER BY %s %s', $column, $direction);
    }

    /**


     * @param array<string, mixed> $row


     * @return Webhook


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\Webhook



     */



    private function hydrate(array $row): \App\Domain\Model\Webhook
    {
        $webhook = new Webhook();
        foreach ($row as $column => $value) {
            $webhook->{$column} = $value;
        }
        return $webhook;
    }

    /**


     * @param array<string, mixed> $row


     * @return WebhookDelivery


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\WebhookDelivery



     */



    private function hydrateDelivery(array $row): \App\Domain\Model\WebhookDelivery
    {
        $delivery = new WebhookDelivery();
        foreach ($row as $column => $value) {
            $delivery->{$column} = $value;
        }
        return $delivery;
    }
}