<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Api\Exception\WcApiException;
use App\Domain\Model\ProductCategory;
use App\Support\Slug;
use FlintPHP\Framework\Database\ConnectionInterface;

final class ProductCategoryRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    /**
     * @return list<ProductCategory>
     */
    /**

     * @param array<string, mixed> $f

     * @return array

     */

    public function list(array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($f);

        $order = sprintf(
            ' ORDER BY %s %s, name ASC',
            $this->orderByColumn($f['orderby'] ?? 'name'),
            strtolower($f['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC',
        );

        $rows = $this->connection->fetchAll(
            'SELECT * FROM product_categories' . $where . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        return array_map(fn (array $row): ProductCategory => $this->hydrate($row), $rows);
    }

    /**


     * @param array<string, mixed> $f


     * @return int


     */


    public function count(array $f): int
    {
        [$where, $params] = $this->buildWhere($f);

        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM product_categories' . $where,
            $params,
        );
    }

    public function find(int $id): ?ProductCategory
    {
        $row = $this->connection->fetch('SELECT * FROM product_categories WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**


     * @param array<string, mixed> $payload


     * @return ProductCategory


     */


    public function create(array $payload): ProductCategory
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($payload, $now): ProductCategory {
            if (isset($payload['parent']) && (int) $payload['parent'] > 0) {
                $parent = $this->find((int) $payload['parent']);
                if ($parent === null) {
                    throw WcApiException::invalidParam(['parent' => 'parent category does not exist']);
                }
            }

            $slug = $payload['slug'] ?? Slug::fromName($payload['name']);
            $slug = Slug::unique($this->connection, 'product_categories', $slug);

            $this->connection->execute(
                'INSERT INTO product_categories (name, slug, parent_id, description, display, menu_order, count, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
                [
                    $payload['name'],
                    $slug,
                    $payload['parent'] ?? 0,
                    $payload['description'] ?? '',
                    $payload['display'] ?? 'default',
                    $payload['menu_order'] ?? 0,
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->connection->fetchColumn('SELECT id FROM product_categories WHERE slug = ?', [$slug]);

            $category = $this->find($id);
            if ($category === null) {
                throw new \RuntimeException('Category insert failed.');
            }

            return $category;
        });
    }

    /**


     * @param array<string, mixed> $payload


     * @return ProductCategory


     */


    public function update(int $id, array $payload): ProductCategory
    {
        return $this->connection->transaction(function () use ($id, $payload): ProductCategory {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new WcApiException('woocommerce_rest_term_invalid', 'Resource does not exist.', 404);
            }

            if (isset($payload['parent']) && (int) $payload['parent'] > 0) {
                if ((int) $payload['parent'] === $id) {
                    throw WcApiException::invalidParam(['parent' => 'category cannot be its own parent']);
                }
                $parent = $this->find((int) $payload['parent']);
                if ($parent === null) {
                    throw WcApiException::invalidParam(['parent' => 'parent category does not exist']);
                }
                // Check for circular reference
                if ($this->wouldCreateCycle($id, (int) $payload['parent'])) {
                    throw WcApiException::invalidParam(['parent' => 'cannot set parent - would create cycle']);
                }
            }

            $updates = $payload;
            unset($updates['id'], $updates['count'], $updates['date_created'], $updates['date_modified']);

            if (isset($updates['slug']) && is_string($updates['slug'])) {
                $updates['slug'] = Slug::unique($this->connection, 'product_categories', $updates['slug'], $id);
            }

            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $updates = array_intersect_key($updates, array_flip(self::WRITABLE_COLUMNS));

            if ($updates !== []) {
                $columns = array_keys($updates);
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", $columns));
                $this->connection->execute(
                    "UPDATE product_categories SET $set WHERE id = ?",
                    [...array_values($updates), $id],
                );
            }

            $category = $this->find($id);
            if ($category === null) {
                throw new \RuntimeException('Category update failed.');
            }

            return $category;
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
                throw new WcApiException('woocommerce_rest_term_invalid', 'Resource does not exist.', 404);
            }

            if ($force) {
                // Reassign children to parent (WC behavior)
                $this->connection->execute(
                    'UPDATE product_categories SET parent_id = ? WHERE parent_id = ?',
                    [$existing->parent_id ?? 0, $id],
                );
                $this->connection->execute('DELETE FROM product_categories WHERE id = ?', [$id]);
                return [$existing, true];
            }

            $this->connection->execute(
                'UPDATE product_categories SET updated_at = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), $id],
            );

            $trashed = $this->find($id);
            if ($trashed === null) {
                throw new \RuntimeException('Category trash failed.');
            }

            return [$trashed, false];
        });
    }

    private function wouldCreateCycle(int $childId, int $newParentId): bool
    {
        $current = $newParentId;
        while ($current > 0) {
            if ($current === $childId) {
                return true;
            }
            $parent = $this->connection->fetchColumn(
                'SELECT parent_id FROM product_categories WHERE id = ?',
                [$current],
            );
            $current = (int) ($parent ?? 0);
        }
        return false;
    }

    /**
     * Published-product count per category for a page of categories.
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    public function countMapFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->connection->fetchAll(
            'SELECT r.category_id, COUNT(*) AS n
             FROM product_category_relationships r
             INNER JOIN products p ON p.id = r.product_id
             WHERE p.status = \'publish\' AND r.category_id IN (' . $placeholders . ')
             GROUP BY r.category_id',
            $ids,
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['category_id']] = (int) $row['n'];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $f
     * @return array{0: string, 1: list<mixed>}
     */
    /**

     * @param array<string, mixed> $f

     * @return array

     */

    private function buildWhere(array $f): array
    {
        $clauses = [];
        $params = [];

        if (isset($f['slug']) && $f['slug'] !== '') {
            $clauses[] = 'slug = ?';
            $params[] = $f['slug'];
        }

        if (isset($f['search']) && $f['search'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['search']) . '%';
            $clauses[] = '(name LIKE ? ESCAPE \'\\\' OR description LIKE ? ESCAPE \'\\\')';
            array_push($params, $like, $like);
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

        if (isset($f['parent'])) {
            $ids = self::idList($f['parent'], 'parent');
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $clauses[] = sprintf('parent_id IN (%s)', $placeholders);
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    private function orderByColumn(string $orderby): string
    {
        return match ($orderby) {
            'id' => 'id',
            'include', 'slug' => 'slug',
            'name' => 'name',
            'menu_order' => 'menu_order',
            'count' => 'id',
            default => throw WcApiException::invalidParam(['orderby' => 'orderby is not a valid option']),
        };
    }

    /**
     * @return list<int>
     */
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
     */
    /**

     * @param array<string, mixed> $row

     * @return ProductCategory

     */

    /**


     * @param array<string, mixed> $row


     * @return \App\Domain\Model\ProductCategory


     */


    private function hydrate(array $row): \App\Domain\Model\ProductCategory
    {
        $category = new ProductCategory();

        foreach ($row as $column => $value) {
            $category->{$column} = $value;
        }

        return $category;
    }

    private const WRITABLE_COLUMNS = [
        'name', 'slug', 'parent_id', 'description', 'display', 'menu_order', 'updated_at',
    ];
}
