<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiPathMatcher;

class OpenApiPathMatcherTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function provideMatches_paths_without_strip_prefixCases(): iterable
    {
        return [
            'exact match' => [
                '/v2/account',
                '/v2/account',
            ],
            'parameterized path' => [
                '/v2/projects/abc123',
                '/v2/projects/{project_id}',
            ],
            'multiple parameters' => [
                '/v2/projects/abc123/assets/def456',
                '/v2/projects/{project_id}/assets/{asset_id}',
            ],
            'collection path' => [
                '/v2/projects',
                '/v2/projects',
            ],
            'nested collection' => [
                '/v2/projects/abc123/assets',
                '/v2/projects/{project_id}/assets',
            ],
            'hyphenated path' => [
                '/v2/add-on/plans',
                '/v2/add-on/plans',
            ],
            'trailing slash stripped' => [
                '/v2/account/',
                '/v2/account',
            ],
            'no match returns null' => [
                '/v2/unknown/path',
                null,
            ],
            'workspace members' => [
                '/v2/workspace/ws123/members',
                '/v2/workspace/{workspace_id}/members',
            ],
        ];
    }

    /** @return array<string, array{string, ?string}> */
    public static function provideMatches_paths_with_strip_prefixCases(): iterable
    {
        return [
            'with /api prefix' => [
                '/api/v2/account',
                '/v2/account',
            ],
            'without prefix' => [
                '/v2/account',
                '/v2/account',
            ],
            'parameterized with prefix' => [
                '/api/v2/projects/abc123',
                '/v2/projects/{project_id}',
            ],
            'hyphenated with prefix' => [
                '/api/v2/add-on/plans',
                '/v2/add-on/plans',
            ],
        ];
    }

    #[Test]
    #[DataProvider('provideMatches_paths_without_strip_prefixCases')]
    public function matches_paths_without_strip_prefix(string $requestPath, ?string $expected): void
    {
        $matcher = self::createMatcher();
        $this->assertSame($expected, $matcher->match($requestPath));
    }

    #[Test]
    public function specific_path_prioritized_over_parameterized(): void
    {
        $matcher = new OpenApiPathMatcher([
            '/v2/projects/{project_id}',
            '/v2/projects/templates',
        ]);

        $this->assertSame('/v2/projects/templates', $matcher->match('/v2/projects/templates'));
        $this->assertSame('/v2/projects/{project_id}', $matcher->match('/v2/projects/abc123'));
    }

    #[Test]
    #[DataProvider('provideMatches_paths_with_strip_prefixCases')]
    public function matches_paths_with_strip_prefix(string $requestPath, ?string $expected): void
    {
        $matcher = new OpenApiPathMatcher(
            [
                '/v2/account',
                '/v2/projects/{project_id}',
                '/v2/add-on/plans',
            ],
            ['/api'],
        );

        $this->assertSame($expected, $matcher->match($requestPath));
    }

    #[Test]
    public function multiple_strip_prefixes_only_first_match_applied(): void
    {
        $matcher = new OpenApiPathMatcher(
            ['/v2/account'],
            ['/api', '/internal'],
        );

        $this->assertSame('/v2/account', $matcher->match('/api/v2/account'));
        $this->assertSame('/v2/account', $matcher->match('/internal/v2/account'));
    }

    #[Test]
    public function empty_strip_prefixes_no_stripping(): void
    {
        $matcher = new OpenApiPathMatcher(
            ['/v2/account'],
            [],
        );

        $this->assertSame('/v2/account', $matcher->match('/v2/account'));
        $this->assertNull($matcher->match('/api/v2/account'));
    }

    private static function createMatcher(): OpenApiPathMatcher
    {
        return new OpenApiPathMatcher([
            '/v2/account',
            '/v2/projects',
            '/v2/projects/{project_id}',
            '/v2/projects/{project_id}/assets',
            '/v2/projects/{project_id}/assets/{asset_id}',
            '/v2/plans',
            '/v2/workspace/{workspace_id}/members',
            '/v2/add-on/plans',
        ]);
    }
}
