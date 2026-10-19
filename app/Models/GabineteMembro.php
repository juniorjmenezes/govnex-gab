<?php

namespace App\Models;

use App\Enums\AccessRole;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gabinete_id
 * @property int $usuario_id
 * @property AccessRole $papel
 * @property bool $ativo
 * @property Carbon|null $ingressou_em
 * @property-read User $usuario
 */
class GabineteMembro extends Model
{
    protected $table = 'gabinete_membros';

    protected $guarded = [];

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class, 'gabinete_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    protected function casts(): array
    {
        return [
            'papel' => AccessRole::class,
            'ativo' => 'boolean',
            'ingressou_em' => 'datetime',
            'desativado_em' => 'datetime',
        ];
    }

    /**
     * Restringe `$query` (sobre `users`) a membros ativos de `$gabineteId`.
     *
     * `users.gabinete_id`/`is_active` continuam a fonte que Policies, filas e
     * comandos fora de uma requisição com contexto resolvido leem (ver
     * `HubVinculoSyncService::reprojetarContextoLegado()`), mas o toggle
     * "pausar" do Time (`TeamController::update()`) só grava a linha de
     * `gabinete_membros` — nunca aqueles campos. Sem esta exclusão, a pessoa
     * pausada continua selecionável como responsável e continua recebendo
     * avisos por WhatsApp em qualquer outro ponto do sistema.
     *
     * Vale só para quem TEM vínculo explícito marcado inativo: a ausência de
     * linha em `gabinete_membros` (comum — ela só nasce ao passar pelo Time,
     * convite ou sincronização do Hub) não torna ninguém inativo.
     *
     * Usável tanto em Eloquent quanto nas closures de `Rule::exists`/
     * `Rule::unique`, que recebem um query builder puro sobre a tabela.
     */
    public static function constrainActiveUsers(QueryContract $query, int $gabineteId): QueryContract
    {
        return $query
            ->where('gabinete_id', $gabineteId)
            ->where('is_active', true)
            ->whereNotExists(function (QueryContract $inactive) use ($gabineteId): void {
                $inactive->selectRaw('1')
                    ->from('gabinete_membros')
                    ->whereColumn('gabinete_membros.usuario_id', 'users.id')
                    ->where('gabinete_membros.gabinete_id', $gabineteId)
                    ->where('gabinete_membros.ativo', false);
            });
    }

    public static function isActiveMember(User $user, int $gabineteId): bool
    {
        return static::constrainActiveUsers(User::query()->whereKey($user->id), $gabineteId)->exists();
    }

    /**
     * Vínculos EXPLICITAMENTE inativos nos gabinetes informados, por
     * gabinete — para checagens em lote (evita N+1 ao percorrer vários
     * contatos/usuários de uma vez, ex. convocação institucional).
     *
     * @param  list<int>  $gabineteIds
     * @return array<int, array<int, true>>
     */
    public static function inactiveOverrides(array $gabineteIds): array
    {
        $overrides = [];
        static::query()
            ->whereIn('gabinete_id', $gabineteIds)
            ->where('ativo', false)
            ->get(['gabinete_id', 'usuario_id'])
            ->each(function (self $membro) use (&$overrides): void {
                $overrides[(int) $membro->gabinete_id][(int) $membro->usuario_id] = true;
            });

        return $overrides;
    }
}
