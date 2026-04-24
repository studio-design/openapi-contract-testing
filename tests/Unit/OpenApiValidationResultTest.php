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
