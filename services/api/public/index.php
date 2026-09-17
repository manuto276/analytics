<?php

declare(strict_types=1);

use Analytics\Kernel\AppFactory;
use Analytics\Kernel\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

AppFactory::create(Kernel::container())->run();
