<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility\Fixture;

trait PublicApiTraitSurfaceFixture
{
    public const PUBLIC_CONSTANT = 'public';
    protected const PROTECTED_CONSTANT = 'protected';
    private const PRIVATE_CONSTANT = 'private';

    /** @internal Test member that must remain outside the compatibility surface. */
    private const INTERNAL_CONSTANT = 'internal';
    protected static ?int $protectedProperty = null;
    public string $publicProperty = 'public';
    private bool $privateProperty = false;

    /** @internal Test member that must remain outside the compatibility surface. */
    private string $internalProperty = 'internal';

    public function publicMethod(): void {}

    protected static function protectedMethod(int $value): string
    {
        return (string) $value;
    }

    private function privateMethod(): string
    {
        return self::PRIVATE_CONSTANT . ($this->privateProperty ? '-true' : '-false');
    }

    /** @internal Test member that must remain outside the compatibility surface. */
    private function internalMethod(): string
    {
        return self::INTERNAL_CONSTANT;
    }
}
