<?php

namespace App\Models;

use App\Enums\EntityType;
use App\Enums\OfficePosition;
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
 * @property string|null $hub_user_id
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
 * @property-read Office|null $gabinete
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /** @return BelongsTo<Office, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return HasMany<EntityMember, $this> */
    public function entidades(): HasMany
    {
        return $this->hasMany(EntityMember::class, 'usuario_id');
    }

    /** @return HasMany<OfficeMember, $this> */
    public function gabinetes(): HasMany
    {
        return $this->hasMany(OfficeMember::class, 'usuario_id');
    }

    /** Cargo local do vínculo ativo com `$gabineteId`, ou `null` sem cargo. */
    public function gabinetePosition(int $gabineteId): ?OfficePosition
    {
        $position = $this->gabinetes()
            ->where('gabinete_id', $gabineteId)
            ->where('ativo', true)
            ->value('cargo');

        return $position instanceof OfficePosition
            ? $position
            : (is_string($position) ? OfficePosition::tryFrom($position) : null);
    }

    /** Vínculo ativo com a entidade (o Hub só diz "está vinculado"). */
    public function canAccessEntidade(int $entidadeId): bool
    {
        return $this->isRoot() || $this->entidades()
            ->where('entidade_id', $entidadeId)
            ->where('ativo', true)
            ->exists();
    }

    /**
     * Entra no gabinete quem tem vínculo ativo **com cargo** (falha fechada):
     * vínculo sem cargo vê o diretório da entidade, mas não os dados do
     * gabinete, até alguém atribuir o cargo.
     */
    public function canAccessGabinete(int $gabineteId): bool
    {
        return $this->isRoot() || $this->gabinetePosition($gabineteId) !== null;
    }

    /**
     * Administra a entidade: root ou, em gabinete independente (a entidade
     * existe só por causa dele), quem lidera o gabinete dela (vereador ou
     * chefe de gabinete). Câmara e Prefeitura ainda não têm cargo de
     * entidade: só root (pendente em docs/INTEGRACAO_GOVNEX_HUB.md).
     */
    public function canManageEntidade(int $entidadeId): bool
    {
        if ($this->isRoot()) {
            return true;
        }

        return Entity::query()->whereKey($entidadeId)->where('tipo', EntityType::IndependentOffice->value)->exists()
            && $this->gabinetes()
                ->where('ativo', true)
                ->whereIn('cargo', [OfficePosition::Councilor->value, OfficePosition::ChiefOfStaff->value])
                ->whereIn('gabinete_id', fn ($query) => $query->select('id')->from('gabinetes')->where('entidade_id', $entidadeId))
                ->exists();
    }

    /** Lidera o gabinete pelo cargo (vereador ou chefe de gabinete), ou é root. */
    public function canManageGabinete(int $gabineteId): bool
    {
        return $this->isRoot() || $this->gabinetePosition($gabineteId)?->leadsOffice() === true;
    }

    /** @return HasMany<Demand, $this> */
    public function demandasAtribuidas(): HasMany
    {
        return $this->hasMany(Demand::class, 'responsavel_id');
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

    /** @return HasMany<Attendance, $this> */
    public function atendimentosRealizados(): HasMany
    {
        return $this->hasMany(Attendance::class, 'atendente_id');
    }

    /** @return HasMany<Event, $this> */
    public function eventosResponsaveis(): HasMany
    {
        return $this->hasMany(Event::class, 'responsavel_id');
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
