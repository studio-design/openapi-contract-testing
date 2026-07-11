# Symfony quickstart

```bash
composer require --dev studio-design/openapi-contract-testing symfony/http-foundation symfony/http-kernel
composer install
composer test
```

The passing test validates HttpFoundation request and response objects. In a full Symfony application, the same trait also validates the last `KernelBrowser` exchange.
