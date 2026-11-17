<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Resolved API key row (not a public response type).
 */
final class ApiKeyIdentityRow
{
    public function __construct(
        public readonly int $keyId,
        public readonly string $permissions,
        public readonly ?int $userId,
    ) {
    }
}
