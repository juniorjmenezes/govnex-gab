<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'gabinete_id' => Gabinete::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::Advisor,
            'is_active' => true,
            'email_verified_at' => now(),
            'last_login_at' => null,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function forGabinete(Gabinete $gabinete): static
    {
        return $this->state(fn (): array => ['gabinete_id' => $gabinete->getKey()]);
    }

    public function root(): static
    {
        return $this->state(fn (): array => [
            'gabinete_id' => null,
            'role' => UserRole::Root,
        ]);
    }

    public function councilor(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Councilor]);
    }

    public function chiefOfStaff(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::ChiefOfStaff]);
    }

    public function advisor(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Advisor]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
