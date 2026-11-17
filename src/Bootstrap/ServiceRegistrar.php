<?php

declare(strict_types=1);

namespace App\Bootstrap;

use FlintPHP\Framework\Foundation\Application;

/**
 * Registers all application services in the container.
 */
final class ServiceRegistrar
{
    public static function register(Application $app): void
    {
        $container = $app->container();
        $config = $app->config();

        $dp = (int) ($config->get('app.wc.dp') ?? 2);
        $baseUrl = rtrim((string) ($config->get('app.url') ?? 'http://localhost:8000'), '/');

        // Observability
        $container->singleton(\FlintPHP\Framework\Observability\Contract\MetricRegistryInterface::class, new \FlintPHP\Framework\Observability\Metrics\MetricRegistry());
        $container->singleton(
            \FlintPHP\Framework\Observability\Metrics\PrometheusExporter::class,
            new \FlintPHP\Framework\Observability\Metrics\PrometheusExporter($container->get(\FlintPHP\Framework\Observability\Contract\MetricRegistryInterface::class)),
        );

        // Cache
        $container->singleton(\FlintPHP\Framework\Cache\CacheInterface::class, fn (): \FlintPHP\Framework\Cache\CacheInterface => new \FlintPHP\Framework\Cache\DatabaseCache(
            $container->get(\FlintPHP\Framework\Database\ConnectionInterface::class),
        ));

        // Security
        $container->singleton(\App\Security\WcBasicAuthenticator::class, fn (): \App\Security\WcBasicAuthenticator => new \App\Security\WcBasicAuthenticator(
            new \App\Domain\Repository\ApiKeyRepository($container->get(\FlintPHP\Framework\Database\ConnectionInterface::class), $container->get(\FlintPHP\Framework\Orm\OrmManager::class)),
            (bool) ($config->get('security.auth_require_https') ?? false),
        ));
        $container->singleton(\App\Security\JwtAuth::class, fn (): \App\Security\JwtAuth => new \App\Security\JwtAuth($config));

        // Transformers
        $container->singleton(\App\Api\Transformer\CategoryTransformer::class, fn (): \App\Api\Transformer\CategoryTransformer => new \App\Api\Transformer\CategoryTransformer($dp));
        $container->singleton(\App\Api\Transformer\CouponTransformer::class, fn (): \App\Api\Transformer\CouponTransformer => new \App\Api\Transformer\CouponTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\CustomerTransformer::class, fn (): \App\Api\Transformer\CustomerTransformer => new \App\Api\Transformer\CustomerTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\CustomerAddressTransformer::class, fn (): \App\Api\Transformer\CustomerAddressTransformer => new \App\Api\Transformer\CustomerAddressTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\OrderTransformer::class, fn (): \App\Api\Transformer\OrderTransformer => new \App\Api\Transformer\OrderTransformer($baseUrl, $dp));
        $container->singleton(\App\Api\Transformer\OrderNoteTransformer::class, fn (): \App\Api\Transformer\OrderNoteTransformer => new \App\Api\Transformer\OrderNoteTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\OrderRefundTransformer::class, fn (): \App\Api\Transformer\OrderRefundTransformer => new \App\Api\Transformer\OrderRefundTransformer($baseUrl, $dp));
        $container->singleton(\App\Api\Transformer\PaymentGatewayTransformer::class, fn (): \App\Api\Transformer\PaymentGatewayTransformer => new \App\Api\Transformer\PaymentGatewayTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\ProductTransformer::class, fn (): \App\Api\Transformer\ProductTransformer => new \App\Api\Transformer\ProductTransformer($baseUrl, $dp));
        $container->singleton(\App\Api\Transformer\ProductVariationTransformer::class, fn (): \App\Api\Transformer\ProductVariationTransformer => new \App\Api\Transformer\ProductVariationTransformer($baseUrl, $dp));
        $container->singleton(\App\Api\Transformer\ProductAttributeTransformer::class, fn (): \App\Api\Transformer\ProductAttributeTransformer => new \App\Api\Transformer\ProductAttributeTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\ProductAttributeTermTransformer::class, fn (): \App\Api\Transformer\ProductAttributeTermTransformer => new \App\Api\Transformer\ProductAttributeTermTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\ProductReviewTransformer::class, fn (): \App\Api\Transformer\ProductReviewTransformer => new \App\Api\Transformer\ProductReviewTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\ProductTagTransformer::class, fn (): \App\Api\Transformer\ProductTagTransformer => new \App\Api\Transformer\ProductTagTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\ShippingClassTransformer::class, fn (): \App\Api\Transformer\ShippingClassTransformer => new \App\Api\Transformer\ShippingClassTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\ShippingZoneTransformer::class, fn (): \App\Api\Transformer\ShippingZoneTransformer => new \App\Api\Transformer\ShippingZoneTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\ShippingZoneLocationTransformer::class, fn (): \App\Api\Transformer\ShippingZoneLocationTransformer => new \App\Api\Transformer\ShippingZoneLocationTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\ShippingZoneMethodTransformer::class, fn (): \App\Api\Transformer\ShippingZoneMethodTransformer => new \App\Api\Transformer\ShippingZoneMethodTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\TaxClassTransformer::class, fn (): \App\Api\Transformer\TaxClassTransformer => new \App\Api\Transformer\TaxClassTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\TaxRateTransformer::class, fn (): \App\Api\Transformer\TaxRateTransformer => new \App\Api\Transformer\TaxRateTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\SettingTransformer::class, fn (): \App\Api\Transformer\SettingTransformer => new \App\Api\Transformer\SettingTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\SettingGroupTransformer::class, fn (): \App\Api\Transformer\SettingGroupTransformer => new \App\Api\Transformer\SettingGroupTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\WebhookTransformer::class, fn (): \App\Api\Transformer\WebhookTransformer => new \App\Api\Transformer\WebhookTransformer($baseUrl));
        $container->singleton(\App\Api\Transformer\WebhookDeliveryTransformer::class, fn (): \App\Api\Transformer\WebhookDeliveryTransformer => new \App\Api\Transformer\WebhookDeliveryTransformer($baseUrl));

        // Repositories
        $container->singleton(\App\Domain\Repository\SettingRepository::class, fn (): \App\Domain\Repository\SettingRepository => new \App\Domain\Repository\SettingRepository(
            $container->get(\FlintPHP\Framework\Database\ConnectionInterface::class),
        ));
        $container->singleton(\App\Domain\Repository\WebhookRepository::class, fn (): \App\Domain\Repository\WebhookRepository => new \App\Domain\Repository\WebhookRepository(
            $container->get(\FlintPHP\Framework\Database\ConnectionInterface::class),
        ));

        // Controllers
        $container->singleton(\App\Api\Controller\OrderController::class, fn (): \App\Api\Controller\OrderController => new \App\Api\Controller\OrderController(
            $container->get(\App\Domain\Repository\OrderRepository::class),
            $container->get(\App\Domain\Repository\ProductRepository::class),
            $container->get(\App\Domain\Repository\ProductVariationRepository::class),
            $container->get(\App\Domain\Repository\CouponRepository::class),
            $container->get(\App\Domain\Repository\TaxRateRepository::class),
            $container->get(\App\Domain\Repository\TaxClassRepository::class),
            $container->get(\App\Domain\Repository\CustomerRepository::class),
            $container->get(\App\Domain\Repository\PaymentGatewayRepository::class),
            $container->get(\App\Api\Transformer\OrderTransformer::class),
            $container->get(\App\Tax\TaxCalculator::class),
            $container->get(\App\Shipping\ShippingCalculator::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\OrderNoteController::class, fn (): \App\Api\Controller\OrderNoteController => new \App\Api\Controller\OrderNoteController(
            $container->get(\App\Domain\Repository\OrderRepository::class),
            $container->get(\App\Domain\Repository\OrderNoteRepository::class),
            $container->get(\App\Api\Transformer\OrderNoteTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\OrderRefundController::class, fn (): \App\Api\Controller\OrderRefundController => new \App\Api\Controller\OrderRefundController(
            $container->get(\App\Domain\Repository\OrderRepository::class),
            $container->get(\App\Domain\Repository\OrderRefundRepository::class),
            $container->get(\App\Api\Transformer\OrderRefundTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\ShippingZoneController::class, fn (): \App\Api\Controller\ShippingZoneController => new \App\Api\Controller\ShippingZoneController(
            $container->get(\App\Domain\Repository\ShippingZoneRepository::class),
            $container->get(\App\Domain\Repository\ShippingZoneLocationRepository::class),
            $container->get(\App\Domain\Repository\ShippingZoneMethodRepository::class),
            $container->get(\App\Api\Transformer\ShippingZoneTransformer::class),
            $container->get(\App\Api\Transformer\ShippingZoneLocationTransformer::class),
            $container->get(\App\Api\Transformer\ShippingZoneMethodTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\PaymentGatewayController::class, fn (): \App\Api\Controller\PaymentGatewayController => new \App\Api\Controller\PaymentGatewayController(
            $container->get(\App\Domain\Repository\PaymentGatewayRepository::class),
            $container->get(\App\Api\Transformer\PaymentGatewayTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\SettingsController::class, fn (): \App\Api\Controller\SettingsController => new \App\Api\Controller\SettingsController(
            $container->get(\App\Domain\Repository\SettingRepository::class),
            $container->get(\App\Api\Transformer\SettingGroupTransformer::class),
            $container->get(\App\Api\Transformer\SettingTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\WebhookController::class, fn (): \App\Api\Controller\WebhookController => new \App\Api\Controller\WebhookController(
            $container->get(\App\Domain\Repository\WebhookRepository::class),
            $container->get(\App\Api\Transformer\WebhookTransformer::class),
            $container->get(\App\Api\Transformer\WebhookDeliveryTransformer::class),
            $baseUrl,
        ));

        // Standard CRUD controllers
        $container->singleton(\App\Api\Controller\CouponController::class, fn (): \App\Api\Controller\CouponController => new \App\Api\Controller\CouponController(
            $container->get(\App\Domain\Repository\CouponRepository::class),
            $container->get(\App\Api\Transformer\CouponTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\CustomerController::class, fn (): \App\Api\Controller\CustomerController => new \App\Api\Controller\CustomerController(
            $container->get(\App\Domain\Repository\CustomerRepository::class),
            $container->get(\App\Domain\Repository\CustomerAddressRepository::class),
            $container->get(\App\Api\Transformer\CustomerTransformer::class),
            $container->get(\App\Api\Transformer\CustomerAddressTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\CustomerAuthController::class, fn (): \App\Api\Controller\CustomerAuthController => new \App\Api\Controller\CustomerAuthController(
            $container->get(\App\Domain\Repository\CustomerRepository::class),
            $container->get(\App\Security\JwtAuth::class),
        ));
        $container->singleton(\App\Api\Controller\ProductController::class, fn (): \App\Api\Controller\ProductController => new \App\Api\Controller\ProductController(
            $container->get(\App\Domain\Repository\ProductRepository::class),
            $container->get(\App\Api\Transformer\ProductTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\ProductVariationController::class, fn (): \App\Api\Controller\ProductVariationController => new \App\Api\Controller\ProductVariationController(
            $container->get(\App\Domain\Repository\ProductRepository::class),
            $container->get(\App\Domain\Repository\ProductVariationRepository::class),
            $container->get(\App\Api\Transformer\ProductVariationTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\ProductAttributeController::class, fn (): \App\Api\Controller\ProductAttributeController => new \App\Api\Controller\ProductAttributeController(
            $container->get(\App\Domain\Repository\ProductAttributeRepository::class),
            $container->get(\App\Api\Transformer\ProductAttributeTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\ProductAttributeTermController::class, fn (): \App\Api\Controller\ProductAttributeTermController => new \App\Api\Controller\ProductAttributeTermController(
            $container->get(\App\Domain\Repository\ProductAttributeRepository::class),
            $container->get(\App\Domain\Repository\ProductAttributeTermRepository::class),
            $container->get(\App\Api\Transformer\ProductAttributeTermTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\ProductCategoryController::class, fn (): \App\Api\Controller\ProductCategoryController => new \App\Api\Controller\ProductCategoryController(
            $container->get(\App\Domain\Repository\ProductCategoryRepository::class),
            $container->get(\App\Api\Transformer\CategoryTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\ProductTagController::class, fn (): \App\Api\Controller\ProductTagController => new \App\Api\Controller\ProductTagController(
            $container->get(\App\Domain\Repository\ProductTagRepository::class),
            $container->get(\App\Api\Transformer\ProductTagTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\ProductReviewController::class, fn (): \App\Api\Controller\ProductReviewController => new \App\Api\Controller\ProductReviewController(
            $container->get(\App\Domain\Repository\ProductRepository::class),
            $container->get(\App\Domain\Repository\ProductReviewRepository::class),
            $container->get(\App\Api\Transformer\ProductReviewTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\ShippingClassController::class, fn (): \App\Api\Controller\ShippingClassController => new \App\Api\Controller\ShippingClassController(
            $container->get(\App\Domain\Repository\ShippingClassRepository::class),
            $container->get(\App\Api\Transformer\ShippingClassTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\TaxClassController::class, fn (): \App\Api\Controller\TaxClassController => new \App\Api\Controller\TaxClassController(
            $container->get(\App\Domain\Repository\TaxClassRepository::class),
            $container->get(\App\Api\Transformer\TaxClassTransformer::class),
            $baseUrl,
        ));
        $container->singleton(\App\Api\Controller\TaxRateController::class, fn (): \App\Api\Controller\TaxRateController => new \App\Api\Controller\TaxRateController(
            $container->get(\App\Domain\Repository\TaxClassRepository::class),
            $container->get(\App\Domain\Repository\TaxRateRepository::class),
            $container->get(\App\Api\Transformer\TaxRateTransformer::class),
            $baseUrl,
        ));

        // Tax & Shipping Calculators
        $container->singleton(\App\Tax\TaxCalculator::class, fn (): \App\Tax\TaxCalculator => new \App\Tax\TaxCalculator(
            $container->get(\App\Domain\Repository\TaxRateRepository::class),
            (int) ($config->get('tax.decimals') ?? 2),
            (string) ($config->get('tax.rounding_mode') ?? 'line'),
            (bool) ($config->get('tax.prices_include_tax') ?? false),
        ));
        $container->singleton(\App\Shipping\ShippingCalculator::class, fn (): \App\Shipping\ShippingCalculator => new \App\Shipping\ShippingCalculator(
            $container->get(\App\Domain\Repository\ShippingZoneRepository::class),
            $container->get(\App\Domain\Repository\ShippingZoneLocationRepository::class),
            $container->get(\App\Domain\Repository\ShippingZoneMethodRepository::class),
            (int) ($config->get('wc.dp') ?? 2),
        ));

        // Webhook Dispatcher
        $container->singleton(\App\Webhook\WebhookDispatcher::class, fn (): \App\Webhook\WebhookDispatcher => new \App\Webhook\WebhookDispatcher(
            $container->get(\App\Domain\Repository\WebhookRepository::class),
            $container->get(\FlintPHP\Framework\Database\ConnectionInterface::class),
            (int) ($config->get('webhook.max_attempts') ?? 5),
            (int) ($config->get('webhook.base_delay_ms') ?? 1000),
            (int) ($config->get('webhook.max_delay_ms') ?? 300000),
            (int) ($config->get('webhook.timeout_ms') ?? 10000),
        ));
        $container->singleton(\App\Console\Command\WebhookWorkerCommand::class, fn (): \App\Console\Command\WebhookWorkerCommand => new \App\Console\Command\WebhookWorkerCommand(
            $container->get(\App\Webhook\WebhookDispatcher::class),
            $container->get(\App\Domain\Repository\WebhookRepository::class),
        ));
    }
}