<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AppFactory;
use App\Migrations\Migrations;
use FlintPHP\Framework\Config\ConfigRepository;
use FlintPHP\Framework\Database\ConnectionInterface;
use FlintPHP\Framework\Foundation\Application;
use FlintPHP\Framework\Testing\TestCase as FrameworkTestCase;

/**
 * App test case: full composition against a per-test SQLite file database.
 *
 * A fresh database file + migrations run once per test; the same Application
 * instance (and therefore connection + routes) serves all requests in the test.
 */
abstract class AppTestCase extends FrameworkTestCase
{
    protected Application $app;
    protected ConnectionInterface $db;

    private static int $run = 0;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/wc-api-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        self::$run++;
        $path = sprintf('%s/run-%d.sqlite', $dir, self::$run);
        @unlink($path);

        $config = new ConfigRepository([
            'app' => [
                'url' => 'http://localhost:8000',
                'env' => 'testing',
                'wc' => ['dp' => 2, 'currency' => 'USD'],
            ],
            'database' => ['driver' => 'sqlite', 'database' => $path],
        ]);

        $this->app = AppFactory::create(dirname(__DIR__, 2), $config);
        $this->app->boot();

        $this->db = $this->app->container()->get(ConnectionInterface::class);

        foreach (Migrations::all() as $migration) {
            $migration->up($this->db);
        }
    }

    public function createKernel(): \FlintPHP\Framework\Http\Kernel
    {
        return $this->app->kernel();
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->app);
    }
}
