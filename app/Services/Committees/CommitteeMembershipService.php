<?php

namespace App\Services\Committees;

use App\Enums\CommitteeRole;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Models\ComissaoLegislativa;
use App\Models\ComissaoMembro;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regras de composição de uma comissão, fora da camada HTTP para valerem em
 * qualquer chamador (controller, comando, seed):
 *
 * - só gabinete de vereador ativo da mesma entidade da comissão;
 * - um gabinete aparece uma vez por comissão (também garantido pelo índice
 *   único `committee_members_office_unique`);
 * - a pessoa indicada, quando houver, tem vínculo ativo com aquele gabinete;
 * - um presidente por comissão;
 * - comissão extinta não recebe membros.
 *
 * A linha da comissão é travada durante a escrita para que duas inclusões
 * simultâneas não furem a checagem de duplicidade ou de presidente.
 */
class CommitteeMembershipService
{
    public function add(
        ComissaoLegislativa $comissao,
        int $gabineteId,
        ?int $usuarioId,
        CommitteeRole $cargo,
        ?CarbonInterface $ingressouEm = null,
    ): ComissaoMembro {
        return DB::transaction(function () use ($comissao, $gabineteId, $usuarioId, $cargo, $ingressouEm): ComissaoMembro {
            $locked = $this->lock($comissao);

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'gabinete_id' => 'Comissão extinta não recebe novos membros.',
                ]);
            }

            $this->assertEligibleOffice($locked, $gabineteId);

            if ($locked->membros()->where('gabinete_id', $gabineteId)->exists()) {
                throw ValidationException::withMessages([
                    'gabinete_id' => 'Este gabinete já faz parte da comissão.',
                ]);
            }

            $this->assertUserBelongsToOffice($gabineteId, $usuarioId);
            $this->assertSinglePresident($locked, $cargo);

            $membro = new ComissaoMembro([
                'usuario_id' => $usuarioId,
                'cargo' => $cargo,
                'ingressou_em' => ($ingressouEm ?? CarbonImmutable::today())->toDateString(),
            ]);
            $membro->forceFill([
                'comissao_id' => $locked->id,
                'gabinete_id' => $gabineteId,
            ]);

            try {
                $membro->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'gabinete_id' => 'Este gabinete já faz parte da comissão.',
                ]);
            }

            return $membro;
        });
    }

    /** Troca a pessoa indicada e/ou o cargo, sem duplicar a linha do gabinete. */
    public function update(ComissaoMembro $membro, ?int $usuarioId, CommitteeRole $cargo): ComissaoMembro
    {
        return DB::transaction(function () use ($membro, $usuarioId, $cargo): ComissaoMembro {
            $locked = $this->lock($membro->comissao);

            $this->assertUserBelongsToOffice($membro->gabinete_id, $usuarioId);
            $this->assertSinglePresident($locked, $cargo, $membro);

            $membro->update(['usuario_id' => $usuarioId, 'cargo' => $cargo]);

            return $membro;
        });
    }

    public function remove(ComissaoMembro $membro): void
    {
        $membro->delete();
    }

    /** Ao extinguir a comissão, encerra a participação de quem ainda está nela. */
    public function closeAll(ComissaoLegislativa $comissao, CarbonInterface $at): void
    {
        $comissao->membros()
            ->whereNull('saiu_em')
            ->update(['saiu_em' => $at->toDateString()]);
    }

    private function lock(ComissaoLegislativa $comissao): ComissaoLegislativa
    {
        return ComissaoLegislativa::query()->whereKey($comissao->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lido sem o escopo global de `Gabinete`: no contexto da Secretaria ele
     * só enxerga a própria Secretaria. O recorte é a entidade da comissão.
     */
    private function assertEligibleOffice(ComissaoLegislativa $comissao, int $gabineteId): void
    {
        $gabinete = Gabinete::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find($gabineteId);

        if ($gabinete === null
            || $gabinete->entidade_id !== $comissao->entidade_id
            || $gabinete->tipo_gabinete !== GabineteType::CouncilorOffice
            || $gabinete->status !== GabineteStatus::Active) {
            throw ValidationException::withMessages([
                'gabinete_id' => 'Selecione um gabinete de vereador ativo desta Câmara.',
            ]);
        }
    }

    private function assertUserBelongsToOffice(int $gabineteId, ?int $usuarioId): void
    {
        if ($usuarioId === null) {
            return;
        }

        $linked = GabineteMembro::query()
            ->where('gabinete_id', $gabineteId)
            ->where('usuario_id', $usuarioId)
            ->where('ativo', true)
            ->exists();

        if (! $linked) {
            throw ValidationException::withMessages([
                'usuario_id' => 'A pessoa indicada precisa fazer parte do gabinete.',
            ]);
        }
    }

    private function assertSinglePresident(
        ComissaoLegislativa $comissao,
        CommitteeRole $cargo,
        ?ComissaoMembro $ignore = null,
    ): void {
        if ($cargo !== CommitteeRole::President) {
            return;
        }

        $taken = $comissao->membros()
            ->where('cargo', CommitteeRole::President->value)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'cargo' => 'A comissão já tem presidente.',
            ]);
        }
    }
}
