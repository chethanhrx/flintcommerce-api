<?php

declare(strict_types=1);

use App\AppFactory;

$basePath = dirname(__DIR__);

$storageDir = $basePath . '/storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}

return AppFactory::create($basePath);
