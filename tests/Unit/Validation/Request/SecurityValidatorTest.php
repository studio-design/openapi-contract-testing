<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Request;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Request\SecurityValidator;

use function restore_error_handler;
use function set_error_handler;

class SecurityValidatorTest extends TestCase
{
    private SecurityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SecurityValidator();
        // Reset the per-process "warned-once" set so tests are deterministic
        // regardless of run order or filter selection. Same convention as
        // OpenApiSchemaConverterTest.
        SecurityValidator::resetWarningStateForTesting();
    }

    protected function tearDown(): void
    {
        SecurityValidator::resetWarningStateForTesting();
        parent::tearDown();
    }

    #[Test]
    public function validate_returns_empty_when_no_security_defined(): void
    {
        $errors = $this->validator->validate('GET', '/pets', [], [], [], [], []);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_passes_bearer_auth_with_valid_header(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => [['BearerAuth' => []]]];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $spec,
            $operation,
            ['Authorization' => 'Bearer xyz.token'],
            [],
            [],
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_missing_bearer_header(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => [['BearerAuth' => []]]];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Authorization header is missing', $errors[0]);
    }

    #[Test]
    public function validate_surfaces_hard_error_for_undefined_scheme(): void
    {
        $spec = ['components' => ['securitySchemes' => []]];
        $operation = ['security' => [['MissingScheme' => []]]];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("references undefined scheme 'MissingScheme'", $errors[0]);
    }

    #[Test]
    public function validate_surfaces_hard_error_for_malformed_scheme_type(): void
    {
        // A typo like `type: htpp` must be surfaced as a hard error — otherwise
        // the library would silently pass every request for the endpoint.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Oops' => ['type' => 'htpp'],
                ],
            ],
        ];
        $operation = ['security' => [['Oops' => []]]];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('is malformed', $errors[0]);
        $this->assertStringContainsString("unknown type 'htpp'", $errors[0]);
    }

    #[Test]
    public function validate_passes_api_key_in_header(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'ApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
            ],
        ];
        $operation = ['security' => [['ApiKey' => []]]];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $spec,
            $operation,
            ['X-Api-Key' => 'secret'],
            [],
            [],
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_treats_empty_security_as_opt_out(): void
    {
        $spec = [
            'security' => [['BearerAuth' => []]],
            'components' => [
                'securitySchemes' => ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
            ],
        ];
        // Operation explicitly opts out with `security: []`.
        $operation = ['security' => []];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertSame([], $errors);
    }

    // ========================================
    // Loud-warning behaviour for unsupported security schemes (issue #146).
    //
    // The validator silent-passes oauth2 / openIdConnect / mutualTLS / http
    // (non-bearer) requirement entries (false-negative avoidance), but it
    // also fires a one-shot E_USER_WARNING per scheme name so users notice
    // the silent pass — green tests against an unauthenticated request are
    // the worst-class failure mode for a contract-testing tool.
    // ========================================

    #[Test]
    public function oauth2_emits_loud_warning_on_first_encounter(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => ['read']]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'POST',
            '/v1/users',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors, 'silent-pass behaviour preserved');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('[security] OAuth2 scheme', $warnings[0]);
        $this->assertStringContainsString("'OAuth'", $warnings[0]);
        $this->assertStringContainsString('POST /v1/users', $warnings[0]);
    }

    #[Test]
    public function openid_connect_emits_loud_warning_on_first_encounter(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OIDC' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://idp.example/.well-known/openid-configuration'],
                ],
            ],
        ];
        $operation = ['security' => [['OIDC' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/me',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('OpenID Connect scheme', $warnings[0]);
        $this->assertStringContainsString("'OIDC'", $warnings[0]);
    }

    #[Test]
    public function mutual_tls_emits_loud_warning_on_first_encounter(): void
    {
        // mutualTLS is OAS 3.1 only.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'mTLS' => ['type' => 'mutualTLS'],
                ],
            ],
        ];
        $operation = ['security' => [['mTLS' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'POST',
            '/v1/internal/sync',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Mutual TLS scheme', $warnings[0]);
        $this->assertStringContainsString("'mTLS'", $warnings[0]);
    }

    #[Test]
    public function http_basic_emits_loud_warning_on_first_encounter(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Basic' => ['type' => 'http', 'scheme' => 'basic'],
                ],
            ],
        ];
        $operation = ['security' => [['Basic' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/admin',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('http-basic scheme', $warnings[0]);
        $this->assertStringContainsString("'Basic'", $warnings[0]);
    }

    #[Test]
    public function http_digest_emits_loud_warning_on_first_encounter(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Digest' => ['type' => 'http', 'scheme' => 'digest'],
                ],
            ],
        ];
        $operation = ['security' => [['Digest' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/legacy',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('http-digest scheme', $warnings[0]);
        $this->assertStringContainsString("'Digest'", $warnings[0]);
    }

    #[Test]
    public function repeated_calls_with_same_scheme_name_warn_only_once(): void
    {
        // Avoid log spam: warn once per process per scheme name. Three calls
        // referencing the same scheme name must surface exactly one warning.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => []]]];

        $count = 0;
        set_error_handler(static function (int $errno) use (&$count): bool {
            if ($errno === E_USER_WARNING) {
                $count++;

                return true;
            }

            return false;
        });

        try {
            $this->validator->validate('GET', '/v1/a', [...$spec], $operation, [], [], []);
            $this->validator->validate('GET', '/v1/b', [...$spec], $operation, [], [], []);
            $this->validator->validate('GET', '/v1/c', [...$spec], $operation, [], [], []);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $count);
    }

    #[Test]
    public function two_distinct_oauth2_scheme_names_each_warn_once(): void
    {
        // Dedup is per scheme name (the components.securitySchemes key), not
        // per type. Two oauth2 definitions named differently both warn.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'oauth2_user' => ['type' => 'oauth2', 'flows' => []],
                    'oauth2_admin' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];

        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            $this->validator->validate(
                'GET',
                '/v1/a',
                $spec,
                ['security' => [['oauth2_user' => []]]],
                [],
                [],
                [],
            );
            $this->validator->validate(
                'GET',
                '/v1/b',
                $spec,
                ['security' => [['oauth2_admin' => []]]],
                [],
                [],
                [],
            );
        } finally {
            restore_error_handler();
        }

        $this->assertCount(2, $captured);
        $this->assertStringContainsString("'oauth2_user'", $captured[0]);
        $this->assertStringContainsString("'oauth2_admin'", $captured[1]);
    }

    #[Test]
    public function bearer_and_api_key_do_not_warn(): void
    {
        // Regression guard: supported schemes must not trigger the silent-pass
        // warning. Two operations exercise both validatable scheme types.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'ApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
            ],
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            $this->validator->validate(
                'GET',
                '/v1/x',
                $spec,
                ['security' => [['BearerAuth' => []]]],
                ['Authorization' => 'Bearer t'],
                [],
                [],
            );
            $this->validator->validate(
                'GET',
                '/v1/y',
                $spec,
                ['security' => [['ApiKey' => []]]],
                ['X-Api-Key' => 'k'],
                [],
                [],
            );
        } finally {
            restore_error_handler();
        }

        $this->assertNull($captured, 'supported schemes (bearer, apiKey) must not warn');
    }

    #[Test]
    public function warning_does_not_change_validation_outcome(): void
    {
        // OAuth2 is silently passed (false-negative avoidance) — the warning
        // is purely a side-channel. The actual return value must remain `[]`
        // even when the warning fires, otherwise the issue #146 fix would
        // accidentally regress the false-negative-avoidance behaviour.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => ['read']]]];

        [$errors] = $this->validateCapturingWarnings('GET', '/pets', $spec, $operation);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function oauth2_inside_and_entry_with_supported_scheme_still_warns(): void
    {
        // An AND-entry mixing an unsupported scheme with a supported one is
        // still skipped by the validator (the unsupported member taints the
        // whole entry). The warning must fire on the unsupported member so the
        // user is told that the test is not actually verifying the oauth2 leg.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'oauth2_user' => ['type' => 'oauth2', 'flows' => []],
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = [
            'security' => [
                ['oauth2_user' => [], 'BearerAuth' => []],
            ],
        ];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'POST',
            '/v1/secure',
            $spec,
            $operation,
            ['Authorization' => 'Bearer t'],
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'oauth2_user'", $warnings[0]);
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $operation
     * @param array<array-key, mixed> $headers
     *
     * @return array{0: string[], 1: string[]} [errors, warnings]
     */
    private function validateCapturingWarnings(
        string $method,
        string $path,
        array $spec,
        array $operation,
        array $headers = [],
    ): array {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            $errors = $this->validator->validate($method, $path, $spec, $operation, $headers, [], []);
        } finally {
            restore_error_handler();
        }

        return [$errors, $captured];
    }
}
