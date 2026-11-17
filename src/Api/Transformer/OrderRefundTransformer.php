<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\OrderRefund;

final class OrderRefundTransformer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $dp = 2,
    ) {
    }

    public function transform(\App\Domain\Model\OrderRefund $refund): array
    {
        $refundUrl = $this->baseUrl . "/api/fc/v3/orders/{$refund->order_id}/refunds/{$refund->id}";

        return [
            'id' => (int) $refund->id,
            'order_id' => (int) $refund->order_id,
            'amount' => number_format((float) $refund->amount, $this->dp, '.', ''),
            'reason' => (string) $refund->reason,
            'status' => (string) $refund->status,
            'refunded_by' => $refund->refunded_by !== null ? (int) $refund->refunded_by : null,
            'refunded_via' => (string) $refund->refunded_via,
            'date_created' => $this->formatDate($refund->date_created),
            'date_created_gmt' => $this->formatDate($refund->date_created_gmt),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}