<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Domain\Repository\WebhookRepository;
use App\Domain\Model\Webhook;
use App\Domain\Model\WebhookDelivery;
use FlintPHP\Framework\Database\ConnectionInterface;

final class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookRepository $webhooks,
        private readonly ConnectionInterface $connection,
        private readonly int $maxAttempts = 5,
        private readonly int $baseDelayMs = 1000,
        private readonly int $maxDelayMs = 300000,
        private readonly int $timeoutMs = 10000,
    ) {
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function dispatch(WebhookDelivery $delivery, Webhook $webhook): void
    {
        $startTime = microtime(true);

        $payload = json_encode($delivery->request_body ?? new \stdClass(), JSON_THROW_ON_ERROR);
        $headers = $this->buildHeaders($webhook, $payload);

        $responseCode = 0;
        $responseHeaders = [];
        $responseBody = '';
        $errorMessage = null;

        try {
            $ch = curl_init($delivery->request_url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $delivery->request_method,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
            ]);

            $rawResponse = curl_exec($ch);
            $curlError = curl_error($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($rawResponse === false) {
                throw new \RuntimeException($curlError !== '' ? $curlError : 'cURL request failed');
            }

            assert(is_string($rawResponse));
            $parts = explode("\r\n\r\n", $rawResponse, 2);
            $headerString = $parts[0] ?? '';
            $body = $parts[1] ?? '';
            $responseBody = $body;
            $responseHeaders = $this->parseHeaders($headerString);

        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $responseCode = 0;
            $responseHeaders = [];
            $responseBody = '';
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->webhooks->updateDelivery($delivery->id, [
            'response_code' => (string) $responseCode,
            'response_headers' => json_encode($responseHeaders, JSON_THROW_ON_ERROR),
            'response_body' => $responseBody,
            'duration_ms' => $durationMs,
            'status' => $responseCode >= 200 && $responseCode < 300 ? 'delivered' : 'failed',
            'error_message' => $errorMessage,
            'attempts' => $delivery->attempts + 1,
            'last_attempt_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function scheduleRetry(WebhookDelivery $delivery): int
    {
        $delayMs = $this->calculateBackoff($delivery->attempts);
        $retryAt = gmdate('Y-m-d H:i:s', time() + (int) ($delayMs / 1000));

        $this->webhooks->updateDelivery($delivery->id, [
            'status' => 'retrying',
            'error_message' => 'Scheduled for retry',
            'last_attempt_at' => $retryAt,
        ]);

        return $delayMs;
    }

    private function calculateBackoff(int $attempt): int
    {
        $delay = $this->baseDelayMs * (2 ** ($attempt - 1));
        $jitter = (int) ($delay * 0.1 * (mt_rand(-100, 100) / 100));
        $delay = max($this->baseDelayMs, min($this->maxDelayMs, $delay + $jitter));
        return $delay;
    }

    private function buildHeaders(Webhook $webhook, string $payload): array
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $webhook->secret);

        return [
            'Content-Type: application/json',
            'User-Agent: WooCommerce-Webhook/3.0',
            'X-WC-Webhook-Signature: ' . $signature,
            'X-WC-Webhook-Timestamp: ' . $timestamp,
            'X-WC-Webhook-ID: ' . $webhook->id,
            'X-WC-Webhook-Topic: ' . $webhook->topic,
        ];
    }

    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        foreach (explode("\r\n", $headerString) as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        return $headers;
    }
}