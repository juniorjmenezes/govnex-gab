<?php

namespace App\Services\Politics;

use App\Enums\CandidateScope;
use App\Enums\ElectionType;
use App\Jobs\SyncOfficeSectionVotesFromGovnexApi;
use App\Models\CandidatoFavorito;
use App\Models\CandidatoPolitico;
use App\Models\Eleicao;
use App\Models\Gabinete;

class OfficeHolderCandidateResolver
{
    public function resolveOffice(Gabinete $office): ?CandidatoPolitico
    {
        $number = $office->numero_eleitoral;

        if ($number === null || $office->municipio_eleitoral_id === null) {
            $office->forceFill(['candidato_titular_id' => null])->save();

            return null;
        }

        $election = Eleicao::query()
            ->where('tipo', ElectionType::Municipal)
            ->whereDate('primeiro_turno_em', '<=', today())
            ->latest('primeiro_turno_em')
            ->first();
        $candidate = $election
            ? CandidatoPolitico::query()
                ->where('eleicao_id', $election->id)
                ->where('abrangencia', CandidateScope::Municipal)
                ->where('municipio_eleitoral_id', $office->municipio_eleitoral_id)
                ->where('cargo', 'Vereador')
                ->where('numero', $number)
                ->first()
            : null;

        $office->forceFill([
            'candidato_titular_id' => $candidate?->id,
        ])->save();

        if ($candidate instanceof CandidatoPolitico) {
            $this->favorite($office, $candidate);
        }

        return $candidate;
    }

    /**
     * O titular entra nos favoritos do próprio gabinete assim que é
     * resolvido, e não sai de lá (ver PoliticalPanelController::unfavorite()).
     * É o candidato que o gabinete acompanha por definição: favoritar é o que
     * liga a coleta de notícias e o destaque dele nas pesquisas, e depender de
     * alguém lembrar de marcar deixava justamente o próprio titular de fora.
     *
     * Sem autor em `escolhido_por_id`: ninguém escolheu, veio do vínculo do
     * gabinete. E sem escopo de tenant — isto roda em job e em importação,
     * onde não há sessão para o escopo global resolver o gabinete.
     */
    private function favorite(Gabinete $office, CandidatoPolitico $candidate): void
    {
        $exists = CandidatoFavorito::withoutGlobalScopes()
            ->where('gabinete_id', $office->id)
            ->where('candidato_politico_id', $candidate->id)
            ->exists();

        if ($exists) {
            return;
        }

        CandidatoFavorito::withoutGlobalScopes()->forceCreate([
            'gabinete_id' => $office->id,
            'candidato_politico_id' => $candidate->id,
            'escolhido_por_id' => null,
        ]);
    }

    /**
     * Resolve o titular dos gabinetes em lote — é o que roda no fim de uma
     * importação de candidaturas.
     *
     * Um gabinete que passa a ter titular agora (a eleição dele só foi
     * importada depois do cadastro) tem a votação por seção importada em
     * seguida: é o único dataset filtrado pelo titular, e sem isso o mapa
     * eleitoral dele só apareceria na próxima sincronização manual. O job
     * confere as condições e não faz nada se a votação por seção nunca tiver
     * sido sincronizada.
     */
    public function resolve(?int $officeId = null): int
    {
        $resolved = 0;

        Gabinete::withoutGlobalScopes()
            ->whereNotNull('numero_eleitoral')
            ->when($officeId !== null, fn ($query) => $query->whereKey($officeId))
            ->get()
            ->each(function (Gabinete $office) use (&$resolved): void {
                $previousId = $office->candidato_titular_id;
                $candidate = $this->resolveOffice($office);

                if (! $candidate instanceof CandidatoPolitico) {
                    return;
                }

                $resolved++;

                if ($candidate->id !== $previousId) {
                    SyncOfficeSectionVotesFromGovnexApi::dispatch($office->id);
                }
            });

        return $resolved;
    }
}
