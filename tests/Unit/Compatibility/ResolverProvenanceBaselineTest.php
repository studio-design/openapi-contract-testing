<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\OpenApiRefResolver;

use function dirname;
use function file_get_contents;
use function json_encode;

final class ResolverProvenanceBaselineTest extends TestCase
{
    #[Test]
    public function resolved_composition_matches_the_v1_9_provenance_fixture(): void
    {
        $legacyMarker = 'x-studio-openapi-contract-testing-implicit-schema-name';
        $resolved = OpenApiRefResolver::resolve([
            'components' => ['schemas' => [
                'Cat' => ['type' => 'object', 'required' => ['meow']],
            ]],
            'schema' => [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    ['type' => 'object', $legacyMarker => 'Spoofed'],
                ],
            ],
        ]);

        $this->assertSame($legacyMarker, OpenApiRefResolver::IMPLICIT_SCHEMA_NAME_EXTENSION);
        $this->assertSame(
            $this->fixture(),
            json_encode(
                $resolved,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n",
        );
    }

    private function fixture(): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v1.9-resolver-provenance.json',
        );
        $this->assertIsString($contents);

        return $contents;
    }
}
