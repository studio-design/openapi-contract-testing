<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

/**
 * Direction of validation for a schema conversion. Drives asymmetric handling
 * of OpenAPI's `readOnly` / `writeOnly` markers: `readOnly` properties are
 * forbidden in requests, `writeOnly` properties are forbidden in responses.
 */
enum SchemaContext
{
    case Request;
    case Response;
}
