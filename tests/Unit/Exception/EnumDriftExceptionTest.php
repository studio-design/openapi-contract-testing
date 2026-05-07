<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\EnumDriftException;
use Studio\OpenApiContractTesting\Schema\EnumDriftReport;

class EnumDriftExceptionTest extends TestCase
{
    #[Test]
    public function ctor_rejects_empty_reports_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one EnumDriftReport');

        new EnumDriftException([], 'msg');
    }

    #[Test]
    public function ctor_rejects_clean_reports(): void
    {
        $clean = new EnumDriftReport(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpOnly: [],
            specOnly: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hasDrift()');

        new EnumDriftException([$clean], 'msg');
    }

    #[Test]
    public function ctor_accepts_drifting_reports(): void
    {
        $drifting = new EnumDriftReport(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpOnly: ['a'],
            specOnly: [],
        );

        $exception = new EnumDriftException([$drifting], 'msg');

        $this->assertSame('msg', $exception->getMessage());
        $this->assertCount(1, $exception->reports);
    }

    #[Test]
    public function ctor_rejects_mixed_clean_and_drifting_reports(): void
    {
        $clean = new EnumDriftReport(
            enumFqcn: 'A',
            specPath: 'a.json',
            phpOnly: [],
            specOnly: [],
        );
        $drifting = new EnumDriftReport(
            enumFqcn: 'B',
            specPath: 'b.json',
            phpOnly: ['x'],
            specOnly: [],
        );

        $this->expectException(InvalidArgumentException::class);

        new EnumDriftException([$drifting, $clean], 'msg');
    }
}
