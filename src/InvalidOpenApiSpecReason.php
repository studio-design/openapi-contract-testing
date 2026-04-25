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
    /**
     * @deprecated Use the more specific external-ref reasons instead:
     *             `LocalRefNotFound`, `LocalRefUnreadable`, `LocalRefRequiresSourceFile`,
     *             `RemoteRefNotImplemented`, or `FileSchemeNotSupported`. Kept for
     *             backwards compatibility with consumers that branched on this case
     *             before the resolver learned to follow external `$ref` targets.
     *             No production code throws this reason any more.
     */
    case ExternalRef;
    case LocalRefNotFound;
    case LocalRefUnreadable;
    case LocalRefRequiresSourceFile;
    /**
     * @deprecated Use `RemoteRefDisallowed` (when `allowRemoteRefs` is false)
     *             or `HttpClientNotConfigured` (when the flag is on but no
     *             PSR-18 client + PSR-17 factory have been provided). Kept
     *             for backwards compatibility with consumers that branched
     *             on this case before HTTP `$ref` resolution shipped.
     *             No production code throws this reason any more.
     */
    case RemoteRefNotImplemented;
    case RemoteRefDisallowed;
    case RemoteRefFetchFailed;
    case HttpClientNotConfigured;
    case FileSchemeNotSupported;
    case EmptyRef;
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
