<?php

declare(strict_types=1);

namespace App\Support;

/**
 * WordPress/WooCommerce-style slug generation (sanitize_title subset).
 */
final class Slug
{
    public static function fromName(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $slug);
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug) ?: $slug;
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'product' : $slug;
    }

    /**
     * Ensure uniqueness in a table: appends -2, -3, ... on collision.
     */
    public static function unique(
        \FlintPHP\Framework\Database\ConnectionInterface $connection,
        string $table,
        string $slug,
        ?int $ignoreId = null,
    ): string {
        $candidate = $slug;
        $suffix = 2;

        while (true) {
            $sql = "SELECT COUNT(*) FROM $table WHERE slug = ?";
            $params = [$candidate];

            if ($ignoreId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $ignoreId;
            }

            if ((int) $connection->fetchColumn($sql, $params) === 0) {
                return $candidate;
            }

            $candidate = $slug . '-' . $suffix;
            $suffix++;

            if ($suffix > 100) {
                return $slug . '-' . bin2hex(random_bytes(4));
            }
        }
    }
}
