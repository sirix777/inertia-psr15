<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class InertiaContainerException extends RuntimeException implements ContainerExceptionInterface, InertiaExceptionInterface {}
