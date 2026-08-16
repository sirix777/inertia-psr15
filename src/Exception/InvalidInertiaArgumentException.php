<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Exception;

use InvalidArgumentException;

/**
 * Thrown when a value cannot be represented safely by the Inertia protocol.
 */
class InvalidInertiaArgumentException extends InvalidArgumentException implements InertiaExceptionInterface {}
