<?php

namespace App\Console\Commands;

use App\Models\Eleicao;
use App\Services\Politics\ElectionLifecycleService;
use Illuminate\Console\Command;

class TallyElections extends Command
{
    protected $signature = 'politics:tally-elections
        {--eleicao= : ID da eleição; sem ele, só as que estão entre o 1º turno e alguns dias depois do último turno}';

    protected $description = 'Apura as eleições em curso: grava o resultado de todas as disputas do feed do TSE (presidente, governador, senador e deputados) e classifica quem foi ao 2º turno';

    public function handle(ElectionLifecycleService $lifecycle): int
    {
        $electionId = $this->option('eleicao');
        $today = today()->toDateString();
        // A folga cobre a apuração que vira a noite e a totalização das
        // cadeiras proporcionais, que fecha depois da majoritária.
        $cutoff = today()->subDays(3)->toDateString();

        $elections = Eleicao::query()
            ->when(
                $electionId !== null,
                fn ($query) => $query->whereKey((int) $electionId),
                // Fora dessa janela não há nada novo a descobrir: antes do 1º
                // turno não há apuração, e depois dela o resultado já foi
                // gravado.
                fn ($query) => $query
                    ->whereDate('primeiro_turno_em', '<=', $today)
                    ->where(fn ($window) => $window
                        ->whereDate('segundo_turno_em', '>=', $cutoff)
                        ->orWhere(fn ($single) => $single
                            ->whereNull('segundo_turno_em')
                            ->whereDate('primeiro_turno_em', '>=', $cutoff))),
            )
            ->get();

        foreach ($elections as $election) {
            ['recorded' => $recorded, 'classified' => $classified] = $lifecycle->tally($election);
            $this->components->info(
                "{$election->nome}: {$recorded} disputa(s) com resultado gravado, {$classified} classificada(s) para o 2º turno",
            );
        }

        return self::SUCCESS;
    }
}
