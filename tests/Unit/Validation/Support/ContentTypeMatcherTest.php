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

    // ========================================
    // Wildcard ranges (RFC 7231 / OpenAPI 3.x)
    // ========================================

    #[Test]
    public function find_content_type_key_matches_type_wildcard(): void
    {
        $content = ['application/*' => []];

        $this->assertSame('application/*', ContentTypeMatcher::findContentTypeKey('application/json', $content));
        $this->assertSame('application/*', ContentTypeMatcher::findContentTypeKey('application/xml', $content));
        $this->assertNull(ContentTypeMatcher::findContentTypeKey('text/html', $content));
    }

    #[Test]
    public function find_content_type_key_matches_full_wildcard(): void
    {
        $content = ['*/*' => []];

        $this->assertSame('*/*', ContentTypeMatcher::findContentTypeKey('application/json', $content));
        $this->assertSame('*/*', ContentTypeMatcher::findContentTypeKey('text/html', $content));
    }

    #[Test]
    public function find_content_type_key_prefers_exact_over_wildcard(): void
    {
        // Most-specific-first: an exact match must beat a `<type>/*` and `*/*`
        // entry even if the wildcard appears earlier in iteration order.
        $content = [
            '*/*' => ['exists' => 'fallback'],
            'application/*' => ['exists' => 'type-range'],
            'application/json' => ['exists' => 'exact'],
        ];

        $this->assertSame('application/json', ContentTypeMatcher::findContentTypeKey('application/json', $content));
    }

    #[Test]
    public function find_content_type_key_prefers_type_range_over_full_wildcard(): void
    {
        $content = [
            '*/*' => [],
            'application/*' => [],
        ];

        $this->assertSame('application/*', ContentTypeMatcher::findContentTypeKey('application/json', $content));
    }

    #[Test]
    public function find_json_content_type_returns_type_wildcard_when_no_explicit_json(): void
    {
        // Pre-fix: an `application/*` spec entry would silently skip JSON
        // schema validation because findJsonContentType only matched literal
        // `application/json` or `+json` suffixes.
        $content = ['application/*' => ['schema' => ['type' => 'string']]];

        $this->assertSame('application/*', ContentTypeMatcher::findJsonContentType($content));
    }

    #[Test]
    public function find_json_content_type_does_not_return_full_wildcard(): void
    {
        // `*&#47;*` covers everything (binary, image, html). Returning it from a
        // method whose contract is "find a JSON-validatable spec key" would let
        // ResponseBodyValidator validate non-JSON bodies as JSON when the response
        // omits Content-Type. Conservative: skip JSON validation rather than
        // pretend `*&#47;*` means JSON.
        $content = ['*/*' => ['schema' => ['type' => 'object']]];

        $this->assertNull(ContentTypeMatcher::findJsonContentType($content));
    }

    #[Test]
    public function find_json_content_type_does_not_return_non_json_type_ranges(): void
    {
        // `text/*`, `image/*`, `multipart/*` cannot plausibly carry a JSON body.
        // findJsonContentType must not return them — pre-fix, the broad
        // `<type>/*` accept introduced a NEW silent pass: a spec declaring only
        // `text/*` would route through JSON schema validation.
        $textOnly = ['text/*' => ['schema' => ['type' => 'string']]];
        $this->assertNull(ContentTypeMatcher::findJsonContentType($textOnly));

        $imageOnly = ['image/*' => ['schema' => ['type' => 'string']]];
        $this->assertNull(ContentTypeMatcher::findJsonContentType($imageOnly));
    }

    #[Test]
    public function find_json_content_type_prefers_explicit_json_over_application_wildcard(): void
    {
        $content = [
            'application/*' => [],
            'application/json' => ['schema' => ['type' => 'string']],
        ];

        $this->assertSame('application/json', ContentTypeMatcher::findJsonContentType($content));
    }

    #[Test]
    public function find_json_content_type_matches_application_wildcard_case_insensitively(): void
    {
        $content = ['Application/*' => ['schema' => ['type' => 'object']]];

        $this->assertSame('Application/*', ContentTypeMatcher::findJsonContentType($content));
    }
}
