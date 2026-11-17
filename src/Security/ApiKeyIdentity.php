<?php

declare(strict_types=1);

namespace App\Security;

use FlintPHP\Framework\Authentication\IdentityInterface;

/**
 * Identity bound to a WooCommerce-style API key.
 */
final class ApiKeyIdentity implements IdentityInterface
{
    public const ATTRIBUTE = 'wc_api_key_identity';

    public function __construct(
        public readonly int $keyId,
        public readonly string $consumerKey,
        public readonly string $permissions,
        public readonly ?int $userId,
    ) {
    }

    public function getIdentifier(): string|int
    {
        return $this->keyId;
    }

    public function canRead(): bool
    {
        return $this->permissions === 'read' || $this->permissions === 'read_write';
    }

    public function canWrite(): bool
    {
        return $this->permissions === 'write' || $this->permissions === 'read_write';
    }
}
