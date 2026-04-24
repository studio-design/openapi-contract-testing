<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Support\ContentTypeMatcher;

class ContentTypeMatcherTest extends TestCase
{
    #[Test]
    public function is_json_content_type_accepts_application_json(): void
    {
        $this->assertTrue(ContentTypeMatcher::isJsonContentType('application/json'));
    }

    #[Test]
    public function is_json_content_type_accepts_plus_json_suffix(): void
    {
        $this->assertTrue(ContentTypeMatcher::isJsonContentType('application/problem+json'));
        $this->assertTrue(ContentTypeMatcher::isJsonContentType('application/vnd.api+json'));
    }

    #[Test]
    public function is_json_content_type_rejects_non_json(): void
    {
        $this->assertFalse(ContentTypeMatcher::isJsonContentType('text/html'));
        $this->assertFalse(ContentTypeMatcher::isJsonContentType('application/xml'));
    }

    #[Test]
    public function find_json_content_type_returns_first_json_key(): void
    {
        $content = [
            'text/html' => [],
            'application/json' => [],
            'application/vnd.api+json' => [],
        ];

        $this->assertSame('application/json', ContentTypeMatcher::findJsonContentType($content));
    }

    #[Test]
    public function find_json_content_type_returns_null_when_no_json_defined(): void
    {
        $this->assertNull(ContentTypeMatcher::findJsonContentType(['text/html' => [], 'application/xml' => []]));
    }

    #[Test]
    public function normalize_media_type_strips_parameters_and_lowercases(): void
    {
        $this->assertSame('text/html', ContentTypeMatcher::normalizeMediaType('Text/HTML; charset=utf-8'));
        $this->assertSame('application/json', ContentTypeMatcher::normalizeMediaType(' application/json '));
    }

    #[Test]
    public function is_content_type_in_spec_matches_case_insensitively(): void
    {
        $content = ['Application/JSON' => [], 'Text/Html' => []];

        $this->assertTrue(ContentTypeMatcher::isContentTypeInSpec('application/json', $content));
        $this->assertTrue(ContentTypeMatcher::isContentTypeInSpec('text/html', $content));
        $this->assertFalse(ContentTypeMatcher::isContentTypeInSpec('application/xml', $content));
    }
}
