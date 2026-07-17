<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function json_decode;
use function str_contains;

final class DocumentationInstallPolicyTest extends TestCase
{
    #[Test]
    public function prerelease_install_guides_are_explicitly_marked_for_evaluation(): void
    {
        $root = dirname(__DIR__, 3);
        $manifestContents = file_get_contents($root . '/.release-please-manifest.json');
        $this->assertNotFalse($manifestContents);

        /** @var array{'.': string} $manifest */
        $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
        $currentVersion = $manifest['.'];

        foreach ([
            'README.md',
            'docs/quickstarts/core.md',
            'docs/quickstarts/laravel.md',
            'docs/quickstarts/pest.md',
            'docs/quickstarts/symfony.md',
            'examples/core/README.md',
            'examples/laravel/README.md',
            'examples/pest/README.md',
            'examples/symfony/README.md',
        ] as $relativePath) {
            $contents = file_get_contents($root . '/' . $relativePath);
            $this->assertIsString($contents);

            if (!str_contains($currentVersion, '-')) {
                $this->assertStringContainsString(
                    'studio-design/gesso:^2.0',
                    $contents,
                    $relativePath . ' must install the stable Gesso 2 line.',
                );
                $this->assertStringNotContainsString(
                    'studio-design/gesso:^2.0@beta',
                    $contents,
                    $relativePath . ' must stop opting in to prereleases after the stable release.',
                );
                $this->assertStringNotContainsString(
                    'Pre-release evaluation only',
                    $contents,
                    $relativePath . ' must remove the prerelease warning after the stable release.',
                );

                continue;
            }

            $this->assertStringContainsString(
                'studio-design/gesso:^2.0@beta',
                $contents,
                $relativePath . ' must opt in to the published Gesso 2 beta.',
            );
            $this->assertStringContainsString(
                'Pre-release evaluation only',
                $contents,
                $relativePath . ' must not present the Gesso 2 beta as a stable recommendation.',
            );
            $this->assertStringNotContainsString(
                'composer require --dev studio-design/gesso',
                $contents,
                $relativePath . ' must not present an unavailable stable release as the current install path.',
            );
        }
    }
}
