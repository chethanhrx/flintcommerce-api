<?php

declare(strict_types=1);

namespace App;

use App\Bootstrap\MiddlewareRegistrar;
use App\Bootstrap\RouteRegistrar;
use App\Bootstrap\ServiceRegistrar;
use App\Http\WcJsonExceptionHandler;
use FlintPHP\Framework\Config\Contract\ConfigRepositoryInterface;
use FlintPHP\Framework\Config\PhpFileLoader;
use FlintPHP\Framework\Console\ConsoleApplication;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Database\DatabaseBootstrapper;
use FlintPHP\Framework\Database\Migration\Migrator;
use FlintPHP\Framework\Foundation\Application;
use FlintPHP\Framework\Orm\OrmBootstrapper;
use FlintPHP\Framework\Orm\OrmManager;
use FlintPHP\Framework\Validation\ValidationBootstrapper;
use App\Migrations\Migrations;

final class AppFactory
{
    public static function create(string $basePath, ?ConfigRepositoryInterface $config = null): Application
    {
        $config ??= new \FlintPHP\Framework\Config\ConfigRepository(\FlintPHP\Framework\Config\PhpFileLoader::loadDirectory(rtrim($basePath, '/') . '/config'));

        $app = new Application($basePath, $config);

        $app->bootstrapWith([
            new DatabaseBootstrapper(),
            new OrmBootstrapper(),
            new ValidationBootstrapper(),
        ]);

        $logger = $app->container()->get(\FlintPHP\Framework\Observability\Contract\LoggerInterface::class);
        $app->setExceptionHandler(new WcJsonExceptionHandler($logger));

        ServiceRegistrar::register($app);
        MiddlewareRegistrar::register($app);
        RouteRegistrar::register($app);

        return $app;
    }

    public static function console(Application $app): ConsoleApplication
    {
        $console = new ConsoleApplication();
        $container = $app->container();
        $db = $container->get(ConnectionInterface::class);

        $migrator = new Migrator($db);

        $console->register(new \FlintPHP\Framework\Console\Command\MigrateCommand($migrator, Migrations::all()));
        $console->register(new \FlintPHP\Framework\Console\Command\MigrateRollbackCommand($migrator, Migrations::all()));
        $console->register(new \FlintPHP\Framework\Console\Command\MigrateStatusCommand($migrator, Migrations::all()));
        $console->register(new \App\Console\Command\DbSeedCommand($db));
        $console->register(new \App\Console\Command\KeyGenerateCommand(new \App\Domain\Repository\ApiKeyRepository($db, $container->get(OrmManager::class))));
        $console->register(new \App\Console\Command\WebhookWorkerCommand(
            $container->get(\App\Webhook\WebhookDispatcher::class),
            $container->get(\App\Domain\Repository\WebhookRepository::class),
        ));

        return $console;
    }
}
