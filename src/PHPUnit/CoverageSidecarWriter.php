<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use RuntimeException;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;

use function file_put_contents;
use function getmypid;
use function is_dir;
use function json_encode;
use function mkdir;
use function preg_replace;
use function rename;
use function rtrim;
use function sprintf;
use function unlink;

/**
 * Writes a paratest worker's coverage state to a JSON sidecar that the
 * merge CLI will later combine into a single report.
 *
 * Filename format: `part-<token>-<pid>.json`. `<token>` identifies the
 * paratest slot (1..N) and is enough to disambiguate within one run; `<pid>`
 * is appended so a leftover sidecar from a crashed previous run cannot be
 * mistaken for the current one. The pair plus a defensive sanitiser keeps
 * the path filesystem-safe across runners that inject unexpected characters
 * into `TEST_TOKEN`.
 *
 * Atomicity: written to a sibling `*.tmp` first, then `rename()`d into place
 * so a partial file is never observable by the reader.
 */
final class CoverageSidecarWriter
{
    public const FILENAME_PREFIX = 'part-';
    public const FILENAME_SUFFIX = '.json';

    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * @param array<string, mixed> $state from {@see OpenApiCoverageTracker::exportState()}
     *
     * @throws RuntimeException when the sidecar cannot be created or persisted
     */
    public static function write(string $sidecarDir, string $testToken, array $state): string
    {
        $dir = rtrim($sidecarDir, '/' . DIRECTORY_SEPARATOR);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, recursive: true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('failed to create sidecar dir "%s"', $dir));
        }

        $safeToken = self::sanitise($testToken);
        $pid = (string) (getmypid() ?: 0);
        $finalPath = sprintf('%s/%s%s-%s%s', $dir, self::FILENAME_PREFIX, $safeToken, $pid, self::FILENAME_SUFFIX);
        $tmpPath = $finalPath . '.tmp';

        try {
            $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('failed to encode coverage state: %s', $e->getMessage()), 0, $e);
        }

        $written = @file_put_contents($tmpPath, $payload);
        if ($written === false) {
            throw new RuntimeException(sprintf('failed to write sidecar tmp file "%s"', $tmpPath));
        }

        if (!@rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);

            throw new RuntimeException(sprintf('failed to rename sidecar "%s" -> "%s"', $tmpPath, $finalPath));
        }

        return $finalPath;
    }

    /**
     * Strip anything that's not safe in a filename so a hostile or malformed
     * `TEST_TOKEN` cannot escape the sidecar dir or break the reader's glob.
     */
    private static function sanitise(string $token): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9_-]/', '_', $token);

        return $cleaned === null || $cleaned === '' ? 'unknown' : $cleaned;
    }
}
