<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility\Fixture;

use Studio\Gesso\Tests\Unit\Compatibility\Fixture\PublicApiReturnTypeFixture as ExplicitReturnType;

final class PublicApiReturnTypeFixture
{
    public function declaredAsSelf(): self
    {
        return $this;
    }

    public function declaredAsClassName(): ExplicitReturnType
    {
        return $this;
    }
}
