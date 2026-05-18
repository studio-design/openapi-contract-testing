<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidationResult;

/**
 * Outcome of {@see RequestBodyValidator::validate()}.
 *
 * `errors` carries the same string payload the validator previously returned
 * directly (empty list = body acceptable). `skipReason`, when non-null, marks
 * that the validator deliberately did NOT check the body even though a
 * media-type key matched and that key declared a `schema`: the request
 * Content-Type is a non-JSON media type this JSON-Schema engine cannot
 * evaluate (issue #254). `errors` stays empty in that case — it is a skip,
 * not a failure — and {@see OpenApiRequestValidator} turns it into an
 * `OpenApiValidationResult::skipped()` (when no sibling validator failed) so
 * the unvalidated body is not miscounted as a clean pass and the skip reason
 * reaches coverage tracking.
 *
 * This mirrors {@see ResponseBodyValidationResult} on the response side;
 * request-side coverage has no per-content-type dimension, so no
 * `matchedContentType` is carried.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class RequestBodyValidationResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public array $errors,
        public ?string $skipReason = null,
    ) {}
}
