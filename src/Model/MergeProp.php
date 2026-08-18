<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Model;

use Sirix\InertiaPsr15\Exception\InvalidInertiaArgumentException;

use function array_fill_keys;
use function array_is_list;
use function count;
use function is_string;
use function preg_match;
use function strlen;

final class MergeProp extends Prop
{
    /** @var list<array{mode: 'append'|'deep'|'prepend', path: string, matchOn: ?string}> */
    private array $operations = [];

    /** @param null|array<int|string, string>|string $paths */
    public function append(array|string|null $paths = null, ?string $matchOn = null): self
    {
        return $this->withOperations('append', $paths, $matchOn);
    }

    /** @param null|array<int|string, string>|string $paths */
    public function prepend(array|string|null $paths = null, ?string $matchOn = null): self
    {
        return $this->withOperations('prepend', $paths, $matchOn);
    }

    public function deepMerge(): self
    {
        if ([] !== $this->operations) {
            throw new InvalidInertiaArgumentException('Deep merge cannot be combined with append or prepend operations.');
        }

        return $this->withOperations('deep', null, null);
    }

    public function matchOn(string $path): self
    {
        $this->assertSafePath($path, 'Match path');

        if ([] === $this->operations) {
            return $this->withOperations('append', null, $path);
        }

        $prop                               = clone $this;
        $last                               = count($prop->operations) - 1;
        if ('deep' === $prop->operations[$last]['mode']) {
            throw new InvalidInertiaArgumentException('Deep merge operations cannot use a match path.');
        }

        if (null !== $prop->operations[$last]['matchOn']) {
            throw new InvalidInertiaArgumentException('A merge operation can only have one match path.');
        }
        $prop->operations[$last]['matchOn'] = $path;

        return $prop;
    }

    /** @return list<array{mode: 'append'|'deep'|'prepend', path: string, matchOn: ?string}> */
    public function operations(): array
    {
        $rootOperation  = null;
        $pathOperations = [];
        foreach ($this->operations as $operation) {
            if ('deep' === $operation['mode']) {
                return [$operation];
            }

            if ('' === $operation['path']) {
                $rootOperation = $operation;

                continue;
            }

            $pathOperations[] = $operation;
        }

        if ([] !== $pathOperations) {
            return $pathOperations;
        }

        return [$rootOperation ?? [
            'mode'    => 'append',
            'path'    => '',
            'matchOn' => null,
        ]];
    }

    /**
     * @param 'append'|'deep'|'prepend'            $mode
     * @param null|array<int|string, mixed>|string $paths
     */
    private function withOperations(string $mode, array|string|null $paths, ?string $matchOn): self
    {
        foreach ($this->operations as $operation) {
            if ('deep' === $operation['mode']) {
                throw new InvalidInertiaArgumentException('Deep merge cannot be combined with append or prepend operations.');
            }
        }

        $paths ??= [''];
        if (is_string($paths)) {
            $paths = [
                $paths => $matchOn,
            ];
        } elseif (array_is_list($paths)) {
            $paths = array_fill_keys($paths, $matchOn);
        }

        $prop = clone $this;
        foreach ($paths as $path => $field) {
            if (! is_string($path)) {
                throw new InvalidInertiaArgumentException('Merge paths must be strings.');
            }

            $this->assertSafePath($path, 'Merge path', true);
            if (null !== $field) {
                if (! is_string($field)) {
                    throw new InvalidInertiaArgumentException('Merge match paths must be strings or null.');
                }

                $this->assertSafePath($field, 'Merge match path');
            }

            $prop->operations[] = [
                'mode'    => $mode,
                'path'    => $path,
                'matchOn' => $field,
            ];
        }

        return $prop;
    }

    private function assertSafePath(string $path, string $label, bool $allowEmpty = false): void
    {
        if (($allowEmpty && '' === $path) || (255 >= strlen($path) && 1 === preg_match('/^[^.\x00-\x1F\x7F]+(?:\.[^.\x00-\x1F\x7F]+)*$/', $path))) {
            return;
        }

        throw new InvalidInertiaArgumentException($label . ' must be a safe dot path.');
    }
}
