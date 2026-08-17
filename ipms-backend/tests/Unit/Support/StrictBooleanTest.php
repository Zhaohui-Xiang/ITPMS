<?php

namespace Tests\Unit\Support;

use App\Support\StrictBoolean;
use PHPUnit\Framework\TestCase;

final class StrictBooleanTest extends TestCase
{
    public function test_standard_truthy_values_are_enabled(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE', 'yes', 'YES', 'on', 'ON'] as $value) {
            $this->assertTrue(StrictBoolean::parse($value));
        }
    }

    public function test_standard_falsy_and_invalid_values_fail_closed(): void
    {
        foreach ([false, 0, '0', 'false', 'FALSE', 'no', 'NO', 'off', 'OFF', '', null, 'invalid', '2', 2, []] as $value) {
            $this->assertFalse(StrictBoolean::parse($value));
        }
    }
}
