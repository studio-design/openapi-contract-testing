<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Schema\Fixture\AutoDiscovery\Drifting;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/_shared/components/schemas/enums/NotificationCodeEnum.json')]
enum AutoDiscoveryDriftingEnum: string
{
    case StudioPaymentOld = 'studioPaymentOld';
    case StudioPaymentNew = 'studioPaymentNew';
    // Spec lists "deprecated" but this enum does not (spec-only drift).
    case BetaFeature = 'betaFeature'; // PHP-only drift.
}
