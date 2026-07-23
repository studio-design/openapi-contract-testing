<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;

final class DocumentationInstallPolicyTest extends TestCase
{
    #[Test]
    public function installation_guides_keep_the_beta_constraint_until_stable_is_published(): void
    {
        $root = dirname(__DIR__, 3);

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

            $this->assertStringContainsString(
                'studio-design/gesso:^2.0@beta',
                $contents,
                $relativePath . ' must keep using the published beta until v2.0.0 stable is installable.',
            );
            $this->assertStringContainsString(
                'Pre-release evaluation only',
                $contents,
                $relativePath . ' must retain the prerelease warning until v2.0.0 stable is installable.',
            );
            $this->assertStringNotContainsString(
                'composer require --dev studio-design/gesso',
                $contents,
                $relativePath . ' must not present an unpublished stable release as the current install path.',
            );
        }

        $homeContents = file_get_contents($root . '/docs/.vitepress/theme/components/TomboHome.vue');
        $this->assertIsString($homeContents);

        $this->assertStringContainsString(
            'studio-design/gesso:^2.0@beta',
            $homeContents,
            'The home page must keep using the published beta until v2.0.0 stable is installable.',
        );
        $this->assertStringNotContainsString(
            'composer require --dev studio-design/gesso',
            $homeContents,
            'The home page must not present an unpublished stable release as the current install path.',
        );
    }
}
