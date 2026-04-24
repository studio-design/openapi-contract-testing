<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Request\SecurityValidator;

class SecurityValidatorTest extends TestCase
{
    private SecurityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SecurityValidator();
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
}
