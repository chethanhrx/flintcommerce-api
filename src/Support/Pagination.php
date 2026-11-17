<?php

declare(strict_types=1);

namespace App\Support;

use App\Api\Exception\WcApiException;

/**
 * WooCommerce v3 pagination semantics.
 *
 * - `page`  : 1-based page number (default 1)
 * - `per_page` : 1..100 (default 10)
 * - `offset`   : explicit offset; overrides `page` when present (WC behaviour)
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 10;
    public const MAX_PER_PAGE = 100;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $offset,
    ) {
    }

    /**
     * @param array<string, mixed> $query Raw query params
     */
    public static function fromQuery(array $query): self
    {
        $page = self::intParam($query, 'page', 1);
        $perPage = self::intParam($query, 'per_page', self::DEFAULT_PER_PAGE);
        $offsetParam = null;

        if (array_key_exists('offset', $query) && $query['offset'] !== null && $query['offset'] !== '') {
            $offsetParam = self::intParam($query, 'offset', 0);
        }

        if ($page < 1) {
            throw WcApiException::invalidParam(['page' => 'page must be >= 1']);
        }

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw WcApiException::invalidParam(['per_page' => 'per_page must be between 1 and 100']);
        }

        if ($offsetParam !== null && $offsetParam < 0) {
            throw WcApiException::invalidParam(['offset' => 'offset must be >= 0']);
        }

        return new self(
            page: $page,
            perPage: $perPage,
            offset: $offsetParam ?? ($page - 1) * $perPage,
        );
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    public function totalPages(int $total): int
    {
        return (int) max(1, ceil($total / $this->perPage));
    }

    /**
     * RFC 5988 Link header with rel="next"/"prev"/"first"/"last".
     */
    public function linkHeader(string $nextUrl, string $prevUrl): string
    {
        $links = [];

        if ($nextUrl !== '') {
            $links[] = sprintf('<%s>; rel="next"', $nextUrl);
        }
        if ($prevUrl !== '') {
            $links[] = sprintf('<%s>; rel="prev"', $prevUrl);
        }

        return implode(', ', $links);
    }

    /**
     * Strict integer query param parsing. Rejects float/garbage like WC does.
     */
    private static function intParam(array $query, string $key, int $default): int
    {
        $raw = $query[$key] ?? null;

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (is_array($raw) || !is_scalar($raw) || preg_match('/\A\d+\z/', (string) $raw) !== 1) {
            throw WcApiException::invalidParam([$key => "$key must be an integer"]);
        }

        return (int) $raw;
    }
}
