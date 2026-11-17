<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Domain\Repository\ApiKeyRepository;
use FlintPHP\Framework\Console\CommandInterface;
use FlintPHP\Framework\Console\InputInterface;
use FlintPHP\Framework\Console\OutputInterface;

/**
 * key:generate [--user-id=] [--permissions=read|write|read_write] [--description=]
 *
 * Prints the ck_/cs_ pair once. Only the hash of the key is stored.
 */
final class KeyGenerateCommand implements CommandInterface
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
    ) {
    }

    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Generate a WooCommerce-style API key pair (shown once)';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $permissions = $input->option('permissions') ?? 'read_write';

        if (!in_array($permissions, ['read', 'write', 'read_write'], true)) {
            $output->writeErrorLn('Permissions must be read, write or read_write.');

            return 1;
        }

        $userId = null;
        $userIdRaw = $input->option('user-id');

        if ($userIdRaw !== null && $userIdRaw !== '' && preg_match('/\A\d+\z/', $userIdRaw) === 1) {
            $userId = (int) $userIdRaw;
        }

        $description = $input->option('description') ?? 'API key';

        [, $consumerKey, $consumerSecret] = $this->keys->issue($userId, $permissions, $description);

        $output->writeLn('API key generated (permissions: ' . $permissions . ', description: ' . $description . ')');
        $output->writeLn('');
        $output->writeLn('consumer_key:    ' . $consumerKey);
        $output->writeLn('consumer_secret: ' . $consumerSecret);
        $output->writeLn('');
        $output->writeLn('Store this pair now — the secret cannot be retrieved again.');
        $output->writeLn('Usage: curl -u "' . $consumerKey . ':' . $consumerSecret . '" https://.../api/fc/v3/products');

        return 0;
    }
}
