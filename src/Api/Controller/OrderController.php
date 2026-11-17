<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Api\Response\WcResponse;
use App\Api\Transformer\OrderTransformer;
use App\Domain\Repository\CouponRepository;
use App\Domain\Repository\OrderRepository;
use App\Domain\Repository\PaymentGatewayRepository;
use App\Domain\Repository\ProductRepository;
use App\Domain\Repository\TaxRateRepository;
use App\Domain\Repository\TaxClassRepository;
use App\Domain\Repository\ProductVariationRepository;
use App\Domain\Repository\CustomerRepository;
use App\Shipping\ShippingCalculator;
use App\Tax\TaxCalculator;
use App\Support\BcMath;
use App\Support\Pagination;
use App\Support\Sql;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class OrderController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ProductRepository $products,
        private readonly ProductVariationRepository $variations,
        private readonly CouponRepository $coupons,
        private readonly TaxRateRepository $taxRates,
        private readonly TaxClassRepository $taxClasses,
        private readonly CustomerRepository $customers,
        private readonly PaymentGatewayRepository $gateways,
        private readonly OrderTransformer $transformer,
        private readonly TaxCalculator $taxCalculator,
        private readonly ShippingCalculator $shippingCalculator,
        private readonly string $baseUrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $pagination = Pagination::fromQuery($query);
        $filters = $this->orders->normalizeFilters($query);

        $total = $this->orders->count($filters);
        $items = $this->orders->list($filters, $pagination->limit(), $pagination->offset);

        $payload = array_map(
            fn ($o): array => $this->transformer->transform($o, $this->orders->getItems((int) $o->id)),
            $items,
        );

        return WcResponse::collection(
            $payload,
            200,
            $pagination,
            $total,
            $this->pageUrl($request, $pagination, $total, +1),
            $this->pageUrl($request, $pagination, $total, -1),
        );
    }

    public function show(Request $request, int $id): Response
    {
        $order = $this->orders->find($id);

        if ($order === null) {
            throw new WcApiException(
                'woocommerce_rest_order_invalid_id',
                'Invalid ID.',
                404,
            );
        }

        return Response::json(
            $this->transformer->transform($order, $this->orders->getItems($id)),
            200,
        );
    }

    public function store(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['line_items']) || !is_array($payload['line_items']) || $payload['line_items'] === []) {
            $missing['line_items'] = 'At least one line item is required.';
        }

        if (!isset($payload['billing']) || !is_array($payload['billing'])) {
            $missing['billing'] = 'billing is required.';
        } elseif (
            !isset($payload['billing']['first_name']) ||
            !isset($payload['billing']['last_name']) ||
            !isset($payload['billing']['address_1']) ||
            !isset($payload['billing']['city']) ||
            !isset($payload['billing']['state']) ||
            !isset($payload['billing']['postcode']) ||
            !isset($payload['billing']['country']) ||
            !isset($payload['billing']['email'])
        ) {
            $missing['billing'] = 'billing must include first_name, last_name, address_1, city, state, postcode, country, and email.';
        }

        if (!isset($payload['shipping']) || !is_array($payload['shipping'])) {
            $missing['shipping'] = 'shipping is required.';
        } elseif (
            !isset($payload['shipping']['first_name']) ||
            !isset($payload['shipping']['last_name']) ||
            !isset($payload['shipping']['address_1']) ||
            !isset($payload['shipping']['city']) ||
            !isset($payload['shipping']['state']) ||
            !isset($payload['shipping']['postcode']) ||
            !isset($payload['shipping']['country'])
        ) {
            $missing['shipping'] = 'shipping must include first_name, last_name, address_1, city, state, postcode, country.';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $normalized = $this->normalizeCreate($payload);
        $order = $this->createOrder($normalized);

        return Response::json(
            $this->transformer->transform($order, $this->orders->getItems((int) $order->id)),
            201,
        );
    }

    public function update(Request $request, int $id): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $normalized = $this->normalizeUpdate($payload);
        $order = $this->orders->update($id, $normalized);

        return Response::json(
            $this->transformer->transform($order, $this->orders->getItems($id)),
            200,
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];
        $force = self::booleanParam($query['force'] ?? false, 'force');

        [$order, $deleted] = $this->orders->delete($id, $force);
        $data = $this->transformer->transform($order, $this->orders->getItems((int) $order->id));

        if ($deleted) {
            return Response::json([
                'deleted' => true,
                'previous' => $data,
            ], 200);
        }

        return Response::json($data, 200);
    }

    public function batch(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $result = [
            'create' => [],
            'update' => [],
            'delete' => [],
        ];

        $created = $payload['create'] ?? [];
        if (!is_array($created)) {
            throw WcApiException::invalidParam(['create' => 'create must be an array']);
        }
        foreach (array_values($created) as $item) {
            $item = is_array($item) ? $item : [];
            $missing = [];
            if (!isset($item['line_items']) || !is_array($item['line_items']) || $item['line_items'] === []) {
                $missing['line_items'] = 'At least one line item is required.';
            }
            if (!isset($item['billing']) || !is_array($item['billing'])) {
                $missing['billing'] = 'billing is required.';
            }
            if (!isset($item['shipping']) || !is_array($item['shipping'])) {
                $missing['shipping'] = 'shipping is required.';
            }
            if ($missing !== []) {
                throw WcApiException::missingParam($missing);
            }
            $order = $this->createOrder($this->normalizeCreate($item));
            $result['create'][] = $this->transformer->transform($order, $this->orders->getItems((int) $order->id));
        }

        $updated = $payload['update'] ?? [];
        if (!is_array($updated)) {
            throw WcApiException::invalidParam(['update' => 'update must be an array']);
        }
        foreach (array_values($updated) as $i => $item) {
            $item = is_array($item) ? $item : [];
            if (!isset($item['id']) || preg_match('/\A\d+\z/', (string) $item['id']) !== 1) {
                throw WcApiException::invalidParam(["update[$i]" => 'update requires a valid id']);
            }
            $order = $this->orders->update((int) $item['id'], $this->normalizeUpdate($item));
            $result['update'][] = $this->transformer->transform($order, $this->orders->getItems((int) $order->id));
        }

        $deleted = $payload['delete'] ?? [];
        if (!is_array($deleted)) {
            throw WcApiException::invalidParam(['delete' => 'delete must be an array']);
        }
        foreach (array_values($deleted) as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? null;
            }
            if (!is_numeric($item) || preg_match('/\A\d+\z/', (string) $item) !== 1) {
                throw WcApiException::invalidParam(['delete' => 'delete must contain valid ids']);
            }
            [$order, $wasDeleted] = $this->orders->delete((int) $item, true);
            $result['delete'][] = [
                'deleted' => true,
                'previous' => $this->transformer->transform($order, $this->orders->getItems((int) $order->id)),
            ];
            unset($wasDeleted);
        }

        return Response::json($result, 200);
    }

    private function createOrder(array $payload): \App\Domain\Model\Order
    {
        $now = gmdate('Y-m-d H:i:s');
        $orderKey = 'wc_order_' . bin2hex(random_bytes(8));

        // Validate and prepare line items
        $lineItems = $this->prepareLineItems($payload['line_items']);

        // Calculate totals
        $totals = $this->calculateTotals($lineItems, $payload);

        // Apply coupons
        $couponDiscounts = $this->applyCoupons($payload['coupon_lines'] ?? [], $lineItems, $totals);

        // Calculate tax
        $taxTotals = $this->taxCalculator->calculate($lineItems, $payload);

        // Calculate shipping
        $shippingTotal = $this->shippingCalculator->calculate(
            $lineItems,
            $payload['shipping'] ?? $payload['billing'] ?? [],
            (float) ($totals['subtotal'] ?? 0)
        );

        // Calculate shipping tax
        $shippingTaxClassId = (int) ($payload['shipping_tax_class_id'] ?? 0);
        $shippingAddress = $payload['shipping'] ?? $payload['billing'] ?? [];
        $shippingTax = $this->taxCalculator->calculateShippingTax(
            (float) $shippingTotal,
            $shippingTaxClassId,
            $shippingAddress
        );

        $finalTotal = BcMath::add(
            BcMath::add($totals['subtotal'], $taxTotals['total_tax'], 2),
            BcMath::add($shippingTotal, $totals['fee_total'] ?? '0.00', 2),
            2,
        );
        $finalTotal = BcMath::sub($finalTotal, $couponDiscounts['total'], 2);

        return $this->orders->getConnection()->transaction(function () use ($payload, $now, $orderKey, $lineItems, $totals, $taxTotals, $shippingTotal, $shippingTax, $finalTotal): \App\Domain\Model\Order {
            $this->orders->getConnection()->execute(
                'INSERT INTO orders (
                    parent_id, status, currency, version, prices_include_tax,
                    date_created, date_created_gmt, date_modified, date_modified_gmt,
                    discount_total, discount_tax, shipping_total, shipping_tax,
                    cart_tax, total, total_tax, customer_id, order_key,
                    billing_first_name, billing_last_name, billing_company,
                    billing_address_1, billing_address_2, billing_city, billing_state,
                    billing_postcode, billing_country, billing_email, billing_phone,
                    shipping_first_name, shipping_last_name, shipping_company,
                    shipping_address_1, shipping_address_2, shipping_city, shipping_state,
                    shipping_postcode, shipping_country, shipping_phone,
                    payment_method, payment_method_title, transaction_id,
                    customer_ip_address, customer_user_agent, created_via,
                    coupon_codes, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $payload['parent_id'] ?? 0,
                    $payload['status'] ?? 'pending',
                    $payload['currency'] ?? 'USD',
                    $payload['version'] ?? 'fc/v3',
                    (int) ($payload['prices_include_tax'] ?? 0),
                    $now,
                    $now,
                    $now,
                    $now,
                    $totals['discount_total'] ?? '0.00',
                    '0.00',
                    $shippingTotal,
                    $shippingTax,
                    $taxTotals['cart_tax'] ?? '0.00',
                    $finalTotal,
                    $taxTotals['total'] ?? '0.00',
                    $payload['customer_id'] ?? 0,
                    $orderKey,
                    $payload['billing']['first_name'] ?? '',
                    $payload['billing']['last_name'] ?? '',
                    $payload['billing']['company'] ?? null,
                    $payload['billing']['address_1'] ?? '',
                    $payload['billing']['address_2'] ?? null,
                    $payload['billing']['city'] ?? '',
                    $payload['billing']['state'] ?? '',
                    $payload['billing']['postcode'] ?? '',
                    $payload['billing']['country'] ?? '',
                    $payload['billing']['email'] ?? '',
                    $payload['billing']['phone'] ?? null,
                    $payload['shipping']['first_name'] ?? '',
                    $payload['shipping']['last_name'] ?? '',
                    $payload['shipping']['company'] ?? null,
                    $payload['shipping']['address_1'] ?? '',
                    $payload['shipping']['address_2'] ?? null,
                    $payload['shipping']['city'] ?? '',
                    $payload['shipping']['state'] ?? '',
                    $payload['shipping']['postcode'] ?? '',
                    $payload['shipping']['country'] ?? '',
                    $payload['shipping']['phone'] ?? null,
                    $payload['payment_method'] ?? null,
                    $payload['payment_method_title'] ?? null,
                    $payload['transaction_id'] ?? null,
                    $payload['customer_ip_address'] ?? null,
                    $payload['customer_user_agent'] ?? null,
                    $payload['created_via'] ?? 'wc-api',
                    json_encode($payload['coupon_lines'] ?? []),
                    $now,
                    $now,
                ],
            );

            $id = (int) $this->orders->getConnection()->fetchColumn(
                'SELECT id FROM orders WHERE order_key = ?',
                [$orderKey]
            );

            $order = $this->orders->find($id);
            if ($order === null) {
                throw new \RuntimeException('Order insert failed.');
            }

            // Update line items with the correct order_id
            foreach ($lineItems as &$item) {
                $item['order_id'] = $id;
            }

            // Insert line items
            foreach ($lineItems as $item) {
                $this->orders->getConnection()->execute(
                    'INSERT INTO order_items (
                        order_id, name, product_id, variation_id, quantity,
                        tax_class, subtotal, subtotal_tax, total, total_tax,
                        tax_class_id, tax_data, meta_data, sku, price,
                        image, parent_item_id, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $item['order_id'],
                        $item['name'],
                        $item['product_id'],
                        $item['variation_id'],
                        $item['quantity'],
                        $item['tax_class'] ?? '',
                        $item['subtotal'],
                        $item['subtotal_tax'],
                        $item['total'],
                        $item['total_tax'],
                        $item['tax_class_id'] ?? 0,
                        $item['tax_data'] ?? null,
                        $item['meta_data'] ?? null,
                        $item['sku'] ?? '',
                        $item['price'],
                        $item['image'] ?? null,
                        $item['parent_item_id'] ?? 0,
                        gmdate('Y-m-d H:i:s'),
                        gmdate('Y-m-d H:i:s'),
                    ],
                );
            }

            // Update stock
            $this->updateStock($lineItems);

            $order = $this->orders->find($id);
            if ($order === null) {
                throw new \RuntimeException('Order insert failed.');
            }
            return $order;
        });
    }

    private function prepareLineItems(array $items): array
    {
        $lineItems = [];
        $sequence = 0;

        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];

            $productId = (int) ($item['product_id'] ?? 0);
            $variationId = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;
            $quantity = (int) ($item['quantity'] ?? 1);

            if ($productId <= 0) {
                throw WcApiException::invalidParam(['line_items' => 'product_id is required for each line item']);
            }

            $product = $this->products->find($productId);
            if ($product === null) {
                throw WcApiException::invalidParam(['line_items' => "Product $productId does not exist"]);
            }

            if ($variationId > 0) {
                $variation = $this->variations->find($productId, $variationId);
                if ($variation === null) {
                    throw WcApiException::invalidParam(['line_items' => "Variation $variationId does not exist for product $productId"]);
                }
                $name = $variation->name;
                $price = $variation->price !== '' ? $variation->price : $variation->regular_price;
                $taxClass = $variation->tax_class ?? $product->tax_class;
                $taxClassId = $variation->tax_class_id ?? $product->tax_class_id;
                $sku = $variation->sku ?? $product->sku;
            } else {
                $name = $product->name;
                $price = $product->price !== '' ? $product->price : $product->regular_price;
                $taxClass = $product->tax_class;
                $taxClassId = $product->tax_class_id;
                $sku = $product->sku;
            }

            $subtotal = BcMath::mul($price, (string) $quantity, 2);

            $lineItems[] = [
                'order_id' => 0, // will be set after order creation
                'name' => $name,
                'product_id' => $productId,
                'variation_id' => $variationId,
                'quantity' => $quantity,
                'tax_class' => $taxClass,
                'subtotal' => $subtotal,
                'subtotal_tax' => '0.00', // calculated later
                'total' => $subtotal,
                'total_tax' => '0.00', // calculated later
                'tax_class_id' => $taxClassId,
                'tax_data' => null,
                'meta_data' => null,
                'sku' => $sku,
                'price' => $price,
                'image' => null,
                'parent_item_id' => 0,
            ];
        }

        return $lineItems;
    }

    private function calculateTotals(array $lineItems, array $payload): array
    {
        $subtotal = '0.00';
        $discountTotal = '0.00';
        $feeTotal = '0.00';

        foreach ($lineItems as $item) {
            $subtotal = BcMath::add($subtotal, $item['subtotal'], 2);
        }

        // Apply coupon discounts (simplified)
        $couponLines = $payload['coupon_lines'] ?? [];
        foreach ($couponLines as $couponLine) {
            if (!is_array($couponLine)) continue;
            $discountTotal = BcMath::add($discountTotal, $couponLine['total'] ?? '0.00', 2);
        }

        // Fee lines
        $feeLines = $payload['fee_lines'] ?? [];
        foreach ($feeLines as $feeLine) {
            if (!is_array($feeLine)) continue;
            $feeTotal = BcMath::add($feeTotal, $feeLine['total'] ?? '0.00', 2);
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'fee_total' => $feeTotal,
        ];
    }

    private function applyCoupons(array $couponLines, array $lineItems, array $totals): array
    {
        $totalDiscount = '0.00';

        foreach ($couponLines as $couponLine) {
            if (!is_array($couponLine)) continue;

            $coupon = $this->coupons->findByCode($couponLine['code'] ?? '');
            if ($coupon === null) continue;

            $discount = '0.00';
            $amount = (float) ($coupon->amount ?? 0);

            switch ($coupon->discount_type) {
                case 'fixed_cart':
                    $discount = min($coupon->amount, $totals['subtotal']);
                    break;
                case 'percent':
                    $discount = BcMath::mul($totals['subtotal'], BcMath::div($coupon->amount, '100', 4), 2);
                    break;
                case 'fixed_product':
                case 'percent_product':
                    // Per-product discount - simplified
                    $discount = min($coupon->amount, $totals['subtotal']);
                    break;
            }

            $discount = \min($discount, $totals['subtotal']);
            $totalDiscount = BcMath::add($totalDiscount, $discount, 2);
        }

        return ['total' => $totalDiscount];
    }

    private function updateStock(array $lineItems): void
    {
        foreach ($lineItems as $item) {
            if ($item['variation_id'] > 0) {
                $variation = $this->variations->find($item['product_id'], $item['variation_id']);
                if ($variation !== null && (int) $variation->manage_stock === 1 && $variation->stock_quantity !== null) {
                    $newStock = max(0, (int) $variation->stock_quantity - $item['quantity']);
                    $this->variations->getConnection()->execute(
                        'UPDATE product_variations SET stock_quantity = ?, stock_status = ? WHERE id = ? AND product_id = ?',
                        [
                            $newStock,
                            $newStock > 0 ? 'instock' : 'outofstock',
                            $item['variation_id'],
                            $item['product_id'],
                        ],
                    );
                }
            } else {
                $product = $this->products->find($item['product_id']);
                if ($product !== null && (int) $product->manage_stock === 1 && $product->stock_quantity !== null) {
                    $newStock = max(0, (int) $product->stock_quantity - $item['quantity']);
                    $this->products->getConnection()->execute(
                        'UPDATE products SET stock_quantity = ?, stock_status = ? WHERE id = ?',
                        [
                            $newStock,
                            $newStock > 0 ? 'instock' : 'outofstock',
                            $item['product_id'],
                        ],
                    );
                }
            }
        }
    }

    /**


     * @param array<string, mixed> $payload


     * @return array<string, mixed>


     */


    private function normalizeCreate(array $payload): array
    {
        $errors = [];

        // Validate billing
        foreach (['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country', 'email'] as $field) {
            if (!isset($payload['billing'][$field]) || !is_string($payload['billing'][$field]) || trim($payload['billing'][$field]) === '') {
                $errors["billing.$field"] = "$field is required.";
            }
        }

        if (isset($payload['billing']['email']) && !filter_var($payload['billing']['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['billing.email'] = 'A valid email address is required.';
        }

        // Validate shipping
        foreach (['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country'] as $field) {
            if (!isset($payload['shipping'][$field]) || !is_string($payload['shipping'][$field]) || trim($payload['shipping'][$field]) === '') {
                $errors["shipping.$field"] = "$field is required.";
            }
        }

        // Validate line items
        if (!isset($payload['line_items']) || !is_array($payload['line_items']) || $payload['line_items'] === []) {
            $errors['line_items'] = 'At least one line item is required.';
        } else {
            foreach ($payload['line_items'] as $i => $item) {
                $item = is_array($item) ? $item : [];
                if (!isset($item['product_id']) || !is_numeric($item['product_id']) || (int) $item['product_id'] <= 0) {
                    $errors["line_items[$i].product_id"] = 'product_id is required and must be a positive integer.';
                }
                if (isset($item['quantity']) && (!is_int($item['quantity']) || (int) $item['quantity'] <= 0)) {
                    $errors["line_items[$i].quantity"] = 'quantity must be a positive integer.';
                }
            }
        }

        // Validate coupons
        if (isset($payload['coupon_lines']) && is_array($payload['coupon_lines'])) {
            foreach ($payload['coupon_lines'] as $i => $coupon) {
                $coupon = is_array($coupon) ? $coupon : [];
                if (!isset($coupon['code']) || !is_string($coupon['code']) || trim($coupon['code']) === '') {
                    $errors["coupon_lines[$i].code"] = 'code is required.';
                }
            }
        }

        // Validate payment method against registered gateways
        $this->resolvePaymentMethod($payload);

        if ($errors !== []) {
            throw WcApiException::invalidParam($errors);
        }

        return $payload;
    }

    /**


     * @param array<string, mixed> $payload


     * @return array<string, mixed>


     */


    private function normalizeUpdate(array $payload): array
    {
        // For updates, allow partial updates
        $errors = [];

        if (array_key_exists('payment_method', $payload)) {
            $this->resolvePaymentMethod($payload);
        }

        if (isset($payload['status'])) {
            if (!in_array($payload['status'], ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'trash'], true)) {
                $errors['status'] = 'status is not a valid option.';
            }
        }

        if (isset($payload['billing']) && is_array($payload['billing'])) {
            foreach (['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country', 'email'] as $field) {
                if (isset($payload['billing'][$field]) && (!is_string($payload['billing'][$field]) || trim($payload['billing'][$field]) === '')) {
                    $errors["billing.$field"] = "$field cannot be empty.";
                }
            }
        }

        if (isset($payload['shipping']) && is_array($payload['shipping'])) {
            foreach (['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country'] as $field) {
                if (isset($payload['shipping'][$field]) && (!is_string($payload['shipping'][$field]) || trim($payload['shipping'][$field]) === '')) {
                    $errors["shipping.$field"] = "$field cannot be empty.";
                }
            }
        }

        if ($errors !== []) {
            throw WcApiException::invalidParam($errors);
        }

        return $payload;
    }

    private static function booleanParam(mixed $raw, string $key): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $v = is_string($raw) ? strtolower(trim($raw)) : $raw;

        return match ($v) {
            1, '1', 'true', 'yes' => true,
            0, '0', 'false', 'no' => false,
            default => throw WcApiException::invalidParam([$key => "$key must be boolean"]),
        };
    }

    /**
     * Validates `payment_method` (a gateway id_key) against registered
     * payment gateways and auto-fills `payment_method_title` from the
     * gateway title when the client did not supply one.
     *
     * Mutates $payload in place. An empty payment_method is allowed
     * (manual/admin orders) and clears any stale title.
     */
    private function resolvePaymentMethod(array &$payload): void
    {
        $method = $payload['payment_method'] ?? null;

        if ($method !== null && !is_string($method)) {
            throw WcApiException::invalidParam(['payment_method' => 'payment_method must be a string.']);
        }

        $method = trim((string) $method);

        if ($method === '') {
            // Clearing the gateway — drop a stale title unless explicitly set.
            if (!isset($payload['payment_method_title'])) {
                $payload['payment_method_title'] = null;
            }

            return;
        }

        $gateway = $this->gateways->findByIdKey($method);

        if ($gateway === null) {
            throw WcApiException::invalidParam([
                'payment_method' => "Payment gateway \"$method\" does not exist.",
            ]);
        }

        if ((int) $gateway->enabled !== 1) {
            throw WcApiException::invalidParam([
                'payment_method' => "Payment gateway \"$method\" is disabled.",
            ]);
        }

        if (!isset($payload['payment_method_title']) || trim((string) $payload['payment_method_title']) === '') {
            $payload['payment_method_title'] = $gateway->title;
        }
    }

    private function pageUrl(Request $request, Pagination $pagination, int $total, int $delta): string
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $targetOffset = $pagination->offset + $delta * $pagination->perPage;

        if ($targetOffset < 0 || $targetOffset >= $total) {
            return '';
        }

        $page = intdiv($targetOffset, $pagination->perPage) + 1;
        unset($query['offset']);
        $query['page'] = (string) $page;

        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $this->baseUrl . $request->path() . ($parts === [] ? '' : '?' . implode('&', $parts));
    }
}