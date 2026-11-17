<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Webhook\WebhookDispatcher;
use App\Domain\Repository\WebhookRepository;
use FlintPHP\Framework\Console\CommandInterface;
use FlintPHP\Framework\Console\InputInterface;
use FlintPHP\Framework\Console\ParsedInputInterface;
use FlintPHP\Framework\Console\OutputInterface;

final class WebhookWorkerCommand implements CommandInterface
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly WebhookRepository $repository,
    ) {
    }

    public function name(): string
    {
        return 'webhook:worker';
    }

    public function description(): string
    {
        return 'Process pending webhook deliveries with exponential backoff';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input instanceof ParsedInputInterface) {
            $output->writeErrorLn('This command requires parsed input (use bin/flint).');
            return 1;
        }
        $batchSize = (int) ($input->option('batch') ?? 10);
        $batchDelayMs = (int) ($input->option('delay') ?? 500);
        $maxRuntime = (int) ($input->option('max-runtime') ?? 0);
        $runOnce = $input->hasOption('once');

        $startTime = time();
        $processed = 0;
        $delivered = 0;
        $failed = 0;
        $retried = 0;

        $output->writeLn('<info>Starting webhook worker...</info>');
        $output->writeLn(sprintf('Batch size: %d, Delay: %dms, Max runtime: %ds', $batchSize, $batchDelayMs, $maxRuntime));

        while (true) {
            if ($maxRuntime > 0 && (time() - $startTime) >= $maxRuntime) {
                $output->writeLn('<comment>Max runtime reached, stopping.</comment>');
                break;
            }

            $deliveries = $this->repository->getPendingDeliveries($batchSize);

            if ($deliveries === []) {
                $output->writeLn('<comment>No pending deliveries, waiting...</comment>');
                if ($runOnce) {
                    break;
                }
                usleep($batchDelayMs * 1000);
                continue;
            }

            foreach ($deliveries as $delivery) {
                $webhook = $this->repository->find($delivery->webhook_id);
                if ($webhook === null || $webhook->status !== 'active') {
                    $this->repository->updateDelivery($delivery->id, [
                        'status' => 'failed',
                        'error_message' => 'Webhook not found or inactive',
                    ]);
                    $failed++;
                    continue;
                }

                $output->write(sprintf('Processing delivery #%d (webhook: %s, attempt %d)... ',
                    $delivery->id, $webhook->name, $delivery->attempts));

                try {
                    $this->dispatcher->dispatch($delivery, $webhook);
                    $processed++;

                    // Re-fetch to check status
                    $updated = $this->repository->findDelivery($delivery->id);
                    if ($updated !== null) {
                        if ($updated->status === 'delivered') {
                            $delivered++;
                            $output->writeLn('<info>DELIVERED</info>');
                        } elseif ($updated->status === 'failed') {
                            if ($updated->attempts >= $this->dispatcher->getMaxAttempts()) {
                                $failed++;
                                $output->writeLn('<error>FAILED (max attempts reached)</error>');
                            } else {
                                $retried++;
                                $delayMs = $this->dispatcher->scheduleRetry($updated);
                                $output->writeLn(sprintf('<comment>RETRY in %dms (attempt %d/%d)</comment>',
                                    $delayMs, $updated->attempts + 1, $this->dispatcher->getMaxAttempts()));
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $output->writeLn('<error>EXCEPTION: ' . $e->getMessage() . '</error>');
                    $this->repository->updateDelivery($delivery->id, [
                        'status' => 'failed',
                        'error_message' => 'Worker exception: ' . $e->getMessage(),
                        'attempts' => $delivery->attempts + 1,
                        'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
            }

            if ($runOnce) {
                break;
            }

            usleep($batchDelayMs * 1000);
        }

        $output->writeLn('');
        $output->writeLn('<info>Worker finished:</info>');
        $output->writeLn(sprintf('  Processed: %d', $processed));
        $output->writeLn(sprintf('  Delivered: %d', $delivered));
        $output->writeLn(sprintf('  Failed: %d', $failed));
        $output->writeLn(sprintf('  Retried: %d', $retried));

        return 0;
    }
}