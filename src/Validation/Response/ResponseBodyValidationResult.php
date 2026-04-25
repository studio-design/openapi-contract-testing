<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Response;

/**
 * Outcome of {@see ResponseBodyValidator::validate()}.
 *
 * `errors` carries the same string payload the validator previously returned
 * (empty list = body acceptable). `matchedContentType` is the spec key (with
 * the spec author's original casing) that the body validated against, or
 * `null` when no content-type lookup was performed (204-style responses,
 * non-JSON specs we don't validate, content-type-not-in-spec failures).
 *
 * Coverage tracking uses `matchedContentType` to record per-(status, media-type)
 * granularity instead of treating the whole endpoint as a single bucket.
 */
final readonly class ResponseBodyValidationResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public array $errors,
        public ?string $matchedContentType,
    ) {}
}
