<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiValidationResult;

class OpenApiValidationResultTest extends TestCase
{
    #[Test]
    public function success_creates_valid_result(): void
    {
        $result = OpenApiValidationResult::success('/v1/pets');

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

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertNull($result->matchedPath());
    }

    #[Test]
    public function failure_creates_invalid_result(): void
    {
        $errors = ['Error 1', 'Error 2'];
        $result = OpenApiValidationResult::failure($errors);

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertSame($errors, $result->errors());
        $this->assertNull($result->matchedPath());
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
        $result = OpenApiValidationResult::skipped('/v1/pets', 'status 500 matched skip pattern');

        // isValid() remains true so the assertion surface does not fail the test,
        // but isSkipped() distinguishes the case from a genuine success.
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame([], $result->errors());
        $this->assertSame('', $result->errorMessage());
        $this->assertSame('/v1/pets', $result->matchedPath());
        $this->assertSame('status 500 matched skip pattern', $result->skipReason());
    }

    #[Test]
    public function skipped_without_reason(): void
    {
        $result = OpenApiValidationResult::skipped('/v1/pets');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
        $this->assertNull($result->skipReason());
    }

    #[Test]
    public function skipped_without_matched_path(): void
    {
        $result = OpenApiValidationResult::skipped();

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertNull($result->matchedPath());
        $this->assertNull($result->skipReason());
    }
}
