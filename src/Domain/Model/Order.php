<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class Order extends Model
{
    protected string $table = 'orders';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'parent_id', 'status', 'currency', 'version', 'prices_include_tax',
        'date_created', 'date_created_gmt', 'date_modified', 'date_modified_gmt',
        'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax',
        'cart_tax', 'total', 'total_tax', 'customer_id', 'order_key',
        'billing_first_name', 'billing_last_name', 'billing_company',
        'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state',
        'billing_postcode', 'billing_country', 'billing_email', 'billing_phone',
        'shipping_first_name', 'shipping_last_name', 'shipping_company',
        'shipping_address_1', 'shipping_address_2', 'shipping_city',
        'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone',
        'payment_method', 'payment_method_title', 'transaction_id',
        'customer_ip_address', 'customer_user_agent', 'created_via',
        'completed_at', 'completed_at_gmt', 'paid_at', 'paid_at_gmt',
        'coupon_codes', 'created_at', 'updated_at',
    ];

    public $id;
    public $parent_id;
    public $status;
    public $currency;
    public $version;
    public $prices_include_tax;
    public $date_created;
    public $date_created_gmt;
    public $date_modified;
    public $date_modified_gmt;
    public $discount_total;
    public $discount_tax;
    public $shipping_total;
    public $shipping_tax;
    public $cart_tax;
    public $total;
    public $total_tax;
    public $customer_id;
    public $order_key;
    public $billing_first_name;
    public $billing_last_name;
    public $billing_company;
    public $billing_address_1;
    public $billing_address_2;
    public $billing_city;
    public $billing_state;
    public $billing_postcode;
    public $billing_country;
    public $billing_email;
    public $billing_phone;
    public $shipping_first_name;
    public $shipping_last_name;
    public $shipping_company;
    public $shipping_address_1;
    public $shipping_address_2;
    public $shipping_city;
    public $shipping_state;
    public $shipping_postcode;
    public $shipping_country;
    public $shipping_phone;
    public $payment_method;
    public $payment_method_title;
    public $transaction_id;
    public $customer_ip_address;
    public $customer_user_agent;
    public $created_via;
    public $completed_at;
    public $completed_at_gmt;
    public $paid_at;
    public $paid_at_gmt;
    public $coupon_codes;
    public $created_at;
    public $updated_at;
}