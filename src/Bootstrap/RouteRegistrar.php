<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Api\Controller\CouponController;
use App\Api\Controller\CustomerAuthController;
use App\Api\Controller\CustomerController;
use App\Api\Controller\OrderController;
use App\Api\Controller\OrderNoteController;
use App\Api\Controller\OrderRefundController;
use App\Api\Controller\PaymentGatewayController;
use App\Api\Controller\ProductAttributeController;
use App\Api\Controller\ProductAttributeTermController;
use App\Api\Controller\ProductCategoryController;
use App\Api\Controller\ProductController;
use App\Api\Controller\ProductReviewController;
use App\Api\Controller\ProductTagController;
use App\Api\Controller\ProductVariationController;
use App\Api\Controller\SettingsController;
use App\Api\Controller\ShippingClassController;
use App\Api\Controller\ShippingZoneController;
use App\Api\Controller\TaxClassController;
use App\Api\Controller\TaxRateController;
use App\Api\Controller\WebhookController;
use App\Security\Middleware\WcAuthenticationMiddleware;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class RouteRegistrar
{
    public static function register(\FlintPHP\Framework\Foundation\Application $app): void
    {
        $router = $app->router();
        $prefix = '/api/fc/v3';
        $wc = [\App\Security\Middleware\WcAuthenticationMiddleware::class];
        $jwt = [\App\Security\Middleware\JwtAuthenticationMiddleware::class];

        // Public infrastructure
        $router->get('/health', static function (Request $request): Response {
            return Response::json(['status' => 'ok'], 200);
        });

        $router->get('/metrics', static function (Request $request): Response {
            return Response::json(['metrics' => 'available'], 200);
        });

        $router->get('/', static function (Request $request) use ($app): Response {
            $routes = [];
            foreach ($app->router()->routes() as $route) {
                $routes[$route->pattern()][] = $route->method();
            }
            return Response::json([
                'name' => 'FlintCommerce API',
                'namespace' => 'fc/v3',
                'routes' => $routes,
            ], 200);
        });

        // Products
        self::crud($router, $prefix, '/products', \App\Api\Controller\ProductController::class, $wc);
        $router->post($prefix . '/products/batch', [\App\Api\Controller\ProductController::class, 'batch'], null, $wc);

        // Product variations (nested)
        $router->get($prefix . '/products/{productId}/variations', [\App\Api\Controller\ProductVariationController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/{productId}/variations', [\App\Api\Controller\ProductVariationController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/{productId}/variations/{id}', [\App\Api\Controller\ProductVariationController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/{productId}/variations/{id}', [\App\Api\Controller\ProductVariationController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/{productId}/variations/{id}', [\App\Api\Controller\ProductVariationController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/{productId}/variations/{id}', [\App\Api\Controller\ProductVariationController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/{productId}/variations/{id}', [\App\Api\Controller\ProductVariationController::class, 'destroy'], null, $wc);
        $router->post($prefix . '/products/{productId}/variations/batch', [\App\Api\Controller\ProductVariationController::class, 'batch'], null, $wc);

        // Product attributes (global)
        self::crud($router, $prefix, '/products/attributes', \App\Api\Controller\ProductAttributeController::class, $wc);
        $router->post($prefix . '/products/attributes/batch', [\App\Api\Controller\ProductAttributeController::class, 'batch'], null, $wc);

        // Attribute terms (nested)
        $router->get($prefix . '/products/attributes/{attributeId}/terms', [\App\Api\Controller\ProductAttributeTermController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/attributes/{attributeId}/terms', [\App\Api\Controller\ProductAttributeTermController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/attributes/{attributeId}/terms/{id}', [\App\Api\Controller\ProductAttributeTermController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/attributes/{attributeId}/terms/{id}', [\App\Api\Controller\ProductAttributeTermController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/attributes/{attributeId}/terms/{id}', [\App\Api\Controller\ProductAttributeTermController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/attributes/{attributeId}/terms/{id}', [\App\Api\Controller\ProductAttributeTermController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/attributes/{attributeId}/terms/{id}', [\App\Api\Controller\ProductAttributeTermController::class, 'destroy'], null, $wc);
        $router->post($prefix . '/products/attributes/{attributeId}/terms/batch', [\App\Api\Controller\ProductAttributeTermController::class, 'batch'], null, $wc);

        // Categories
        self::crud($router, $prefix, '/products/categories', \App\Api\Controller\ProductCategoryController::class, $wc);
        $router->post($prefix . '/products/categories/batch', [\App\Api\Controller\ProductCategoryController::class, 'batch'], null, $wc);

        // Tags
        self::crud($router, $prefix, '/products/tags', \App\Api\Controller\ProductTagController::class, $wc);
        $router->post($prefix . '/products/tags/batch', [\App\Api\Controller\ProductTagController::class, 'batch'], null, $wc);

        // Reviews (nested)
        $router->get($prefix . '/products/{productId}/reviews', [\App\Api\Controller\ProductReviewController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/{productId}/reviews', [\App\Api\Controller\ProductReviewController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/{productId}/reviews/{id}', [\App\Api\Controller\ProductReviewController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/{productId}/reviews/{id}', [\App\Api\Controller\ProductReviewController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/{productId}/reviews/{id}', [\App\Api\Controller\ProductReviewController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/{productId}/reviews/{id}', [\App\Api\Controller\ProductReviewController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/{productId}/reviews/{id}', [\App\Api\Controller\ProductReviewController::class, 'destroy'], null, $wc);
        $router->post($prefix . '/products/{productId}/reviews/batch', [\App\Api\Controller\ProductReviewController::class, 'batch'], null, $wc);

        // Shipping classes
        self::crud($router, $prefix, '/products/shipping_classes', \App\Api\Controller\ShippingClassController::class, $wc);
        $router->post($prefix . '/products/shipping_classes/batch', [\App\Api\Controller\ShippingClassController::class, 'batch'], null, $wc);

        // Customers
        self::crud($router, $prefix, '/customers', \App\Api\Controller\CustomerController::class, $wc);

        // Customer addresses (nested)
        $router->get($prefix . '/customers/{customerId}/addresses', [\App\Api\Controller\CustomerController::class, 'addresses'], null, $wc);
        $router->post($prefix . '/customers/{customerId}/addresses', [\App\Api\Controller\CustomerController::class, 'createAddress'], null, $wc);
        $router->get($prefix . '/customers/{customerId}/addresses/{id}', [\App\Api\Controller\CustomerController::class, 'address'], null, $wc);
        $router->put($prefix . '/customers/{customerId}/addresses/{id}', [\App\Api\Controller\CustomerController::class, 'updateAddress'], null, $wc);
        $router->post($prefix . '/customers/{customerId}/addresses/{id}', [\App\Api\Controller\CustomerController::class, 'updateAddress'], null, $wc);
        $router->patch($prefix . '/customers/{customerId}/addresses/{id}', [\App\Api\Controller\CustomerController::class, 'updateAddress'], null, $wc);
        $router->delete($prefix . '/customers/{customerId}/addresses/{id}', [\App\Api\Controller\CustomerController::class, 'deleteAddress'], null, $wc);

        // JWT (public)
        $router->post($prefix . '/customers/jwt/token', [\App\Api\Controller\CustomerAuthController::class, 'login']);
        $router->post($prefix . '/customers/jwt/token/refresh', [\App\Api\Controller\CustomerAuthController::class, 'refresh']);
        $router->post($prefix . '/customers/jwt/register', [\App\Api\Controller\CustomerAuthController::class, 'register']);
        $router->get($prefix . '/customers/jwt/me', [\App\Api\Controller\CustomerAuthController::class, 'me'], null, $jwt);

        // Coupons
        self::crud($router, $prefix, '/coupons', \App\Api\Controller\CouponController::class, $wc);
        $router->post($prefix . '/coupons/batch', [\App\Api\Controller\CouponController::class, 'batch'], null, $wc);

        // Tax classes
        self::crud($router, $prefix, '/taxes/classes', \App\Api\Controller\TaxClassController::class, $wc);

        // Tax rates (nested)
        $router->get($prefix . '/taxes/classes/{taxClassId}/rates', [\App\Api\Controller\TaxRateController::class, 'index'], null, $wc);
        $router->post($prefix . '/taxes/classes/{taxClassId}/rates', [\App\Api\Controller\TaxRateController::class, 'store'], null, $wc);
        $router->get($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [\App\Api\Controller\TaxRateController::class, 'show'], null, $wc);
        $router->put($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [\App\Api\Controller\TaxRateController::class, 'update'], null, $wc);
        $router->post($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [\App\Api\Controller\TaxRateController::class, 'update'], null, $wc);
        $router->patch($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [\App\Api\Controller\TaxRateController::class, 'update'], null, $wc);
        $router->delete($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [\App\Api\Controller\TaxRateController::class, 'destroy'], null, $wc);
        $router->post($prefix . '/taxes/classes/{taxClassId}/rates/batch', [\App\Api\Controller\TaxRateController::class, 'batch'], null, $wc);

        // Orders
        self::crud($router, $prefix, '/orders', \App\Api\Controller\OrderController::class, $wc);
        $router->post($prefix . '/orders/batch', [\App\Api\Controller\OrderController::class, 'batch'], null, $wc);

        // Order notes (nested)
        $router->get($prefix . '/orders/{orderId}/notes', [\App\Api\Controller\OrderNoteController::class, 'index'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/notes', [\App\Api\Controller\OrderNoteController::class, 'store'], null, $wc);
        $router->get($prefix . '/orders/{orderId}/notes/{id}', [\App\Api\Controller\OrderNoteController::class, 'show'], null, $wc);
        $router->put($prefix . '/orders/{orderId}/notes/{id}', [\App\Api\Controller\OrderNoteController::class, 'update'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/notes/{id}', [\App\Api\Controller\OrderNoteController::class, 'update'], null, $wc);
        $router->patch($prefix . '/orders/{orderId}/notes/{id}', [\App\Api\Controller\OrderNoteController::class, 'update'], null, $wc);
        $router->delete($prefix . '/orders/{orderId}/notes/{id}', [\App\Api\Controller\OrderNoteController::class, 'destroy'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/notes/batch', [\App\Api\Controller\OrderNoteController::class, 'batch'], null, $wc);

        // Order refunds (nested)
        $router->get($prefix . '/orders/{orderId}/refunds', [\App\Api\Controller\OrderRefundController::class, 'index'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/refunds', [\App\Api\Controller\OrderRefundController::class, 'store'], null, $wc);
        $router->get($prefix . '/orders/{orderId}/refunds/{id}', [\App\Api\Controller\OrderRefundController::class, 'show'], null, $wc);
        $router->put($prefix . '/orders/{orderId}/refunds/{id}', [\App\Api\Controller\OrderRefundController::class, 'update'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/refunds/{id}', [\App\Api\Controller\OrderRefundController::class, 'update'], null, $wc);
        $router->patch($prefix . '/orders/{orderId}/refunds/{id}', [\App\Api\Controller\OrderRefundController::class, 'update'], null, $wc);
        $router->delete($prefix . '/orders/{orderId}/refunds/{id}', [\App\Api\Controller\OrderRefundController::class, 'destroy'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/refunds/batch', [\App\Api\Controller\OrderRefundController::class, 'batch'], null, $wc);

        // Shipping zones
        self::crud($router, $prefix, '/shipping/zones', \App\Api\Controller\ShippingZoneController::class, $wc);

        // Zone locations (nested)
        $router->get($prefix . '/shipping/zones/{zoneId}/locations', [\App\Api\Controller\ShippingZoneController::class, 'locationsIndex'], null, $wc);
        $router->post($prefix . '/shipping/zones/{zoneId}/locations', [\App\Api\Controller\ShippingZoneController::class, 'locationsStore'], null, $wc);
        $router->get($prefix . '/shipping/zones/{zoneId}/locations/{id}', [\App\Api\Controller\ShippingZoneController::class, 'locationsShow'], null, $wc);
        $router->put($prefix . '/shipping/zones/{zoneId}/locations/{id}', [\App\Api\Controller\ShippingZoneController::class, 'locationsUpdate'], null, $wc);
        $router->post($prefix . '/shipping/zones/{zoneId}/locations/{id}', [\App\Api\Controller\ShippingZoneController::class, 'locationsUpdate'], null, $wc);
        $router->patch($prefix . '/shipping/zones/{zoneId}/locations/{id}', [\App\Api\Controller\ShippingZoneController::class, 'locationsUpdate'], null, $wc);
        $router->delete($prefix . '/shipping/zones/{zoneId}/locations/{id}', [\App\Api\Controller\ShippingZoneController::class, 'locationsDestroy'], null, $wc);

        // Zone methods (nested)
        $router->get($prefix . '/shipping/zones/{zoneId}/methods', [\App\Api\Controller\ShippingZoneController::class, 'methodsIndex'], null, $wc);
        $router->post($prefix . '/shipping/zones/{zoneId}/methods', [\App\Api\Controller\ShippingZoneController::class, 'methodsStore'], null, $wc);
        $router->get($prefix . '/shipping/zones/{zoneId}/methods/{id}', [\App\Api\Controller\ShippingZoneController::class, 'methodsShow'], null, $wc);
        $router->put($prefix . '/shipping/zones/{zoneId}/methods/{id}', [\App\Api\Controller\ShippingZoneController::class, 'methodsUpdate'], null, $wc);
        $router->post($prefix . '/shipping/zones/{zoneId}/methods/{id}', [\App\Api\Controller\ShippingZoneController::class, 'methodsUpdate'], null, $wc);
        $router->patch($prefix . '/shipping/zones/{zoneId}/methods/{id}', [\App\Api\Controller\ShippingZoneController::class, 'methodsUpdate'], null, $wc);
        $router->delete($prefix . '/shipping/zones/{zoneId}/methods/{id}', [\App\Api\Controller\ShippingZoneController::class, 'methodsDestroy'], null, $wc);

        // Payment gateways
        self::crud($router, $prefix, '/payment_gateways', \App\Api\Controller\PaymentGatewayController::class, $wc);
        $router->post($prefix . '/payment_gateways/batch', [\App\Api\Controller\PaymentGatewayController::class, 'batch'], null, $wc);

        // Settings
        $router->get($prefix . '/settings', [\App\Api\Controller\SettingsController::class, 'index'], null, $wc);
        $router->get($prefix . '/settings/{group}', [\App\Api\Controller\SettingsController::class, 'group'], null, $wc);
        $router->get($prefix . '/settings/{group}/{id}', [\App\Api\Controller\SettingsController::class, 'show'], null, $wc);
        $router->put($prefix . '/settings/{group}/{id}', [\App\Api\Controller\SettingsController::class, 'update'], null, $wc);
        $router->post($prefix . '/settings/{group}/{id}', [\App\Api\Controller\SettingsController::class, 'update'], null, $wc);
        $router->patch($prefix . '/settings/{group}/{id}', [\App\Api\Controller\SettingsController::class, 'update'], null, $wc);
        $router->post($prefix . '/settings/{group}/batch', [\App\Api\Controller\SettingsController::class, 'batch'], null, $wc);

        // Webhooks
        self::crud($router, $prefix, '/webhooks', \App\Api\Controller\WebhookController::class, $wc);
        $router->post($prefix . '/webhooks/batch', [\App\Api\Controller\WebhookController::class, 'batch'], null, $wc);

        // Webhook deliveries (nested)
        $router->get($prefix . '/webhooks/{webhookId}/deliveries', [\App\Api\Controller\WebhookController::class, 'deliveriesIndex'], null, $wc);
        $router->get($prefix . '/webhooks/{webhookId}/deliveries/{id}', [\App\Api\Controller\WebhookController::class, 'deliveriesShow'], null, $wc);
        $router->post($prefix . '/webhooks/{webhookId}/deliveries/retry', [\App\Api\Controller\WebhookController::class, 'deliveriesRetry'], null, $wc);
    }

    private static function crud(\FlintPHP\Framework\Routing\Router $router, string $prefix, string $resource, string $controller, array $middleware): void
    {
        $router->get($prefix . $resource, [$controller, 'index'], null, $middleware);
        $router->post($prefix . $resource, [$controller, 'store'], null, $middleware);
        $router->get($prefix . $resource . '/{id}', [$controller, 'show'], null, $middleware);
        $router->put($prefix . $resource . '/{id}', [$controller, 'update'], null, $middleware);
        $router->post($prefix . $resource . '/{id}', [$controller, 'update'], null, $middleware);
        $router->patch($prefix . $resource . '/{id}', [$controller, 'update'], null, $middleware);
        $router->delete($prefix . $resource . '/{id}', [$controller, 'destroy'], null, $middleware);
    }
}