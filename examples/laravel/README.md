# Laravel quickstart

```bash
composer require --dev studio-design/openapi-contract-testing
php artisan vendor:publish --tag=openapi-contract-testing
composer install
composer test
```

The fixture covers explicit response assertions and the `auto_assert` / `auto_validate_request` configuration. Its PHPUnit extension prints endpoint coverage.
