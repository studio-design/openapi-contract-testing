<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit\Fixture\EnumDrift\Drifting;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/_shared/components/schemas/enums/NotificationCodeEnum.json')]
enum DriftingNotification: string
{
    case StudioPaymentOld = 'studioPaymentOld';
    case StudioPaymentNew = 'studioPaymentNew';
    // Spec lists "deprecated" but this enum lacks it (spec-only drift).
    case BetaFeature = 'betaFeature'; // PHP-only drift.
}
