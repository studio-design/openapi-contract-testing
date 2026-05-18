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
 * `skipReason`, when non-null, marks that the validator deliberately did NOT
 * check the body even though a media-type key matched and that key declared
 * a `schema`: the response Content-Type is a non-JSON media type this
 * JSON-Schema engine cannot evaluate (issue #254). `errors` stays empty in
 * that case — it is a skip, not a failure — and the orchestrator turns it
 * into an `OpenApiValidationResult::skipped()` so the unvalidated body is
 * not miscounted as a clean pass.
 *
 * Coverage tracking uses `matchedContentType` to record per-(status, media-type)
 * granularity instead of treating the whole endpoint as a single bucket.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class ResponseBodyValidationResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public array $errors,
        public ?string $matchedContentType,
        public ?string $skipReason = null,
    ) {}
}
