<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\Coupon;

final class CouponTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(Coupon $coupon): array
    {
        $couponUrl = $this->baseUrl . "/api/fc/v3/coupons/{$coupon->id}";

        return [
            'id' => (int) $coupon->id,
            'code' => (string) $coupon->code,
            'amount' => (string) $coupon->amount,
            'date_created' => $this->formatDate($coupon->date_created),
            'date_created_gmt' => $this->formatDate($coupon->date_created_gmt),
            'date_modified' => $this->formatDate($coupon->date_modified),
            'date_modified_gmt' => $this->formatDate($coupon->date_modified_gmt),
            'discount_type' => (string) $coupon->discount_type,
            'description' => (string) $coupon->description,
            'date_expires' => $this->formatDate($coupon->date_expires),
            'date_expires_gmt' => $this->formatDate($coupon->date_expires_gmt),
            'usage_count' => (int) $coupon->usage_count,
            'individual_use' => (bool) $coupon->individual_use,
            'product_ids' => $this->decodeIntArray($coupon->product_ids),
            'excluded_product_ids' => $this->decodeIntArray($coupon->excluded_product_ids),
            'usage_limit' => $coupon->usage_limit !== null ? (int) $coupon->usage_limit : null,
            'usage_limit_per_user' => $coupon->usage_limit_per_user !== null ? (int) $coupon->usage_limit_per_user : null,
            'limit_usage_to_x_items' => $coupon->limit_usage_to_x_items !== null ? (int) $coupon->limit_usage_to_x_items : null,
            'free_shipping' => (bool) $coupon->free_shipping,
            'product_categories' => $this->decodeIntArray($coupon->product_categories),
            'excluded_product_categories' => $this->decodeIntArray($coupon->excluded_product_categories),
            'exclude_sale_items' => (bool) $coupon->exclude_sale_items,
            'minimum_amount' => $coupon->minimum_amount !== null ? (string) $coupon->minimum_amount : null,
            'maximum_amount' => $coupon->maximum_amount !== null ? (string) $coupon->maximum_amount : null,
            'email_restrictions' => $this->decodeStringArray($coupon->email_restrictions),
            'used_by' => $this->decodeIntArray($coupon->used_by),
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

    private function decodeIntArray(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_map('intval', $decoded);
    }

    private function decodeStringArray(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_map('strval', $decoded);
    }
}