<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\TaxClass;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

final class TaxClassRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $f
     * @return array<int, TaxClass>
     */
    public function list(array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);
        $sql = 'SELECT * FROM tax_classes' . $where
            . ' ORDER BY name ASC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): TaxClass => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $f
     * @return int
     */
    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM tax_classes' . $where,
            $params,
        );
    }

    public function find(int $id): ?TaxClass
    {
        $row = $this->connection->fetch('SELECT * FROM tax_classes WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?TaxClass
    {
        $row = $this->connection->fetch('SELECT * FROM tax_classes WHERE slug = ?', [$slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return TaxClass
     */
    public function create(array $payload): TaxClass
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): TaxClass {
            $slug = $payload['slug'] ?? Slug::fromName($payload['name']);
            $slug = $this->uniqueSlug($slug);

            $this->connection->execute(
                'INSERT INTO tax_classes (name, slug, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $payload['name'],
                    $slug,
                    $payload['description'] ?? '',
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM tax_classes WHERE slug = ?', [$slug]);

            $tc = $this->find($id);
            if ($tc === null) {
                throw new \RuntimeException('Tax class insert failed.');
            }

            return $tc;
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return TaxClass
     */
    public function update(int $id, array $payload): TaxClass
    {
        return $this->connection->transaction(function () use ($id, $payload): TaxClass {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_tax_class_invalid_id', 'Invalid ID.', 404);
            }

            $updates = $payload;
            unset($updates['id'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['slug']) && is_string($updates['slug'])) {
                $updates['slug'] = $this->uniqueSlug($updates['slug'], $id);
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE tax_classes SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $tc = $this->find($id);
            if ($tc === null) {
                throw new \RuntimeException('Tax class update failed.');
            }

            return $tc;
        });
    }

    /**
     * @return array{0: TaxClass, 1: bool}
     */
    public function delete(int $id, bool $force): array
    {
        return $this->connection->transaction(function () use ($id, $force): array {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_tax_class_invalid_id', 'Invalid ID.', 404);
            }

            if ($force) {
                $this->connection->execute('DELETE FROM tax_classes WHERE id = ?', [$id]);
                return [$existing, true];
            }

            return [$existing, false];
        });
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (true) {
            $sql = 'SELECT COUNT(*) FROM tax_classes WHERE slug = ?';
            $params = [$candidate];

            if ($ignoreId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $ignoreId;
            }

            if ((int) $this->connection->fetchColumn($sql, $params) === 0) {
                return $candidate;
            }

            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }
    }

    /**
     * @param array<string, mixed> $f
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['search']) && $f['search'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['search']) . '%';
            $clauses[] = '(name LIKE ? ESCAPE \'\\\' OR slug LIKE ? ESCAPE \'\\\')';
            array_push($params, $like, $like);
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $f): array
    {
        $out = [];

        if (isset($f['search']) && $f['search'] !== '') {
            $out['search'] = $f['search'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return TaxClass
     */
    private function hydrate(array $row): TaxClass
    {
        $tc = new \App\Domain\Model\TaxClass();

        foreach ($row as $column => $value) {
            $tc->{$column} = $value;
        }

        return $tc;
    }
}