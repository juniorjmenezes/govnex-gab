<?php

namespace Database\Factories;

use App\Enums\OfficePosition;
use App\Enums\UserRole;
use App\Models\Office;
use App\Models\OfficeMember;
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
            'gabinete_id' => Office::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::Operator,
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

    /**
     * Atalho de teste: o `role` da fábrica vira o cargo local no gabinete da
     * conta (administrador → chefe de gabinete, operador → assessor, auditor →
     * auditor), porque o GAB não decide mais pelo papel. Sem isso, toda conta
     * de teste cairia na falha fechada (vínculo sem cargo não acessa nada).
     * Para testar o vínculo sem cargo, use `withoutPosition()`; para um cargo
     * específico, `withPosition()`.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $position = match ($user->role) {
                // No máximo 1 chefe e 1 vereador ativos por gabinete: o
                // segundo administrador vira vereador; o terceiro, assessor.
                UserRole::Administrator => self::freeLeadership($user),
                UserRole::Operator => OfficePosition::Advisor,
                UserRole::Auditor => OfficePosition::Auditor,
                UserRole::Root => null,
            };

            self::setPosition($user, $position);
        });
    }

    public function withPosition(?OfficePosition $position): static
    {
        return $this->afterCreating(fn (User $user) => self::setPosition($user, $position));
    }

    public function withoutPosition(): static
    {
        return $this->withPosition(null);
    }

    private static function freeLeadership(User $user): OfficePosition
    {
        $taken = OfficeMember::query()
            ->where('gabinete_id', $user->gabinete_id)
            ->where('ativo', true)
            ->pluck('cargo')
            ->map(fn ($cargo) => $cargo instanceof OfficePosition ? $cargo : OfficePosition::tryFrom((string) $cargo))
            ->all();

        foreach ([OfficePosition::ChiefOfStaff, OfficePosition::Councilor] as $position) {
            if (! in_array($position, $taken, true)) {
                return $position;
            }
        }

        return OfficePosition::Advisor;
    }

    private static function setPosition(User $user, ?OfficePosition $position): void
    {
        if ($user->gabinete_id === null) {
            return;
        }

        OfficeMember::query()
            ->where('gabinete_id', $user->gabinete_id)
            ->where('usuario_id', $user->id)
            ->update(['cargo' => $position?->value]);
    }

    public function forGabinete(Office $gabinete): static
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

    public function administrator(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Administrator]);
    }

    public function operator(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Operator]);
    }

    public function auditor(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Auditor]);
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
