<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\Order;
use App\Domain\Model\OrderItem;

final class OrderTransformer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $dp,
    ) {
    }

    public function transform(Order $order, array $items = []): array
    {
        $orderUrl = $this->baseUrl . "/api/fc/v3/orders/{$order->id}";

        $billing = $this->formatAddress($order, 'billing');
        $shipping = $this->formatAddress($order, 'shipping');

        $itemsData = array_map(fn (OrderItem $item): array => $this->transformItem($item), $items);

        return [
            'id' => (int) $order->id,
            'parent_id' => (int) $order->parent_id,
            'status' => (string) $order->status,
            'currency' => (string) $order->currency,
            'version' => (string) $order->version,
            'prices_include_tax' => (bool) $order->prices_include_tax,
            'date_created' => $this->formatDate($order->date_created),
            'date_created_gmt' => $this->formatDate($order->date_created_gmt),
            'date_modified' => $this->formatDate($order->date_modified),
            'date_modified_gmt' => $this->formatDate($order->date_modified_gmt),
            'discount_total' => $this->formatPrice($order->discount_total),
            'discount_tax' => $this->formatPrice($order->discount_tax),
            'shipping_total' => $this->formatPrice($order->shipping_total),
            'shipping_tax' => $this->formatPrice($order->shipping_tax),
            'cart_tax' => $this->formatPrice($order->cart_tax),
            'total' => $this->formatPrice($order->total),
            'total_tax' => $this->formatPrice($order->total_tax),
            'customer_id' => (int) $order->customer_id,
            'order_key' => (string) $order->order_key,
            'billing' => $billing,
            'shipping' => $shipping,
            'payment_method' => (string) $order->payment_method,
            'payment_method_title' => (string) $order->payment_method_title,
            'transaction_id' => (string) $order->transaction_id,
            'customer_ip_address' => (string) $order->customer_ip_address,
            'customer_user_agent' => (string) $order->customer_user_agent,
            'created_via' => (string) $order->created_via,
            'date_completed' => $this->formatDate($order->completed_at),
            'date_completed_gmt' => $this->formatDate($order->completed_at_gmt),
            'date_paid' => $this->formatDate($order->paid_at),
            'date_paid_gmt' => $this->formatDate($order->paid_at_gmt),
            'coupon_codes' => $this->decodeArray($order->coupon_codes),
            'line_items' => $itemsData,
            'tax_lines' => [],
            'shipping_lines' => [],
            'fee_lines' => [],
            'coupon_lines' => [],
        ];
    }

    public function transformItem(OrderItem $item): array
    {
        $itemUrl = $this->baseUrl . "/api/fc/v3/orders/{$item->order_id}/line_items/{$item->id}";

        return [
            'id' => (int) $item->id,
            'name' => (string) $item->name,
            'product_id' => (int) $item->product_id,
            'variation_id' => (int) $item->variation_id,
            'quantity' => (int) $item->quantity,
            'tax_class' => (string) $item->tax_class,
            'subtotal' => $this->formatPrice($item->subtotal),
            'subtotal_tax' => $this->formatPrice($item->subtotal_tax),
            'total' => $this->formatPrice($item->total),
            'total_tax' => $this->formatPrice($item->total_tax),
            'taxes' => [],
            'meta_data' => $this->decodeMeta($item->meta_data),
            'sku' => (string) $item->sku,
            'price' => $this->formatPrice($item->price),
            'image' => $this->decodeImage($item->image),
            'parent_item_id' => (int) $item->parent_item_id,
        ];
    }

    private function formatPrice(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $this->dp, '.', '');
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

    private function formatAddress(Order $order, string $prefix): array
    {
        return [
            'first_name' => (string) ($order->{$prefix . '_first_name'} ?? ''),
            'last_name' => (string) ($order->{$prefix . '_last_name'} ?? ''),
            'company' => (string) ($order->{$prefix . '_company'} ?? ''),
            'address_1' => (string) ($order->{$prefix . '_address_1'} ?? ''),
            'address_2' => (string) ($order->{$prefix . '_address_2'} ?? ''),
            'city' => (string) ($order->{$prefix . '_city'} ?? ''),
            'state' => (string) ($order->{$prefix . '_state'} ?? ''),
            'postcode' => (string) ($order->{$prefix . '_postcode'} ?? ''),
            'country' => (string) ($order->{$prefix . '_country'} ?? ''),
            'email' => $prefix === 'billing' ? (string) ($order->billing_email ?? '') : '',
            'phone' => $prefix === 'billing' ? (string) ($order->billing_phone ?? '') : (string) ($order->{$prefix . '_phone'} ?? ''),
        ];
    }

    private function decodeArray(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function decodeMeta(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $result[] = [
                'id' => (int) ($meta['id'] ?? 0),
                'key' => (string) ($meta['key'] ?? ''),
                'value' => $meta['value'] ?? '',
                'display_key' => (string) ($meta['display_key'] ?? ''),
                'display_value' => $meta['display_value'] ?? '',
            ];
        }

        return $result;
    }

    private function decodeImage(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return [
            'id' => (int) ($decoded['id'] ?? 0),
            'src' => (string) ($decoded['src'] ?? ''),
            'name' => (string) ($decoded['name'] ?? ''),
            'alt' => (string) ($decoded['alt'] ?? ''),
        ];
    }
}