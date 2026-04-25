<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Helpers;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Surfaced by {@see FakeHttpClient} when a test sends a request to a URL
 * that has no registered response. Implements `ClientExceptionInterface`
 * so the resolver's PSR-18 catch path treats it the same as a real
 * network failure (which is how production code would experience it).
 */
final class FakeHttpClientUnexpectedRequest extends RuntimeException implements ClientExceptionInterface {}
