<?php

namespace App\Jobs;

use App\Models\Election;
use App\Services\Politics\ElectionLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Apura uma eleição fora do ciclo horário do comando agendado
 * (`politics:tally-elections`) — usado pelo botão "Apurar agora" da tela da
 * eleição. ElectionLifecycleService::tally() é idempotente, então repetir o
 * disparo é seguro.
 */
class TallyElection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $electionId,
    ) {
        $this->onConnection((string) config('services.tse.queue_connection', 'database'));
        $this->onQueue('tse');
    }

    public function handle(ElectionLifecycleService $lifecycle): void
    {
        $election = Election::query()->find($this->electionId);

        if ($election instanceof Election) {
            $lifecycle->tally($election);
        }
    }
}
