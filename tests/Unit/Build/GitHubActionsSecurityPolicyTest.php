<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_key_exists;
use function glob;
use function is_string;
use function str_starts_with;

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
            $workflow = $this->parse($path);
            $this->assertArrayHasKey(
                'permissions',
                $workflow,
                "{$path} must not inherit the repository's default GITHUB_TOKEN permissions.",
            );
        }
    }

    #[Test]
    public function ci_is_read_only_at_workflow_and_job_level(): void
    {
        $workflow = $this->parse(self::WORKFLOW_DIR . '/ci.yml');

        $this->assertSame(['contents' => 'read'], $workflow['permissions'] ?? null);
        foreach ($this->jobs($workflow) as $jobName => $job) {
            $this->assertIsArray($job, "CI job {$jobName} must be a mapping.");
            if (!array_key_exists('permissions', $job)) {
                continue;
            }

            $this->assertContainsNoWritePermission($job['permissions'], "CI job {$jobName}");
        }

        $this->assertCheckoutCredentialsAreNotPersisted($workflow);
    }

    #[Test]
    public function tag_guard_has_no_workflow_or_job_token_permissions(): void
    {
        $workflow = $this->parse(self::WORKFLOW_DIR . '/tag-guard.yml');

        $this->assertSame([], $workflow['permissions'] ?? null);
        foreach ($this->jobs($workflow) as $jobName => $job) {
            $this->assertIsArray($job, "tag-guard job {$jobName} must be a mapping.");
            $this->assertArrayNotHasKey(
                'permissions',
                $job,
                "tag-guard job {$jobName} must not override the empty workflow token policy.",
            );
        }
    }

    #[Test]
    public function pages_permissions_are_confined_to_the_jobs_that_need_them(): void
    {
        $workflow = $this->parse(self::WORKFLOW_DIR . '/docs.yml');
        $jobs = $this->jobs($workflow);

        $this->assertSame([], $workflow['permissions'] ?? null);
        $this->assertSame(
            ['contents' => 'read', 'pages' => 'read'],
            $this->jobPermissions($jobs, 'build'),
        );
        $this->assertSame(
            ['pages' => 'write', 'id-token' => 'write'],
            $this->jobPermissions($jobs, 'deploy'),
        );
        $this->assertCheckoutCredentialsAreNotPersisted($workflow);
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private function assertCheckoutCredentialsAreNotPersisted(array $workflow): void
    {
        $checkoutCount = 0;
        foreach ($this->jobs($workflow) as $jobName => $job) {
            $this->assertIsArray($job, "job {$jobName} must be a mapping.");
            $steps = $job['steps'] ?? [];
            $this->assertIsArray($steps, "steps for job {$jobName} must be a list.");

            foreach ($steps as $step) {
                $this->assertIsArray($step, "each step in job {$jobName} must be a mapping.");
                $uses = $step['uses'] ?? null;
                if (!is_string($uses) || !str_starts_with($uses, 'actions/checkout@')) {
                    continue;
                }

                $checkoutCount++;
                $with = $step['with'] ?? null;
                $this->assertIsArray($with, "checkout in job {$jobName} must declare with options.");
                $this->assertSame(
                    false,
                    $with['persist-credentials'] ?? null,
                    "checkout in job {$jobName} must disable credential persistence.",
                );
            }
        }

        $this->assertGreaterThan(0, $checkoutCount);
    }

    private function assertContainsNoWritePermission(mixed $permissions, string $context): void
    {
        $this->assertIsArray($permissions, "{$context} permissions must be a mapping.");
        foreach ($permissions as $permission => $access) {
            $this->assertNotSame(
                'write',
                $access,
                "{$context} must not grant {$permission}: write.",
            );
        }
    }

    /**
     * @param array<string, mixed> $workflow
     *
     * @return array<string, mixed>
     */
    private function jobs(array $workflow): array
    {
        $jobs = $workflow['jobs'] ?? null;
        $this->assertIsArray($jobs);

        return $jobs;
    }

    /**
     * @param array<string, mixed> $jobs
     *
     * @return array<string, string>
     */
    private function jobPermissions(array $jobs, string $jobName): array
    {
        $job = $jobs[$jobName] ?? null;
        $this->assertIsArray($job, "{$jobName} job must be a mapping.");
        $permissions = $job['permissions'] ?? null;
        $this->assertIsArray($permissions, "{$jobName} permissions must be a mapping.");

        foreach ($permissions as $permission => $access) {
            $this->assertIsString($permission);
            $this->assertIsString($access);
        }

        /** @var array<string, string> $permissions */
        return $permissions;
    }

    /** @return array<string, mixed> */
    private function parse(string $path): array
    {
        $workflow = Yaml::parseFile($path);
        $this->assertIsArray($workflow, "{$path} must parse to a mapping.");

        return $workflow;
    }
}
