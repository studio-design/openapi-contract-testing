<?php

declare(strict_types=1);

use Studio\OpenApiContractTesting\OpenApiResponseValidator;

return [
    'default_spec' => '',

    // Maximum number of validation errors to report per response.
    // 0 = unlimited (reports all errors).
    'max_errors' => 20,

    // When true, every TestResponse produced by Laravel HTTP test helpers
    // (get(), post(), etc.) is validated against the OpenAPI spec at creation
    // time, without requiring an explicit assertResponseMatchesOpenApiSchema()
    // call in each test. Defaults to false for backward compatibility.
    'auto_assert' => false,

    // Regex patterns (without delimiters or anchors) matched against the
    // response status code. Matching codes short-circuit body validation and
    // return a "skipped" result — the test is not failed, and the endpoint is
    // still recorded as covered. The default skips every 5xx because specs
    // typically do not document production error responses.
    // Set to [] to disable and validate every status code against the spec.
    'skip_response_codes' => OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
];
