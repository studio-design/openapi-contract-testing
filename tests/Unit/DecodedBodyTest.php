<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\DecodedBody;

class DecodedBodyTest extends TestCase
{
    #[Test]
    public function absent_carries_no_value_and_is_not_present(): void
    {
        $body = DecodedBody::absent();

        $this->assertFalse($body->present);
        $this->assertNull($body->value);
    }

    #[Test]
    public function present_carries_the_decoded_value(): void
    {
        $body = DecodedBody::present(['id' => 1]);

        $this->assertTrue($body->present);
        $this->assertSame(['id' => 1], $body->value);
    }

    #[Test]
    public function present_distinguishes_a_literal_null_value_from_an_absent_body(): void
    {
        // The whole reason the envelope exists: a body of the literal JSON
        // `null` is PRESENT — `present` stays true even though the value is
        // `null`, which an `absent()` body cannot express.
        $body = DecodedBody::present(null);

        $this->assertTrue($body->present);
        $this->assertNull($body->value);
        // The contrast an absent body cannot express: same `null` value, but
        // `present` flips — that single bit is the whole point of the envelope.
        $this->assertFalse(DecodedBody::absent()->present);
    }

    #[Test]
    public function present_carries_scalar_values(): void
    {
        $body = DecodedBody::present(42);

        $this->assertTrue($body->present);
        $this->assertSame(42, $body->value);
    }

    #[Test]
    public function from_legacy_maps_a_plain_null_to_an_absent_body(): void
    {
        // Backward compatibility: callers of the public validators have always
        // signalled "no body" with a plain PHP `null`. fromLegacy() preserves
        // that meaning so the `mixed` validate() signature stays unchanged.
        $body = DecodedBody::fromLegacy(null);

        $this->assertFalse($body->present);
        $this->assertNull($body->value);
    }

    #[Test]
    public function from_legacy_maps_a_non_null_value_to_a_present_body(): void
    {
        $body = DecodedBody::fromLegacy(['name' => 'Rex']);

        $this->assertTrue($body->present);
        $this->assertSame(['name' => 'Rex'], $body->value);
    }

    #[Test]
    public function from_legacy_passes_a_decoded_body_through_unchanged(): void
    {
        // Adapters already build a DecodedBody; fromLegacy() must not double-wrap
        // it when that envelope reaches the public validator's `mixed` parameter.
        $original = DecodedBody::present(null);

        $this->assertSame($original, DecodedBody::fromLegacy($original));
    }
}
