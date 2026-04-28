<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel\Internal;

use Exception;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionProperty;

use function array_values;
use function is_string;
use function str_contains;
use function str_replace;

/**
 * @internal Trims this library's own + Laravel test-concern frames out of an
 *           assertion-failure trace so a contract-test failure points at the
 *           user's test line, not at vendor code (issue #131).
 */
final class StackTraceFilter
{
    /**
     * Frame `file` substrings (forward-slash form) that mark frames as
     * library/framework noise and should be dropped from the trace.
     *
     * - `studio-design/openapi-contract-testing/src/` covers the composer-installed location.
     * - `openapi-contract-testing/src/Laravel/`, `…/src/Internal/`, `…/src/PHPUnit/`
     *   cover path-repo / monorepo dev installs where the vendor prefix is absent.
     * - The two Laravel entries cover the testing concerns that always sit
     *   between the trait hook and the user test method.
     *
     * PHPUnit's own internal frames (Assert, Constraint, TestCase) are
     * already filtered by PHPUnit's `ExcludeList` at display time, so they
     * are not listed here.
     *
     * @var list<string>
     */
    private const DROP_PATTERNS = [
        '/studio-design/openapi-contract-testing/src/',
        '/openapi-contract-testing/src/Laravel/',
        '/openapi-contract-testing/src/Internal/',
        '/openapi-contract-testing/src/PHPUnit/',
        '/Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php',
        '/Illuminate/Testing/TestResponse.php',
    ];
    private static ?ReflectionProperty $traceProperty = null;

    /**
     * Filter library/framework frames out of an assertion failure's trace
     * and re-throw. If filtering would empty the trace, the original trace
     * is preserved — a clean trace is never worth less debug info than what
     * we started with.
     *
     * Only `trace` is rewritten. `getFile()` / `getLine()` are left alone
     * because the displayed PHPUnit failure block lists frames directly and
     * does not surface a single "in /file:line" header — rewriting them
     * would only affect programmatic inspection, where the natural target
     * (the throw site) is rarely the user's test line anyway.
     */
    public static function rethrowWithCleanTrace(AssertionFailedError $exception): never
    {
        /** @var list<array<string, mixed>> $original */
        $original = $exception->getTrace();
        $filtered = self::filterFrames($original);

        if ($filtered !== [] && $filtered !== $original) {
            self::traceProperty()->setValue($exception, $filtered);
        }

        throw $exception;
    }

    /**
     * Drop frames whose `file` matches any DROP_PATTERNS entry. Frames without
     * a `file` key (closures, internal calls) are kept — we cannot tell where
     * they originate, and dropping them would risk hiding user code.
     *
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array<string, mixed>>
     */
    public static function filterFrames(array $trace): array
    {
        $kept = [];
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file)) {
                $kept[] = $frame;

                continue;
            }

            $normalized = str_replace('\\', '/', $file);
            $drop = false;
            foreach (self::DROP_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    $drop = true;

                    break;
                }
            }
            if (!$drop) {
                $kept[] = $frame;
            }
        }

        return array_values($kept);
    }

    private static function traceProperty(): ReflectionProperty
    {
        return self::$traceProperty ??= new ReflectionProperty(Exception::class, 'trace');
    }
}
