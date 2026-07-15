<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function preg_match_all;
use function str_contains;
use function substr_count;

final class GitHubActionsSecurityPolicyTest extends TestCase
{
    private const WORKFLOW_DIR = __DIR__ . '/../../../.github/workflows';

    #[Test]
    public function every_workflow_declares_an_explicit_token_policy(): void
    {
        $workflows = glob(self::WORKFLOW_DIR . '/*.yml');
        $this->assertNotFalse($workflows);
        $this->assertNotEmpty($workflows);

        foreach ($workflows as $path) {
            $workflow = $this->read($path);
            $this->assertMatchesRegularExpression(
                '/^permissions:(?: \{\}|\n)/m',
                $workflow,
                "{$path} must not inherit the repository's default GITHUB_TOKEN permissions.",
            );
        }
    }

    #[Test]
    public function ci_is_read_only_and_does_not_persist_checkout_credentials(): void
    {
        $workflow = $this->read(self::WORKFLOW_DIR . '/ci.yml');

        $this->assertMatchesRegularExpression('/^permissions:\n  contents: read\n/m', $workflow);
        $this->assertStringNotContainsString('contents: write', $workflow);
        $this->assertStringNotContainsString('pull-requests: write', $workflow);
        $this->assertStringNotContainsString('id-token: write', $workflow);
        $this->assertCheckoutCredentialsAreNotPersisted($workflow);
    }

    #[Test]
    public function tag_guard_has_no_token_permissions(): void
    {
        $workflow = $this->read(self::WORKFLOW_DIR . '/tag-guard.yml');

        $this->assertMatchesRegularExpression('/^permissions: \{\}\n/m', $workflow);
        $this->assertStringNotContainsString('contents:', $workflow);
        $this->assertStringNotContainsString('pull-requests:', $workflow);
    }

    #[Test]
    public function pages_write_and_oidc_are_confined_to_the_deploy_job(): void
    {
        $workflow = $this->read(self::WORKFLOW_DIR . '/docs.yml');

        $this->assertMatchesRegularExpression('/^permissions: \{\}\n/m', $workflow);
        $this->assertMatchesRegularExpression(
            '/^  build:\n    runs-on: ubuntu-latest\n    permissions:\n      contents: read\n/m',
            $workflow,
        );
        $this->assertMatchesRegularExpression(
            '/^  deploy:\n    permissions:\n      pages: write\n      id-token: write\n/m',
            $workflow,
        );
        $this->assertSame(1, substr_count($workflow, 'pages: write'));
        $this->assertSame(1, substr_count($workflow, 'id-token: write'));
        $this->assertCheckoutCredentialsAreNotPersisted($workflow);
    }

    private function assertCheckoutCredentialsAreNotPersisted(string $workflow): void
    {
        $checkoutCount = substr_count($workflow, 'uses: actions/checkout@');
        $this->assertGreaterThan(0, $checkoutCount);

        $matched = preg_match_all(
            '/uses: actions\/checkout@[^\n]+\n\s+with:\n(?:\s+[^\n]+\n)*?\s+persist-credentials: false(?:\n|$)/',
            $workflow,
        );
        $this->assertNotFalse($matched);
        $this->assertSame($checkoutCount, $matched, 'Every checkout step must disable credential persistence.');
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->assertTrue(str_contains($contents, 'jobs:'), "{$path} must contain jobs.");

        return $contents;
    }
}
