<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiValidationOutcome;
use Studio\OpenApiContractTesting\OpenApiValidationResult;

class OpenApiValidationResultTest extends TestCase
{
    #[Test]
    public function success_creates_valid_result(): void
    {
        $result = OpenApiValidationResult::success('/v1/pets');

        $this->assertSame(OpenApiValidationOutcome::Success, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertSame([], $result->errors());
        $this->assertSame('', $result->errorMessage());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function success_without_path(): void
    {
        $result = OpenApiValidationResult::success();

        $this->assertSame(OpenApiValidationOutcome::Success, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertNull($result->matchedPath());
    }

    #[Test]
    public function failure_creates_invalid_result(): void
    {
        $errors = ['Error 1', 'Error 2'];
        $result = OpenApiValidationResult::failure($errors);

        $this->assertSame(OpenApiValidationOutcome::Failure, $result->outcome());
        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertSame($errors, $result->errors());
        $this->assertNull($result->matchedPath());
    }

    #[Test]
    public function failure_with_empty_errors_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('failure() requires at least one error message');

        // @phpstan-ignore-next-line argument.type — intentionally empty to verify the runtime guard still fires for consumers without static analysis
        OpenApiValidationResult::failure([]);
    }

    #[Test]
    public function error_message_joins_errors_with_newline(): void
    {
        $result = OpenApiValidationResult::failure(['Error 1', 'Error 2']);

        $this->assertSame("Error 1\nError 2", $result->errorMessage());
    }

    #[Test]
    public function skipped_creates_skipped_result(): void
    {
        $result = OpenApiValidationResult::skipped('/v1/pets', 'status 500 matched skip pattern 5\d\d');

        // isValid() remains true so the assertion surface does not fail the test,
        // but isSkipped() distinguishes the case from a genuine success.
        $this->assertSame(OpenApiValidationOutcome::Skipped, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame([], $result->errors());
        $this->assertSame('', $result->errorMessage());
        $this->assertSame('/v1/pets', $result->matchedPath());
        $this->assertSame('status 500 matched skip pattern 5\d\d', $result->skipReason());
    }

    #[Test]
    public function skipped_without_reason(): void
    {
        $result = OpenApiValidationResult::skipped('/v1/pets');

        $this->assertSame(OpenApiValidationOutcome::Skipped, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
        $this->assertNull($result->skipReason());
    }

    #[Test]
    public function skipped_without_matched_path(): void
    {
        $result = OpenApiValidationResult::skipped();

        $this->assertSame(OpenApiValidationOutcome::Skipped, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertNull($result->matchedPath());
        $this->assertNull($result->skipReason());
    }

    #[Test]
    public function matched_status_and_content_default_to_null(): void
    {
        // Coverage tracking depends on the absence of these being explicit
        // null rather than missing — pin the defaults across all three factories.
        $success = OpenApiValidationResult::success();
        $failure = OpenApiValidationResult::failure(['err']);
        $skipped = OpenApiValidationResult::skipped();

        foreach ([$success, $failure, $skipped] as $result) {
            $this->assertNull($result->matchedStatusCode());
            $this->assertNull($result->matchedContentType());
        }
    }

    #[Test]
    public function success_propagates_matched_status_and_content(): void
    {
        $result = OpenApiValidationResult::success('/v1/pets', '200', 'application/json');

        $this->assertSame('200', $result->matchedStatusCode());
        $this->assertSame('application/json', $result->matchedContentType());
    }

    #[Test]
    public function failure_propagates_matched_status_and_content(): void
    {
        // Failures still carry matched-status/content when the validator got far
        // enough to pick them — coverage records the (status, contentType) pair
        // even on schema mismatches so partial coverage shows up correctly.
        $result = OpenApiValidationResult::failure(['err'], '/v1/pets', '422', 'application/problem+json');

        $this->assertSame('422', $result->matchedStatusCode());
        $this->assertSame('application/problem+json', $result->matchedContentType());
    }

    #[Test]
    public function skipped_carries_literal_status_and_no_content_type(): void
    {
        // Skip happens before content-type lookup — matchedContentType is always null
        // and matchedStatusCode is the literal HTTP status, not a spec range key.
        $result = OpenApiValidationResult::skipped('/v1/pets', 'status 503 matched 5\d\d', '503');

        $this->assertSame('503', $result->matchedStatusCode());
        $this->assertNull($result->matchedContentType());
    }

    #[Test]
    public function outcome_match_covers_all_three_cases_exhaustively(): void
    {
        $results = [
            OpenApiValidationResult::success(),
            OpenApiValidationResult::failure(['err']),
            OpenApiValidationResult::skipped(reason: 'reason'),
        ];

        $labels = [];
        foreach ($results as $result) {
            $labels[] = match ($result->outcome()) {
                OpenApiValidationOutcome::Success => 'success',
                OpenApiValidationOutcome::Failure => 'failure',
                OpenApiValidationOutcome::Skipped => 'skipped',
            };
        }

        $this->assertSame(['success', 'failure', 'skipped'], $labels);
    }
}
