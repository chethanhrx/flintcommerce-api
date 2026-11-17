<?php

declare(strict_types=1);

namespace App\Migrations;

/**
 * Explicit migration registry. Order = id order.
 */
final class Migrations
{
    /** @return list<\FlintPHP\Framework\Database\Migration\MigrationInterface> */
    public static function all(): array
    {
        return [
            new CreateApiKeys(),
            new CreateProductCategories(),
            new CreateProducts(),
            new CreateProductCategoryRelationships(),
            new CreateProductVariations(),
            new CreateProductAttributes(),
            new CreateProductAttributeTerms(),
            new CreateProductProductAttributes(),
            new CreateProductTags(),
            new CreateProductReviews(),
            new CreateShippingClasses(),
            new CreateCustomers(),
            new CreateCustomerAddresses(),
            new CreateOrders(),
            new CreateOrderItems(),
            new CreateCoupons(),
            new CreateTaxClasses(),
            new CreateTaxRates(),
            new CreateOrderNotes(),
            new CreateOrderRefunds(),
            new CreateShippingZones(),
new CreateShippingZoneLocations(),
        new CreateShippingZoneMethods(),
        new CreatePaymentGateways(),
        new CreateSettings(),
        new CreateWebhooks(),
        new CreateWebhookDeliveries(),
    ];
    }
}
