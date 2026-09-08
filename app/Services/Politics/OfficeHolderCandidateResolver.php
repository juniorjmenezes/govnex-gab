<?php

namespace App\Services\Politics;

use App\Enums\CandidateScope;
use App\Enums\ElectionType;
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

        return $candidate;
    }

    public function resolve(?int $officeId = null): int
    {
        $resolved = 0;

        Gabinete::withoutGlobalScopes()
            ->whereNotNull('numero_eleitoral')
            ->when($officeId !== null, fn ($query) => $query->whereKey($officeId))
            ->get()
            ->each(function (Gabinete $office) use (&$resolved): void {
                if ($this->resolveOffice($office) instanceof CandidatoPolitico) {
                    $resolved++;
                }
            });

        return $resolved;
    }
}
