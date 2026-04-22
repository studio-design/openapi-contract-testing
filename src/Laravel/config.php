<?php

declare(strict_types=1);

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
];
