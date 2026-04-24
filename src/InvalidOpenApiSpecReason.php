<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

/**
 * Categorical reasons an OpenAPI spec is considered broken. Carried on
 * `InvalidOpenApiSpecException` so consumers can branch on the concrete
 * failure kind instead of regex-ing the human-readable message.
 */
enum InvalidOpenApiSpecReason
{
    case ExternalRef;
    case CircularRef;
    case UnresolvableRef;
    case NonStringRef;
    case BareFragmentRef;
    case RootPointerRef;
    case NonObjectRefTarget;
    case MalformedJson;
    case MalformedYaml;
    case NonMappingRoot;
    case YamlLibraryMissing;
    case UnsupportedExtension;
    case BasePathNotConfigured;
}
