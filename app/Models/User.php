<?php

namespace App\Models;

use App\Enums\EntidadeRole;
use App\Enums\GabineteRole;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $gabinete_id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_active
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Gabinete|null $gabinete
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class);
    }

    /** @return HasMany<EntidadeMembro, $this> */
    public function entidades(): HasMany
    {
        return $this->hasMany(EntidadeMembro::class, 'usuario_id');
    }

    /** @return HasMany<GabineteMembro, $this> */
    public function gabinetes(): HasMany
    {
        return $this->hasMany(GabineteMembro::class, 'usuario_id');
    }

    public function entidadeRole(int $entidadeId): ?EntidadeRole
    {
        $role = $this->entidades()
            ->where('entidade_id', $entidadeId)
            ->where('ativo', true)
            ->value('papel');

        return $role instanceof EntidadeRole
            ? $role
            : (is_string($role) ? EntidadeRole::tryFrom($role) : null);
    }

    public function gabineteRole(int $gabineteId): ?GabineteRole
    {
        $role = $this->gabinetes()
            ->where('gabinete_id', $gabineteId)
            ->where('ativo', true)
            ->value('papel');

        return $role instanceof GabineteRole
            ? $role
            : (is_string($role) ? GabineteRole::tryFrom($role) : null);
    }

    public function canAccessEntidade(int $entidadeId): bool
    {
        return $this->isRoot() || $this->entidadeRole($entidadeId) !== null;
    }

    public function canAccessGabinete(int $gabineteId): bool
    {
        return $this->isRoot() || $this->gabineteRole($gabineteId) !== null;
    }

    public function canManageEntidade(int $entidadeId): bool
    {
        return $this->isRoot()
            || $this->entidadeRole($entidadeId)?->canManageEntidade() === true;
    }

    public function canManageGabinete(int $gabineteId): bool
    {
        return $this->isRoot() || $this->gabineteRole($gabineteId)?->canManageGabinete() === true;
    }

    /** @return HasMany<Demanda, $this> */
    public function demandasAtribuidas(): HasMany
    {
        return $this->hasMany(Demanda::class, 'responsavel_id');
    }

    /** @return HasMany<Appointment, $this> */
    public function compromissosResponsaveis(): HasMany
    {
        return $this->hasMany(Appointment::class, 'responsavel_id');
    }

    /** @return HasOne<WhatsAppContact, $this> */
    public function whatsappContact(): HasOne
    {
        return $this->hasOne(WhatsAppContact::class, 'usuario_id');
    }

    /** @return HasMany<Atendimento, $this> */
    public function atendimentosRealizados(): HasMany
    {
        return $this->hasMany(Atendimento::class, 'atendente_id');
    }

    /** @return HasMany<Evento, $this> */
    public function eventosResponsaveis(): HasMany
    {
        return $this->hasMany(Evento::class, 'responsavel_id');
    }

    public function isRoot(): bool
    {
        return $this->role->isRoot();
    }

    public function belongsToSameGabineteAs(User $user): bool
    {
        if ($this->gabinete_id !== null && $this->gabinete_id === $user->gabinete_id) {
            return true;
        }

        return $this->gabinetes()
            ->where('ativo', true)
            ->whereIn('gabinete_id', $user->gabinetes()->where('ativo', true)->select('gabinete_id'))
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
