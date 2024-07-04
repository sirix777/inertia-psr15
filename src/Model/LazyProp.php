<?php

declare(strict_types=1);

namespace Cherif\InertiaPsr15\Model;

/**
 * NOTE: this is similar to Laravel Inertia\LazyProp
 */
final class LazyProp
{
    /**
     * @var callable $callback
     */
    protected $callback;

    public function __construct(
        callable $callable
    ) {
        $this->callback = $callable;
    }

    /**
     * @return mixed
     */
    public function __invoke()
    {
        return call_user_func($this->callback);
    }
}
