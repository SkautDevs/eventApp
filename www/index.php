<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

App\Kernel::boot($_SERVER['REQUEST_URI'] ?? '/')->run();
