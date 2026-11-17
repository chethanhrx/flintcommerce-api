<?php

declare(strict_types=1);

namespace App\Api\Exception;

use RuntimeException;
use Throwable;

/**
 * WooCommerce v3-compatible API error.
 *
 * Wire format: {"code":"woocommerce_rest_cannot_view","message":"...","data":{"status":401}}
 */
final class WcApiException extends RuntimeException
{
    /** @var array<string, string>|null */
    private ?array $details = null;

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Attach per-field details (emitted under data.params, WC-style). */
    public function withDetails(array $details): self
    {
        $this->details = $details;

        return $this;
    }

    /** @return array<string, string>|null */
    public function details(): ?array
    {
        return $this->details;
    }

    /** Standard WC v3 error presets. */
    public static function cannotView(string $message = 'Sorry, you cannot view this resource.'): self
    {
        return new self('woocommerce_rest_cannot_view', $message, 401);
    }

    public static function cannotEdit(string $message = 'Sorry, you cannot edit this resource.'): self
    {
        return new self('woocommerce_rest_cannot_edit', $message, 401);
    }

    public static function cannotDelete(string $message = 'Sorry, you cannot delete this resource.'): self
    {
        return new self('woocommerce_rest_cannot_delete', $message, 401);
    }

    public static function invalidParam(array $details = []): self
    {
        return (new self(
            'rest_invalid_param',
            'Invalid parameter(s): ' . implode(', ', array_keys($details)),
            400,
        ))->withDetails($details);
    }

    public static function missingParam(array $details = []): self
    {
        return (new self(
            'rest_missing_callback_param',
            'Missing parameter(s): ' . implode(', ', array_keys($details)),
            400,
        ))->withDetails($details);
    }

    public static function notFound(string $message = 'No route was found matching the URL and request method.'): self
    {
        return new self('rest_no_route', $message, 404);
    }
}
