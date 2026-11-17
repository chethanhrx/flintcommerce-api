<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\ApiKey;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Orm\OrmManager;

/**
 * API key persistence. consumer_key stores hash_hmac('sha256', raw, 'wc-api')
 * exactly like WooCommerce so raw keys never live in the database.
 */
final class ApiKeyRepository
{
    private const KEY_CONTEXT = 'wc-api';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly OrmManager $orm,
    ) {
    }

    /**
     * Issue a new key pair. Returns [keyId, consumerKey, consumerSecret].
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public function issue(?int $userId, string $permissions, string $description): array
    {
        $consumerKey = 'ck_' . bin2hex(random_bytes(20));
        $consumerSecret = 'cs_' . bin2hex(random_bytes(20));

        $now = gmdate('Y-m-d H:i:s');

        $key = new ApiKey();
        $this->orm->fill($key, [
            'user_id' => $userId,
            'description' => $description,
            'permissions' => $permissions,
            'consumer_key' => self::hash($consumerKey),
            'consumer_secret' => $consumerSecret,
            'truncated_key' => substr($consumerKey, -7),
            'last_access' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!$this->orm->save($key)) {
            throw new \RuntimeException('Failed to persist API key.');
        }

        return [$key->id ?? 0, $consumerKey, $consumerSecret];
    }

    /**
     * Resolve an identity from a raw consumer key + secret (timing-safe).
     */
    public function authenticate(string $consumerKey, string $consumerSecret): ?ApiKeyIdentityRow
    {
        $hashed = self::hash($consumerKey);

        $row = $this->connection->fetch(
            'SELECT id, consumer_key, consumer_secret, permissions, user_id
             FROM api_keys WHERE consumer_key = ? LIMIT 1',
            [$hashed],
        );

        if ($row === null || !is_string($row['consumer_secret'])) {
            // Keep timing behaviour stable whether or not the key exists.
            // phpcs:ignore
            $timingSafe = hash_equals($hashed, $hashed);
            unset($timingSafe);
            return null;
        }

        if (!hash_equals($row['consumer_secret'], $consumerSecret)) {
            return null;
        }

        $this->connection->execute(
            'UPDATE api_keys SET last_access = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s'), (int) $row['id']],
        );

        return new ApiKeyIdentityRow(
            keyId: (int) $row['id'],
            permissions: (string) $row['permissions'],
            userId: $row['user_id'] === null ? null : (int) $row['user_id'],
        );
    }

    public static function hash(string $rawKey): string
    {
        return hash_hmac('sha256', $rawKey, self::KEY_CONTEXT);
    }
}
