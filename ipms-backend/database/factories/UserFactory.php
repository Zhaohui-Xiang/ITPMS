<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
            'is_staff' => false,
            'date_joined' => now(),
            'user_type' => UserType::INTERNAL->value,
            'phone' => fake()->optional()->numerify('1##########'),
            'must_change_password' => false,
            'is_disabled' => false,
            'display_name' => "{$firstName} {$lastName}",
            'created_by_id' => null,
            'last_login' => null,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (): array => [
            'user_type' => UserType::INTERNAL->value,
        ]);
    }

    public function supplier(): static
    {
        return $this->state(fn (): array => [
            'user_type' => UserType::SUPPLIER->value,
        ]);
    }

    public function systemUser(): static
    {
        return $this->state(fn (): array => [
            'user_type' => UserType::SYSTEM_USER->value,
        ]);
    }

    public function withRole(string $code): static
    {
        $attributes = $this->canonicalRoleAttributes($code);

        return $this->afterCreating(function (User $user) use ($attributes, $code): void {
            $role = Role::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $attributes['name'],
                    'user_type' => $attributes['user_type']->value,
                    'is_system' => true,
                ],
            );

            $user->roles()->attach($role);
        });
    }

    public function superAdmin(): static
    {
        return $this->internal()->withRole('super_admin');
    }

    /**
     * @return array{name: string, user_type: UserType}
     */
    private function canonicalRoleAttributes(string $code): array
    {
        return match ($code) {
            'super_admin' => ['name' => '超级管理员', 'user_type' => UserType::INTERNAL],
            'it_pm' => ['name' => 'IT项目经理', 'user_type' => UserType::INTERNAL],
            'it_member' => ['name' => 'IT项目成员', 'user_type' => UserType::INTERNAL],
            'supplier_pm' => ['name' => '供应商项目经理', 'user_type' => UserType::SUPPLIER],
            'supplier_dev' => ['name' => '开发人员', 'user_type' => UserType::SUPPLIER],
            'supplier_tester' => ['name' => '测试人员', 'user_type' => UserType::SUPPLIER],
            'requester' => ['name' => '需求提出人', 'user_type' => UserType::SYSTEM_USER],
            default => throw new InvalidArgumentException("Unknown role code [{$code}]."),
        };
    }
}
