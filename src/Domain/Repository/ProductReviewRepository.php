<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ProductReview;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ProductReviewRepository
{
    private const ORDERBY_MAP = [
        'date' => 'created_at',
        'modified' => 'updated_at',
        'id' => 'id',
        'rating' => 'rating',
    ];

    private const STATUS_VALUES = ['any', 'approved', 'hold', 'spam', 'unspam', 'trash'];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function list(int $productId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$productId], $params);

        $sql = 'SELECT * FROM product_reviews WHERE product_id = ?' . $where
            . $this->buildOrderBy($f)
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): ProductReview => $this->hydrate($row), $rows);
    }

    public function count(int $productId, array $f): int
    {
        [$where, $params] = $this->buildWhere($f);
        $params = array_merge([$productId], $params);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM product_reviews WHERE product_id = ?' . $where,
            $params,
        );
    }

    public function find(int $productId, int $id): ?ProductReview
    {
        $row = $this->connection->fetch(
            'SELECT * FROM product_reviews WHERE product_id = ? AND id = ?',
            [$productId, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $productId, array $payload): ProductReview
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($productId, $payload, $now): ProductReview {
            $emailMd5 = md5(strtolower(trim($payload['reviewer_email'])));

            $this->connection->execute(
                'INSERT INTO product_reviews (
                    product_id, reviewer_name, reviewer_email, reviewer_email_md5, status, rating, review, verified, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $productId,
                    $payload['reviewer_name'],
                    $payload['reviewer_email'],
                    $emailMd5,
                    $payload['status'] ?? 'approved',
                    $payload['rating'] ?? 5,
                    $payload['review'] ?? '',
                    (int) ($payload['verified'] ?? 0),
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn(
                'SELECT id FROM product_reviews WHERE product_id = ? ORDER BY id DESC LIMIT 1',
                [$productId],
            );

            $review = $this->find($productId, $id);
            if ($review === null) {
                throw new \RuntimeException('Review insert failed.');
            }

            return $review;
        });
    }

    public function update(int $productId, int $id, array $payload): ProductReview
    {
        return $this->connection->transaction(function () use ($productId, $id, $payload): ProductReview {
            $existing = $this->find($productId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_review_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['product_id'], $updates['reviewer_email_md5'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['reviewer_email']) && is_string($updates['reviewer_email'])) {
                $updates['reviewer_email_md5'] = md5(strtolower(trim($updates['reviewer_email'])));
            }

            if (isset($updates['rating']) && $updates['rating'] !== null) {
                if (!is_int($updates['rating']) && (is_string($updates['rating']) && preg_match('/\A\d+\z/', $updates['rating']) !== 1)) {
                    throw WcApiException::invalidParam(['rating' => 'rating must be an integer between 1 and 5']);
                }
                $rating = (int) $updates['rating'];
                if ($rating < 1 || $rating > 5) {
                    throw WcApiException::invalidParam(['rating' => 'rating must be between 1 and 5']);
                }
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE product_reviews SET $set WHERE product_id = ? AND id = ?",
                    [...array_values($updates), $productId, $id],
                );
            }

            $review = $this->find($productId, $id);
            if ($review === null) {
                throw new \RuntimeException('Review update failed.');
            }

            return $review;
        });
    }

    public function delete(int $productId, int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($productId, $id, $force): array {
            $existing = $this->find($productId, $id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_review_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute(
                    'DELETE FROM product_reviews WHERE product_id = ? AND id = ?',
                    [$productId, $id],
                );
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE product_reviews SET status = ?, updated_at = ? WHERE product_id = ? AND id = ?',
                ['trash', gmdate('Y-m-d H:i:s'), $productId, $id],
            );

            $trashed = $this->find($productId, $id);
            if ($trashed === null) {
                throw new \RuntimeException('Review trash failed.');
            }

            return [$trashed, false];
        });
    }

    private const WRITABLE_COLUMNS = [
        'reviewer_name', 'reviewer_email', 'reviewer_email_md5', 'status',
        'rating', 'review', 'verified', 'updated_at',
    ];

    /**


     * @param array<string, mixed> $f


     * @return array


     */


    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['status'])) {
            $statuses = $f['status'];
            if ($statuses !== ['any']) {
                $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
                $clauses[] = sprintf('status IN (%s)', $placeholders);
                foreach ($statuses as $s) {
                    $params[] = $s;
                }
            }
        }

        if (isset($f['reviewer']) && is_int($f['reviewer'])) {
            $clauses[] = 'reviewer_email = ?';
            $params[] = $f['reviewer'];
        }

        if (isset($f['reviewer_email']) && is_string($f['reviewer_email'])) {
            $clauses[] = 'reviewer_email = ?';
            $params[] = $f['reviewer_email'];
        }

        if (isset($f['before']) && is_string($f['before'])) {
            $clauses[] = 'created_at <= ?';
            $params[] = $f['before'];
        }

        if (isset($f['after']) && is_string($f['after'])) {
            $clauses[] = 'created_at >= ?';
            $params[] = $f['after'];
        }

        if (isset($f['include']) && $f['include'] !== []) {
            $placeholders = implode(', ', array_fill(0, count($f['include']), '?'));
            $clauses[] = sprintf('id IN (%s)', $placeholders);
            foreach ($f['include'] as $id) {
                $params[] = $id;
            }
        }

        if (isset($f['exclude']) && $f['exclude'] !== []) {
            $placeholders = implode(', ', array_fill(0, count($f['exclude']), '?'));
            $clauses[] = sprintf('id NOT IN (%s)', $placeholders);
            foreach ($f['exclude'] as $id) {
                $params[] = $id;
            }
        }

        $where = $clauses === [] ? '' : ' AND ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**


     * @param array<string, mixed> $f


     * @return string


     */


    private function buildOrderBy(array $f): string
    {
        $direction = strtolower($f['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        if (($f['orderby'] ?? 'date') === 'include' && is_array($f['include'] ?? null) && $f['include'] !== []) {
            $cases = [];
            foreach (array_values($f['include']) as $i => $id) {
                $cases[] = sprintf('WHEN %d THEN %d', (int) $id, $i);
            }
            return ' ORDER BY CASE id ' . implode(' ', $cases) . ' ELSE ' . count($f['include']) . ' END ASC';
        }

        $column = self::ORDERBY_MAP[$f['orderby'] ?? 'date'] ?? 'created_at';

        return sprintf(' ORDER BY %s %s', $column, $direction);
    }

    /**


     * @param array<string, mixed> $f


     * @return array<string, mixed>


     */


    public function normalizeFilters(array $f): array
    {
        $out = [];

        if (isset($f['status'])) {
            $statuses = is_array($f['status']) ? $f['status'] : [$f['status']];
            foreach ($statuses as $s) {
                if (!in_array($s, self::STATUS_VALUES, true)) {
                    throw WcApiException::invalidParam(['status' => 'status is not one of ' . implode(',', self::STATUS_VALUES)]);
                }
            }
            $out['status'] = $statuses;
        }

        if (isset($f['reviewer']) && is_int($f['reviewer'])) {
            $out['reviewer'] = $f['reviewer'];
        }

        if (isset($f['reviewer_email']) && is_string($f['reviewer_email'])) {
            $out['reviewer_email'] = $f['reviewer_email'];
        }

        if (isset($f['before']) && is_string($f['before'])) {
            $out['before'] = $f['before'];
        }

        if (isset($f['after']) && is_string($f['after'])) {
            $out['after'] = $f['after'];
        }

        if (isset($f['orderby']) && !array_key_exists($f['orderby'], self::ORDERBY_MAP)) {
            throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']);
        }

        if (isset($f['order']) && !in_array(strtolower((string) $f['order']), ['asc', 'desc'], true)) {
            throw WcApiException::invalidParam(['order' => 'order must be asc or desc']);
        }

        foreach (['include', 'exclude'] as $key) {
            if (isset($f[$key])) {
                $out[$key] = self::idList($f[$key], $key);
            }
        }

        return $out;
    }

    private static function idList(mixed $raw, string $key): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/\A\d+\z/', $part) !== 1) {
                throw WcApiException::invalidParam([$key => "$key must contain integers"]);
            }
            $ids[] = (int) $part;
        }

        return $ids;
    }

    /**


     * @param array<string, mixed> $row


     * @return ProductReview


     */


    /**



     * @param array<string, mixed> $row



     * @return \App\Domain\Model\ProductReview



     */



    private function hydrate(array $row): \App\Domain\Model\ProductReview
    {
        $review = new ProductReview();

        foreach ($row as $column => $value) {
            $review->{$column} = $value;
        }

        return $review;
    }
}