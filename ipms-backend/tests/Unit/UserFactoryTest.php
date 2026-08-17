<?php

namespace Tests\Unit;

use App\Enums\UserType;
use App\Models\User;
use Tests\TestCase;

final class UserFactoryTest extends TestCase
{
    public function test_with_role_sets_user_type_to_the_canonical_role_type(): void
    {
        $user = User::factory()->withRole('supplier_pm')->make();

        $this->assertSame(UserType::SUPPLIER->value, $user->user_type);
    }
}
