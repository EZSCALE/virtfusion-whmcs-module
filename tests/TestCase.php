<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Shared helpers for reaching the module's internals.
 *
 * StockControl and Module keep their capacity maths private and construct their own
 * collaborators, so there is no seam to inject through for the pure-calculation paths.
 * Reflection is the pragmatic way in: it tests the real code rather than a copy of it,
 * and these are the exact methods whose arithmetic has produced production incidents.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['vfd_test_log'] = [];
    }

    /** Instantiate without running the constructor, which reaches for the WHMCS database. */
    protected function bare(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /** Call a private/protected method, passing the final argument by reference. */
    protected function callRef(object|string $target, string $method, array $args, &$ref)
    {
        $m = new ReflectionMethod(is_string($target) ? $target : $target::class, $method);
        $m->setAccessible(true);
        $args[] = &$ref;

        return $m->invokeArgs(is_string($target) ? null : $target, $args);
    }

    /** Call a private/protected method by value. */
    protected function call(object|string $target, string $method, array $args = [])
    {
        $m = new ReflectionMethod(is_string($target) ? $target : $target::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(is_string($target) ? null : $target, $args);
    }

    /** Every logged action tag, in order. */
    protected function loggedActions(): array
    {
        return array_column($GLOBALS['vfd_test_log'], 'action');
    }

    /** The log entries recorded for one action tag. */
    protected function logsFor(string $action): array
    {
        return array_values(array_filter(
            $GLOBALS['vfd_test_log'],
            static fn (array $e): bool => $e['action'] === $action,
        ));
    }
}
