<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use function getenv;
use function mb_strtolower;
use function trim;

enum ConsoleOutput: string
{
    case DEFAULT = 'default';
    case ALL = 'all';
    case UNCOVERED_ONLY = 'uncovered_only';

    /**
     * Resolve the console output mode from environment variable and/or phpunit.xml parameter.
     *
     * Priority: environment variable > parameter > DEFAULT.
     */
    public static function resolve(?string $parameterValue): self
    {
        $envValue = getenv('OPENAPI_CONSOLE_OUTPUT');

        if ($envValue !== false && trim($envValue) !== '') {
            $resolved = self::tryFrom(mb_strtolower(trim($envValue)));

            return $resolved ?? self::DEFAULT;
        }

        if ($parameterValue !== null && trim($parameterValue) !== '') {
            $resolved = self::tryFrom(mb_strtolower(trim($parameterValue)));

            return $resolved ?? self::DEFAULT;
        }

        return self::DEFAULT;
    }
}
