<?php

declare(strict_types=1);

namespace GessoNamespaceCompatibilityFixture;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

use ReflectionAttribute;
use ReflectionClass;
use Studio\Gesso\Example as CanonicalExample;
use Studio\Gesso\Marker as CanonicalMarker;
use Studio\OpenApiContractTesting\Contract as LegacyContract;
use Studio\OpenApiContractTesting\Example as LegacyExample;
use Studio\OpenApiContractTesting\Marker as LegacyMarker;
use Studio\OpenApiContractTesting\Mode as LegacyMode;
use Studio\OpenApiContractTesting\ReusableTrait as LegacyReusableTrait;
use Studio\OpenApiContractTesting\SerializedExample as LegacySerializedExample;

use function class_exists;
use function json_encode;
use function serialize;
use function sprintf;
use function strlen;
use function unserialize;

require __DIR__ . '/vendor/autoload.php';

$beforeLegacyLookup = [
    'example' => class_exists(CanonicalExample::class, false),
    'optional_adapter' => class_exists(Studio\Gesso\OptionalAdapter::class, false),
];

final class LegacyConsumer implements LegacyContract
{
    use LegacyReusableTrait;

    public function label(): string
    {
        return 'consumer';
    }
}

#[LegacyMarker('legacy')]
final class LegacyAttributedConsumer {}

$legacy = new LegacyExample();
$legacySerializedPayload = sprintf(
    'O:%d:"%s":0:{}',
    strlen(LegacySerializedExample::class),
    LegacySerializedExample::class,
);
$restoredLegacy = unserialize($legacySerializedPayload, ['allowed_classes' => true]);

$result = [
    'lazy' => $beforeLegacyLookup,
    'class' => [
        'label' => $legacy->label(),
        'canonical_instance' => $legacy instanceof CanonicalExample,
        'legacy_instance' => $legacy instanceof LegacyExample,
        'shared_static_state' => CanonicalExample::calls(),
    ],
    'interface' => (new LegacyConsumer())->label(),
    'trait' => (new LegacyConsumer())->traitLabel(),
    'enum' => LegacyMode::Strict->value,
    'attribute' => (new ReflectionClass(LegacyAttributedConsumer::class))
        ->getAttributes(CanonicalMarker::class, ReflectionAttribute::IS_INSTANCEOF)[0]
        ->newInstance()
        ->value,
    'identity' => [
        'runtime_class' => $legacy::class,
        'reflection_class' => (new ReflectionClass(LegacyExample::class))->getName(),
        'serialized' => serialize($legacy),
        'legacy_payload_restored_as' => $restoredLegacy::class,
    ],
    'optional_adapter_loaded' => class_exists(Studio\Gesso\OptionalAdapter::class, false),
    'unknown_legacy_exists' => class_exists(Studio\OpenApiContractTesting\MissingType::class),
];

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
