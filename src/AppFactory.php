<?php

declare(strict_types=1);

namespace App;

use App\Api\Controller\CustomerAuthController;
use App\Api\Controller\CustomerController;
use App\Api\Controller\OrderController;
use App\Api\Controller\OrderNoteController;
use App\Api\Controller\OrderRefundController;
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
use App\Api\Controller\PaymentGatewayController;
use App\Api\Controller\TaxClassController;
use App\Api\Controller\TaxRateController;
use App\Api\Controller\WebhookController;
use App\Api\Controller\CouponController;
use App\Api\Transformer\CategoryTransformer;
use App\Api\Transformer\CouponTransformer;
use App\Api\Transformer\CustomerAddressTransformer;
use App\Api\Transformer\CustomerTransformer;
use App\Api\Transformer\OrderNoteTransformer;
use App\Api\Transformer\OrderRefundTransformer;
use App\Api\Transformer\OrderTransformer;
use App\Api\Transformer\ProductAttributeTermTransformer;
use App\Api\Transformer\ProductAttributeTransformer;
use App\Api\Transformer\PaymentGatewayTransformer;
use App\Api\Transformer\ProductReviewTransformer;
use App\Api\Transformer\ProductTagTransformer;
use App\Api\Transformer\ProductTransformer;
use App\Api\Transformer\ProductVariationTransformer;
use App\Api\Transformer\SettingGroupTransformer;
use App\Api\Transformer\SettingTransformer;
use App\Api\Transformer\ShippingClassTransformer;
use App\Api\Transformer\ShippingZoneLocationTransformer;
use App\Api\Transformer\ShippingZoneMethodTransformer;
use App\Api\Transformer\ShippingZoneTransformer;
use App\Api\Transformer\TaxClassTransformer;
use App\Api\Transformer\TaxRateTransformer;
use App\Api\Transformer\WebhookDeliveryTransformer;
use App\Api\Transformer\WebhookTransformer;
use App\Console\Command\DbSeedCommand;
use App\Console\Command\KeyGenerateCommand;
use App\Console\Command\WebhookWorkerCommand;
use App\Domain\Repository\ApiKeyRepository;
use App\Domain\Repository\CouponRepository;
use App\Domain\Repository\CustomerAddressRepository;
use App\Domain\Repository\CustomerRepository;
use App\Domain\Repository\OrderNoteRepository;
use App\Domain\Repository\OrderRefundRepository;
use App\Domain\Repository\OrderRepository;
use App\Domain\Repository\ProductAttributeRepository;
use App\Domain\Repository\ProductAttributeTermRepository;
use App\Domain\Repository\ProductCategoryRepository;
use App\Domain\Repository\ProductProductAttributeRepository;
use App\Domain\Repository\ProductRepository;
use App\Domain\Repository\ProductReviewRepository;
use App\Domain\Repository\ProductTagRepository;
use App\Domain\Repository\ProductVariationRepository;
use App\Domain\Repository\SettingRepository;
use App\Domain\Repository\ShippingClassRepository;
use App\Domain\Repository\ShippingZoneLocationRepository;
use App\Domain\Repository\ShippingZoneMethodRepository;
use App\Domain\Repository\ShippingZoneRepository;
use App\Domain\Repository\PaymentGatewayRepository;
use App\Domain\Repository\TaxClassRepository;
use App\Domain\Repository\TaxRateRepository;
use App\Domain\Repository\WebhookRepository;
use App\Http\Middleware\ExposeHeadersMiddleware;
use App\Http\Middleware\MetricsMiddleware;
use App\Http\Middleware\WcKernelErrorMiddleware;
use App\Http\Middleware\CacheHeadersMiddleware;
use App\Http\Middleware\CompressionMiddleware;
use App\Http\Middleware\BotDetectionMiddleware;
use App\Http\WcJsonExceptionHandler;
use App\Security\Middleware\WcAuthenticationMiddleware;
use App\Security\Middleware\JwtAuthenticationMiddleware;
use App\Security\WcBasicAuthenticator;
use App\Security\JwtAuth;
use App\Support\BcMath;
use App\Support\Sql;
use App\Tax\TaxCalculator;
use App\Shipping\ShippingCalculator;
use App\Webhook\WebhookDispatcher;
use FlintPHP\Framework\Cache\CacheInterface;
use FlintPHP\Framework\Cache\DatabaseCache;
use FlintPHP\Framework\Config\ConfigRepository;
use FlintPHP\Framework\Config\Contract\ConfigRepositoryInterface;
use FlintPHP\Framework\Config\PhpFileLoader;
use FlintPHP\Framework\Console\ConsoleApplication;
use FlintPHP\Framework\Console\Command\MigrateCommand;
use FlintPHP\Framework\Console\Command\MigrateRollbackCommand;
use FlintPHP\Framework\Console\Command\MigrateStatusCommand;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\DatabaseBootstrapper;
use FlintPHP\Framework\Database\Migration\Migrator;
use FlintPHP\Framework\Foundation\Application;
use FlintPHP\Framework\Health\HealthChecker;
use FlintPHP\Framework\Http\Middleware\RateLimitMiddleware;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Observability\Contract\MetricRegistryInterface;
use FlintPHP\Framework\Observability\Metrics\MetricRegistry;
use FlintPHP\Framework\Observability\Metrics\PrometheusExporter;
use FlintPHP\Framework\Orm\OrmBootstrapper;
use FlintPHP\Framework\Orm\OrmManager;
use FlintPHP\Framework\Security\Middleware\SecurityHeadersConfiguration;
use FlintPHP\Framework\Security\Middleware\SecurityHeadersMiddleware;
use FlintPHP\Framework\Validation\ValidationBootstrapper;
use App\Migrations\Migrations;

/**
 * Composition root. Explicit wiring: every consumer with scalar constructor
 * dependencies is registered as a singleton closure; the container's
 * reflection auto-wiring is reserved for repositories (ConnectionInterface
 * + OrmManager) which bind cleanly.
 */
final class AppFactory
{
    private const API_PREFIX = '/api/fc/v3';

    public static function create(string $basePath, ?ConfigRepositoryInterface $config = null): Application
    {
        $config ??= new ConfigRepository(PhpFileLoader::loadDirectory(rtrim($basePath, '/') . '/config'));

        $app = new Application($basePath, $config);

        $app->bootstrapWith([
            new DatabaseBootstrapper(),
            new OrmBootstrapper(),
            new ValidationBootstrapper(),
        ]);

        $logger = $app->container()->get(\FlintPHP\Framework\Observability\Contract\LoggerInterface::class);
        $app->setExceptionHandler(new WcJsonExceptionHandler($logger));

        self::registerServices($app);
        self::registerMiddleware($app);
        self::registerRoutes($app);

        return $app;
    }

    public static function console(Application $app): ConsoleApplication
    {
        $console = new ConsoleApplication();
        $container = $app->container();
        $db = $container->get(ConnectionInterface::class);

        $migrator = new Migrator($db);

        $console->register(new MigrateCommand($migrator, Migrations::all()));
        $console->register(new MigrateRollbackCommand($migrator, Migrations::all()));
        $console->register(new MigrateStatusCommand($migrator, Migrations::all()));
        $console->register(new DbSeedCommand($db));
        $console->register(new KeyGenerateCommand(new ApiKeyRepository($db, $container->get(OrmManager::class))));
        $console->register(new WebhookWorkerCommand(
            $container->get(WebhookDispatcher::class),
            $container->get(WebhookRepository::class),
        ));

        return $console;
    }

    private static function registerServices(Application $app): void
    {
        $container = $app->container();
        $config = $app->config();

        $dp = (int) ($config->get('app.wc.dp') ?? 2);
        $baseUrl = rtrim((string) ($config->get('app.url') ?? 'http://localhost:8000'), '/');

        // Observability
        $container->singleton(MetricRegistryInterface::class, new MetricRegistry());
        $container->singleton(
            PrometheusExporter::class,
            new PrometheusExporter($container->get(MetricRegistryInterface::class)),
        );

        // Cache (rate limiter backing store)
        $container->singleton(CacheInterface::class, fn (): CacheInterface => new DatabaseCache(
            $container->get(ConnectionInterface::class),
        ));

        // Security
        $container->singleton(WcBasicAuthenticator::class, fn (): WcBasicAuthenticator => new WcBasicAuthenticator(
            new ApiKeyRepository($container->get(ConnectionInterface::class), $container->get(OrmManager::class)),
            (bool) ($config->get('security.auth_require_https') ?? false),
        ));
        $container->singleton(JwtAuth::class, fn (): JwtAuth => new JwtAuth($config));

        // Transformers (scalar dependencies: baseUrl, dp)
        $container->singleton(CategoryTransformer::class, fn (): CategoryTransformer => new CategoryTransformer($dp));
        $container->singleton(CouponTransformer::class, fn (): CouponTransformer => new CouponTransformer($baseUrl));
        $container->singleton(CustomerTransformer::class, fn (): CustomerTransformer => new CustomerTransformer($baseUrl));
        $container->singleton(CustomerAddressTransformer::class, fn (): CustomerAddressTransformer => new CustomerAddressTransformer($baseUrl));
        $container->singleton(OrderTransformer::class, fn (): OrderTransformer => new OrderTransformer($baseUrl, $dp));
        $container->singleton(OrderNoteTransformer::class, fn (): OrderNoteTransformer => new OrderNoteTransformer($baseUrl));
        $container->singleton(OrderRefundTransformer::class, fn (): OrderRefundTransformer => new OrderRefundTransformer($baseUrl, $dp));
        $container->singleton(PaymentGatewayTransformer::class, fn (): PaymentGatewayTransformer => new PaymentGatewayTransformer($baseUrl));
        $container->singleton(ProductTransformer::class, fn (): ProductTransformer => new ProductTransformer($baseUrl, $dp));
        $container->singleton(ProductVariationTransformer::class, fn (): ProductVariationTransformer => new ProductVariationTransformer($baseUrl, $dp));
        $container->singleton(ProductAttributeTransformer::class, fn (): ProductAttributeTransformer => new ProductAttributeTransformer($baseUrl));
        $container->singleton(ProductAttributeTermTransformer::class, fn (): ProductAttributeTermTransformer => new ProductAttributeTermTransformer($baseUrl));
        $container->singleton(ProductReviewTransformer::class, fn (): ProductReviewTransformer => new ProductReviewTransformer($baseUrl));
        $container->singleton(ProductTagTransformer::class, fn (): ProductTagTransformer => new ProductTagTransformer($baseUrl));
        $container->singleton(ShippingClassTransformer::class, fn (): ShippingClassTransformer => new ShippingClassTransformer($baseUrl));
        $container->singleton(ShippingZoneTransformer::class, fn (): ShippingZoneTransformer => new ShippingZoneTransformer($baseUrl));
        $container->singleton(ShippingZoneLocationTransformer::class, fn (): ShippingZoneLocationTransformer => new ShippingZoneLocationTransformer($baseUrl));
        $container->singleton(ShippingZoneMethodTransformer::class, fn (): ShippingZoneMethodTransformer => new ShippingZoneMethodTransformer($baseUrl));
        $container->singleton(TaxClassTransformer::class, fn (): TaxClassTransformer => new TaxClassTransformer($baseUrl));
        $container->singleton(TaxRateTransformer::class, fn (): TaxRateTransformer => new TaxRateTransformer($baseUrl));

        // Controllers
        $container->singleton(ProductController::class, fn (): ProductController => new ProductController(
            $container->get(ProductRepository::class),
            $container->get(ProductTransformer::class),
            $baseUrl,
        ));
        $container->singleton(ProductVariationController::class, fn (): ProductVariationController => new ProductVariationController(
            $container->get(ProductRepository::class),
            $container->get(ProductVariationRepository::class),
            $container->get(ProductVariationTransformer::class),
        ));
        $container->singleton(ProductAttributeController::class, fn (): ProductAttributeController => new ProductAttributeController(
            $container->get(ProductAttributeRepository::class),
            $container->get(ProductAttributeTransformer::class),
            $baseUrl,
        ));
        $container->singleton(ProductAttributeTermController::class, fn (): ProductAttributeTermController => new ProductAttributeTermController(
            $container->get(ProductAttributeRepository::class),
            $container->get(ProductAttributeTermRepository::class),
            $container->get(ProductAttributeTermTransformer::class),
        ));
        $container->singleton(ProductCategoryController::class, fn (): ProductCategoryController => new ProductCategoryController(
            $container->get(ProductCategoryRepository::class),
            $container->get(CategoryTransformer::class),
            $baseUrl,
        ));
        $container->singleton(ProductTagController::class, fn (): ProductTagController => new ProductTagController(
            $container->get(ProductTagRepository::class),
            $container->get(ProductTagTransformer::class),
            $baseUrl,
        ));
        $container->singleton(ProductReviewController::class, fn (): ProductReviewController => new ProductReviewController(
            $container->get(ProductRepository::class),
            $container->get(ProductReviewRepository::class),
            $container->get(ProductReviewTransformer::class),
        ));
        $container->singleton(ShippingClassController::class, fn (): ShippingClassController => new ShippingClassController(
            $container->get(ShippingClassRepository::class),
            $container->get(ShippingClassTransformer::class),
            $baseUrl,
        ));
        $container->singleton(CustomerController::class, fn (): CustomerController => new CustomerController(
            $container->get(CustomerRepository::class),
            $container->get(CustomerAddressRepository::class),
            $container->get(CustomerTransformer::class),
            $container->get(CustomerAddressTransformer::class),
            $baseUrl,
        ));
        $container->singleton(CustomerAuthController::class, fn (): CustomerAuthController => new CustomerAuthController(
            $container->get(CustomerRepository::class),
            $container->get(JwtAuth::class),
        ));
        $container->singleton(CouponController::class, fn (): CouponController => new CouponController(
            $container->get(CouponRepository::class),
            $container->get(CouponTransformer::class),
            $baseUrl,
        ));
        $container->singleton(TaxClassController::class, fn (): TaxClassController => new TaxClassController(
            $container->get(TaxClassRepository::class),
            $container->get(TaxClassTransformer::class),
            $baseUrl,
        ));
        $container->singleton(TaxRateController::class, fn (): TaxRateController => new TaxRateController(
            $container->get(TaxClassRepository::class),
            $container->get(TaxRateRepository::class),
            $container->get(TaxRateTransformer::class),
            $baseUrl,
        ));
        $container->singleton(OrderController::class, fn (): OrderController => new OrderController(
            $container->get(OrderRepository::class),
            $container->get(ProductRepository::class),
            $container->get(ProductVariationRepository::class),
            $container->get(CouponRepository::class),
            $container->get(TaxRateRepository::class),
            $container->get(TaxClassRepository::class),
            $container->get(CustomerRepository::class),
            $container->get(PaymentGatewayRepository::class),
            $container->get(OrderTransformer::class),
            $container->get(TaxCalculator::class),
            $container->get(ShippingCalculator::class),
            $baseUrl,
        ));
        $container->singleton(OrderNoteController::class, fn (): OrderNoteController => new OrderNoteController(
            $container->get(OrderRepository::class),
            $container->get(OrderNoteRepository::class),
            $container->get(OrderNoteTransformer::class),
            $baseUrl,
        ));
        $container->singleton(OrderRefundController::class, fn (): OrderRefundController => new OrderRefundController(
            $container->get(OrderRepository::class),
            $container->get(OrderRefundRepository::class),
            $container->get(OrderRefundTransformer::class),
            $baseUrl,
        ));
        $container->singleton(ShippingZoneController::class, fn (): ShippingZoneController => new ShippingZoneController(
            $container->get(ShippingZoneRepository::class),
            $container->get(ShippingZoneLocationRepository::class),
            $container->get(ShippingZoneMethodRepository::class),
            $container->get(ShippingZoneTransformer::class),
            $container->get(ShippingZoneLocationTransformer::class),
            $container->get(ShippingZoneMethodTransformer::class),
            $baseUrl,
        ));
        $container->singleton(PaymentGatewayController::class, fn (): PaymentGatewayController => new PaymentGatewayController(
            $container->get(PaymentGatewayRepository::class),
            $container->get(PaymentGatewayTransformer::class),
            $baseUrl,
        ));
        $container->singleton(SettingRepository::class, fn (): SettingRepository => new SettingRepository(
            $container->get(ConnectionInterface::class),
        ));
        $container->singleton(SettingTransformer::class, fn (): SettingTransformer => new SettingTransformer($baseUrl));
        $container->singleton(SettingGroupTransformer::class, fn (): SettingGroupTransformer => new SettingGroupTransformer($baseUrl));
        $container->singleton(SettingsController::class, fn (): SettingsController => new SettingsController(
            $container->get(SettingRepository::class),
            $container->get(SettingGroupTransformer::class),
            $container->get(SettingTransformer::class),
            $baseUrl,
        ));
        $container->singleton(WebhookRepository::class, fn (): WebhookRepository => new WebhookRepository(
            $container->get(ConnectionInterface::class),
        ));
        $container->singleton(WebhookTransformer::class, fn (): WebhookTransformer => new WebhookTransformer($baseUrl));
        $container->singleton(WebhookDeliveryTransformer::class, fn (): WebhookDeliveryTransformer => new WebhookDeliveryTransformer($baseUrl));
        $container->singleton(WebhookController::class, fn (): WebhookController => new WebhookController(
            $container->get(WebhookRepository::class),
            $container->get(WebhookTransformer::class),
            $container->get(WebhookDeliveryTransformer::class),
            $baseUrl,
        ));
        $container->singleton(WebhookDispatcher::class, fn (): WebhookDispatcher => new WebhookDispatcher(
            $container->get(WebhookRepository::class),
            $container->get(ConnectionInterface::class),
            (int) ($app->config()->get('webhook.max_attempts') ?? 5),
            (int) ($app->config()->get('webhook.base_delay_ms') ?? 1000),
            (int) ($app->config()->get('webhook.max_delay_ms') ?? 300000),
            (int) ($app->config()->get('webhook.timeout_ms') ?? 10000),
        ));
        $container->singleton(WebhookWorkerCommand::class, fn (): WebhookWorkerCommand => new WebhookWorkerCommand());
        $container->singleton(TaxCalculator::class, fn (): TaxCalculator => new TaxCalculator(
            $container->get(TaxRateRepository::class),
            (int) ($app->config()->get('tax.decimals') ?? 2),
            (string) ($app->config()->get('tax.rounding_mode') ?? 'line'),
            (bool) ($app->config()->get('tax.prices_include_tax') ?? false),
        ));
        $container->singleton(ShippingCalculator::class, fn (): ShippingCalculator => new ShippingCalculator(
            $container->get(ShippingZoneRepository::class),
            $container->get(ShippingZoneLocationRepository::class),
            $container->get(ShippingZoneMethodRepository::class),
            (int) ($app->config()->get('wc.dp') ?? 2),
        ));
    }

    private static function registerMiddleware(Application $app): void
    {
        $container = $app->container();

        $security = $app->config()->get('security') ?? [];
        $security = is_array($security) ? $security : [];

        $ratelimit = $security['ratelimit'] ?? [];
        $ratelimit = is_array($ratelimit) ? $ratelimit : [];

        $container->singleton(SecurityHeadersConfiguration::class, new SecurityHeadersConfiguration(
            contentSecurityPolicy: "default-src 'none'; frame-ancestors 'none'",
            strictTransportSecurity: 'max-age=31536000; includeSubDomains',
        ));

        $container->singleton(RateLimitMiddleware::class, fn (): RateLimitMiddleware => new RateLimitMiddleware(
            $container->get(CacheInterface::class),
            (int) ($ratelimit['max_attempts'] ?? 240),
            (int) ($ratelimit['window_seconds'] ?? 60),
        ));

        // Outermost first. WcKernelErrorMiddleware must sit innermost so it
        // rewrites Kernel 404/405 responses before headers/body escape.
        $app->addMiddleware(SecurityHeadersMiddleware::class);
        $app->addMiddleware(BotDetectionMiddleware::class);
        $app->addMiddleware(MetricsMiddleware::class);
        $app->addMiddleware(RateLimitMiddleware::class);
        $app->addMiddleware(ExposeHeadersMiddleware::class);
        $app->addMiddleware(CacheHeadersMiddleware::class);
        $app->addMiddleware(CompressionMiddleware::class);
        $app->addMiddleware(WcKernelErrorMiddleware::class);
    }

    private static function registerRoutes(Application $app): void
    {
        $router = $app->router();
        $prefix = self::API_PREFIX;
        $wc = [WcAuthenticationMiddleware::class];
        $jwt = [JwtAuthenticationMiddleware::class];

        // --- Public infrastructure endpoints ---
        $router->get('/', static function (Request $request) use ($app): Response {
            $routes = [];
            foreach ($app->router()->routes() as $route) {
                $routes[$route->pattern()][] = $route->method();
            }

            return Response::json([
                'name' => $app->config()->get('app.name') ?? 'WooCommerce API (FlintPHP)',
                'namespace' => 'fc/v3',
                'routes' => $routes,
            ]);
        });

        $router->get('/health', [HealthChecker::class, 'toResponse']);
        $router->get('/metrics', [PrometheusExporter::class, 'toResponse']);

        // --- Products ---
        $router->get($prefix . '/products', [ProductController::class, 'index'], null, $wc);
        $router->post($prefix . '/products', [ProductController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/{id}', [ProductController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/{id}', [ProductController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/{id}', [ProductController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/{id}', [ProductController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/{id}', [ProductController::class, 'destroy'], null, $wc);

        // --- Product variations (nested) ---
        $router->get($prefix . '/products/{productId}/variations', [ProductVariationController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/{productId}/variations', [ProductVariationController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/{productId}/variations/{id}', [ProductVariationController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/{productId}/variations/{id}', [ProductVariationController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/{productId}/variations/{id}', [ProductVariationController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/{productId}/variations/{id}', [ProductVariationController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/{productId}/variations/{id}', [ProductVariationController::class, 'destroy'], null, $wc);

        // --- Global attributes ---
        $router->get($prefix . '/products/attributes', [ProductAttributeController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/attributes', [ProductAttributeController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/attributes/{id}', [ProductAttributeController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/attributes/{id}', [ProductAttributeController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/attributes/{id}', [ProductAttributeController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/attributes/{id}', [ProductAttributeController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/attributes/{id}', [ProductAttributeController::class, 'destroy'], null, $wc);

        // --- Attribute terms (nested) ---
        $router->get($prefix . '/products/attributes/{attributeId}/terms', [ProductAttributeTermController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/attributes/{attributeId}/terms', [ProductAttributeTermController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/attributes/{attributeId}/terms/{id}', [ProductAttributeTermController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/attributes/{attributeId}/terms/{id}', [ProductAttributeTermController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/attributes/{attributeId}/terms/{id}', [ProductAttributeTermController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/attributes/{attributeId}/terms/{id}', [ProductAttributeTermController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/attributes/{attributeId}/terms/{id}', [ProductAttributeTermController::class, 'destroy'], null, $wc);

        // --- Categories ---
        $router->get($prefix . '/products/categories', [ProductCategoryController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/categories', [ProductCategoryController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/categories/{id}', [ProductCategoryController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/categories/{id}', [ProductCategoryController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/categories/{id}', [ProductCategoryController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/categories/{id}', [ProductCategoryController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/categories/{id}', [ProductCategoryController::class, 'destroy'], null, $wc);

        // --- Tags ---
        $router->get($prefix . '/products/tags', [ProductTagController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/tags', [ProductTagController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/tags/{id}', [ProductTagController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/tags/{id}', [ProductTagController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/tags/{id}', [ProductTagController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/tags/{id}', [ProductTagController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/tags/{id}', [ProductTagController::class, 'destroy'], null, $wc);

        // --- Reviews (nested) ---
        $router->get($prefix . '/products/{productId}/reviews', [ProductReviewController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/{productId}/reviews', [ProductReviewController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/{productId}/reviews/{id}', [ProductReviewController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/{productId}/reviews/{id}', [ProductReviewController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/{productId}/reviews/{id}', [ProductReviewController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/{productId}/reviews/{id}', [ProductReviewController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/{productId}/reviews/{id}', [ProductReviewController::class, 'destroy'], null, $wc);

        // --- Shipping classes ---
        $router->get($prefix . '/products/shipping_classes', [ShippingClassController::class, 'index'], null, $wc);
        $router->post($prefix . '/products/shipping_classes', [ShippingClassController::class, 'store'], null, $wc);
        $router->get($prefix . '/products/shipping_classes/{id}', [ShippingClassController::class, 'show'], null, $wc);
        $router->put($prefix . '/products/shipping_classes/{id}', [ShippingClassController::class, 'update'], null, $wc);
        $router->post($prefix . '/products/shipping_classes/{id}', [ShippingClassController::class, 'update'], null, $wc);
        $router->patch($prefix . '/products/shipping_classes/{id}', [ShippingClassController::class, 'update'], null, $wc);
        $router->delete($prefix . '/products/shipping_classes/{id}', [ShippingClassController::class, 'destroy'], null, $wc);

        // --- Customers ---
        $router->get($prefix . '/customers', [CustomerController::class, 'index'], null, $wc);
        $router->post($prefix . '/customers', [CustomerController::class, 'store'], null, $wc);
        $router->get($prefix . '/customers/{id}', [CustomerController::class, 'show'], null, $wc);
        $router->put($prefix . '/customers/{id}', [CustomerController::class, 'update'], null, $wc);
        $router->post($prefix . '/customers/{id}', [CustomerController::class, 'update'], null, $wc);
        $router->patch($prefix . '/customers/{id}', [CustomerController::class, 'update'], null, $wc);
        $router->delete($prefix . '/customers/{id}', [CustomerController::class, 'destroy'], null, $wc);

        // Customer addresses (nested)
        $router->get($prefix . '/customers/{customerId}/addresses', [CustomerController::class, 'addresses'], null, $wc);
        $router->post($prefix . '/customers/{customerId}/addresses', [CustomerController::class, 'createAddress'], null, $wc);
        $router->get($prefix . '/customers/{customerId}/addresses/{id}', [CustomerController::class, 'address'], null, $wc);
        $router->put($prefix . '/customers/{customerId}/addresses/{id}', [CustomerController::class, 'updateAddress'], null, $wc);
        $router->post($prefix . '/customers/{customerId}/addresses/{id}', [CustomerController::class, 'updateAddress'], null, $wc);
        $router->patch($prefix . '/customers/{customerId}/addresses/{id}', [CustomerController::class, 'updateAddress'], null, $wc);
        $router->delete($prefix . '/customers/{customerId}/addresses/{id}', [CustomerController::class, 'deleteAddress'], null, $wc);

        // Storefront JWT (public)
        $router->post($prefix . '/customers/jwt/token', [CustomerAuthController::class, 'login']);
        $router->post($prefix . '/customers/jwt/token/refresh', [CustomerAuthController::class, 'refresh']);
        $router->post($prefix . '/customers/jwt/register', [CustomerAuthController::class, 'register']);
        $router->get($prefix . '/customers/jwt/me', [CustomerAuthController::class, 'me'], null, $jwt);

        // --- Coupons ---
        $router->get($prefix . '/coupons', [CouponController::class, 'index'], null, $wc);
        $router->post($prefix . '/coupons', [CouponController::class, 'store'], null, $wc);
        $router->get($prefix . '/coupons/{id}', [CouponController::class, 'show'], null, $wc);
        $router->put($prefix . '/coupons/{id}', [CouponController::class, 'update'], null, $wc);
        $router->post($prefix . '/coupons/{id}', [CouponController::class, 'update'], null, $wc);
        $router->patch($prefix . '/coupons/{id}', [CouponController::class, 'update'], null, $wc);
        $router->delete($prefix . '/coupons/{id}', [CouponController::class, 'destroy'], null, $wc);

        // --- Tax classes (WC: /taxes/classes) ---
        $router->get($prefix . '/taxes/classes', [TaxClassController::class, 'index'], null, $wc);
        $router->post($prefix . '/taxes/classes', [TaxClassController::class, 'store'], null, $wc);
        $router->get($prefix . '/taxes/classes/{id}', [TaxClassController::class, 'show'], null, $wc);
        $router->put($prefix . '/taxes/classes/{id}', [TaxClassController::class, 'update'], null, $wc);
        $router->post($prefix . '/taxes/classes/{id}', [TaxClassController::class, 'update'], null, $wc);
        $router->patch($prefix . '/taxes/classes/{id}', [TaxClassController::class, 'update'], null, $wc);
        $router->delete($prefix . '/taxes/classes/{id}', [TaxClassController::class, 'destroy'], null, $wc);

        // --- Tax rates (nested under classes) ---
        $router->get($prefix . '/taxes/classes/{taxClassId}/rates', [TaxRateController::class, 'index'], null, $wc);
        $router->post($prefix . '/taxes/classes/{taxClassId}/rates', [TaxRateController::class, 'store'], null, $wc);
        $router->get($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [TaxRateController::class, 'show'], null, $wc);
        $router->put($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [TaxRateController::class, 'update'], null, $wc);
        $router->post($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [TaxRateController::class, 'update'], null, $wc);
        $router->patch($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [TaxRateController::class, 'update'], null, $wc);
        $router->delete($prefix . '/taxes/classes/{taxClassId}/rates/{id}', [TaxRateController::class, 'destroy'], null, $wc);

        // --- Orders ---
        $router->get($prefix . '/orders', [OrderController::class, 'index'], null, $wc);
        $router->post($prefix . '/orders', [OrderController::class, 'store'], null, $wc);
        $router->get($prefix . '/orders/{id}', [OrderController::class, 'show'], null, $wc);
        $router->put($prefix . '/orders/{id}', [OrderController::class, 'update'], null, $wc);
        $router->post($prefix . '/orders/{id}', [OrderController::class, 'update'], null, $wc);
        $router->patch($prefix . '/orders/{id}', [OrderController::class, 'update'], null, $wc);
        $router->delete($prefix . '/orders/{id}', [OrderController::class, 'destroy'], null, $wc);

        // Order notes (nested)
        $router->get($prefix . '/orders/{orderId}/notes', [OrderNoteController::class, 'index'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/notes', [OrderNoteController::class, 'store'], null, $wc);
        $router->get($prefix . '/orders/{orderId}/notes/{id}', [OrderNoteController::class, 'show'], null, $wc);
        $router->put($prefix . '/orders/{orderId}/notes/{id}', [OrderNoteController::class, 'update'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/notes/{id}', [OrderNoteController::class, 'update'], null, $wc);
        $router->patch($prefix . '/orders/{orderId}/notes/{id}', [OrderNoteController::class, 'update'], null, $wc);
        $router->delete($prefix . '/orders/{orderId}/notes/{id}', [OrderNoteController::class, 'destroy'], null, $wc);

        // Order refunds (nested)
        $router->get($prefix . '/orders/{orderId}/refunds', [OrderRefundController::class, 'index'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/refunds', [OrderRefundController::class, 'store'], null, $wc);
        $router->get($prefix . '/orders/{orderId}/refunds/{id}', [OrderRefundController::class, 'show'], null, $wc);
        $router->put($prefix . '/orders/{orderId}/refunds/{id}', [OrderRefundController::class, 'update'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/refunds/{id}', [OrderRefundController::class, 'update'], null, $wc);
        $router->patch($prefix . '/orders/{orderId}/refunds/{id}', [OrderRefundController::class, 'update'], null, $wc);
        $router->delete($prefix . '/orders/{orderId}/refunds/{id}', [OrderRefundController::class, 'destroy'], null, $wc);

        // --- Shipping zones ---
        $router->get($prefix . '/shipping/zones', [ShippingZoneController::class, 'index'], null, $wc);
        $router->post($prefix . '/shipping/zones', [ShippingZoneController::class, 'store'], null, $wc);
        $router->get($prefix . '/shipping/zones/{id}', [ShippingZoneController::class, 'show'], null, $wc);
        $router->put($prefix . '/shipping/zones/{id}', [ShippingZoneController::class, 'update'], null, $wc);
        $router->post($prefix . '/shipping/zones/{id}', [ShippingZoneController::class, 'update'], null, $wc);
        $router->patch($prefix . '/shipping/zones/{id}', [ShippingZoneController::class, 'update'], null, $wc);
        $router->delete($prefix . '/shipping/zones/{id}', [ShippingZoneController::class, 'destroy'], null, $wc);

        // Zone locations (nested)
        $router->get($prefix . '/shipping/zones/{zoneId}/locations', [ShippingZoneController::class, 'locationsIndex'], null, $wc);
        $router->post($prefix . '/shipping/zones/{zoneId}/locations', [ShippingZoneController::class, 'locationsStore'], null, $wc);
        $router->get($prefix . '/shipping/zones/{zoneId}/locations/{id}', [ShippingZoneController::class, 'locationsShow'], null, $wc);
        $router->put($prefix . '/shipping/zones/{zoneId}/locations/{id}', [ShippingZoneController::class, 'locationsUpdate'], null, $wc);
        $router->post($prefix . '/shipping/zones/{zoneId}/locations/{id}', [ShippingZoneController::class, 'locationsUpdate'], null, $wc);
        $router->patch($prefix . '/shipping/zones/{zoneId}/locations/{id}', [ShippingZoneController::class, 'locationsUpdate'], null, $wc);
        $router->delete($prefix . '/shipping/zones/{zoneId}/locations/{id}', [ShippingZoneController::class, 'locationsDestroy'], null, $wc);

        // Zone methods (nested)
        $router->get($prefix . '/shipping/zones/{zoneId}/methods', [ShippingZoneController::class, 'methodsIndex'], null, $wc);
        $router->post($prefix . '/shipping/zones/{zoneId}/methods', [ShippingZoneController::class, 'methodsStore'], null, $wc);
        $router->get($prefix . '/shipping/zones/{zoneId}/methods/{id}', [ShippingZoneController::class, 'methodsShow'], null, $wc);
        $router->put($prefix . '/shipping/zones/{zoneId}/methods/{id}', [ShippingZoneController::class, 'methodsUpdate'], null, $wc);
        $router->post($prefix . '/shipping/zones/{zoneId}/methods/{id}', [ShippingZoneController::class, 'methodsUpdate'], null, $wc);
        $router->patch($prefix . '/shipping/zones/{zoneId}/methods/{id}', [ShippingZoneController::class, 'methodsUpdate'], null, $wc);
        $router->delete($prefix . '/shipping/zones/{zoneId}/methods/{id}', [ShippingZoneController::class, 'methodsDestroy'], null, $wc);

        // --- Payment gateways ---
        $router->get($prefix . '/payment_gateways', [PaymentGatewayController::class, 'index'], null, $wc);
        $router->post($prefix . '/payment_gateways', [PaymentGatewayController::class, 'store'], null, $wc);
        $router->get($prefix . '/payment_gateways/{id}', [PaymentGatewayController::class, 'show'], null, $wc);
        $router->put($prefix . '/payment_gateways/{id}', [PaymentGatewayController::class, 'update'], null, $wc);
        $router->post($prefix . '/payment_gateways/{id}', [PaymentGatewayController::class, 'update'], null, $wc);
        $router->patch($prefix . '/payment_gateways/{id}', [PaymentGatewayController::class, 'update'], null, $wc);
        $router->delete($prefix . '/payment_gateways/{id}', [PaymentGatewayController::class, 'destroy'], null, $wc);

        // --- Settings ---
        $router->get($prefix . '/settings', [SettingsController::class, 'index'], null, $wc);
        $router->get($prefix . '/settings/{group}', [SettingsController::class, 'group'], null, $wc);
        $router->get($prefix . '/settings/{group}/{id}', [SettingsController::class, 'show'], null, $wc);
        $router->put($prefix . '/settings/{group}/{id}', [SettingsController::class, 'update'], null, $wc);
        $router->post($prefix . '/settings/{group}/{id}', [SettingsController::class, 'update'], null, $wc);
        $router->patch($prefix . '/settings/{group}/{id}', [SettingsController::class, 'update'], null, $wc);

        // --- Webhooks ---
        $router->get($prefix . '/webhooks', [WebhookController::class, 'index'], null, $wc);
        $router->post($prefix . '/webhooks', [WebhookController::class, 'store'], null, $wc);
        $router->get($prefix . '/webhooks/{id}', [WebhookController::class, 'show'], null, $wc);
        $router->put($prefix . '/webhooks/{id}', [WebhookController::class, 'update'], null, $wc);
        $router->post($prefix . '/webhooks/{id}', [WebhookController::class, 'update'], null, $wc);
        $router->patch($prefix . '/webhooks/{id}', [WebhookController::class, 'update'], null, $wc);
        $router->delete($prefix . '/webhooks/{id}', [WebhookController::class, 'destroy'], null, $wc);

        // Webhook deliveries (nested)
        $router->get($prefix . '/webhooks/{webhookId}/deliveries', [WebhookController::class, 'deliveriesIndex'], null, $wc);
        $router->get($prefix . '/webhooks/{webhookId}/deliveries/{id}', [WebhookController::class, 'deliveriesShow'], null, $wc);
        $router->post($prefix . '/webhooks/{webhookId}/deliveries/retry', [WebhookController::class, 'deliveriesRetry'], null, $wc);

        // Batch routes LAST: the router gives precedence to the last-registered
        // dynamic route, so these must be registered after the {id} routes
        // they'd otherwise shadow (e.g. POST .../notes/batch vs .../notes/{id}).
        $router->post($prefix . '/products/batch', [ProductController::class, 'batch'], null, $wc);
        $router->post($prefix . '/products/{productId}/variations/batch', [ProductVariationController::class, 'batch'], null, $wc);
        $router->post($prefix . '/products/attributes/batch', [ProductAttributeController::class, 'batch'], null, $wc);
        $router->post($prefix . '/products/attributes/{attributeId}/terms/batch', [ProductAttributeTermController::class, 'batch'], null, $wc);
        $router->post($prefix . '/products/categories/batch', [ProductCategoryController::class, 'batch'], null, $wc);
        $router->post($prefix . '/products/tags/batch', [ProductTagController::class, 'batch'], null, $wc);
        $router->post($prefix . '/products/{productId}/reviews/batch', [ProductReviewController::class, 'batch'], null, $wc);
        $router->post($prefix . '/products/shipping_classes/batch', [ShippingClassController::class, 'batch'], null, $wc);
        $router->post($prefix . '/coupons/batch', [CouponController::class, 'batch'], null, $wc);
        $router->post($prefix . '/taxes/classes/{taxClassId}/rates/batch', [TaxRateController::class, 'batch'], null, $wc);
        $router->post($prefix . '/orders/batch', [OrderController::class, 'batch'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/notes/batch', [OrderNoteController::class, 'batch'], null, $wc);
        $router->post($prefix . '/orders/{orderId}/refunds/batch', [OrderRefundController::class, 'batch'], null, $wc);
        $router->post($prefix . '/payment_gateways/batch', [PaymentGatewayController::class, 'batch'], null, $wc);
        $router->post($prefix . '/settings/{group}/batch', [SettingsController::class, 'batch'], null, $wc);
        $router->post($prefix . '/webhooks/batch', [WebhookController::class, 'batch'], null, $wc);
    }
}
