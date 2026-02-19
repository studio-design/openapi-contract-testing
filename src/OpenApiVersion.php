<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function is_string;
use function str_starts_with;

enum OpenApiVersion: string
{
    case V3_0 = '3.0';
    case V3_1 = '3.1';

    /**
     * Detect OAS version from a parsed spec array.
     *
     * @param array<string, mixed> $spec
     */
    public static function fromSpec(array $spec): self
    {
        $version = $spec['openapi'] ?? '';

        if (is_string($version) && str_starts_with($version, '3.1')) {
            return self::V3_1;
        }

        return self::V3_0;
    }
}
