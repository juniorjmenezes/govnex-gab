<?php

namespace App\Console\Commands;

use App\Enums\HubWebhookStatus;
use App\Models\HubWebhookEvent;
use App\Services\Hub\HubWebhookInbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reprocessa a caixa de entrada dos webhooks do Hub (padrão do GHO, mas
 * síncrono: o GEP processa no próprio comando, pelo `HubWebhookInbox`).
 *
 * Sem `--id`: eventos em FALHA_TRANSITORIA cuja próxima tentativa já venceu,
 * RECEBIDO esquecido há mais de 5 minutos e PROCESSANDO preso há mais de 15
 * (a requisição morreu no meio) — sempre abaixo do teto
 * `services.hub.webhook_max_attempts`. Com `--id`, reprocessa um evento
 * específico, inclusive FALHA_PERMANENTE.
 */
#[Signature('hub:reprocessar-webhooks {--id= : evento_id (uuid do Hub) a reprocessar, inclusive FALHA_PERMANENTE} {--dry-run : Só lista o que seria reprocessado}')]
#[Description('Reprocessa webhooks do Hub pendentes ou com falha temporária; --id reprocessa um evento explicitamente.')]
class ReprocessHubWebhooks extends Command
{
    public function handle(HubWebhookInbox $inbox): int
    {
        if (($id = $this->option('id')) !== null) {
            return $this->retryOne((string) $id, $inbox);
        }

        $max = (int) config('services.hub.webhook_max_attempts', 5);

        if (! $this->option('dry-run')) {
            $this->releaseStuck($max);
        }

        $pending = HubWebhookEvent::query()
            ->where(function ($query): void {
                $query->where(fn ($q) => $q->where('status', HubWebhookStatus::TransientFailure->value)
                    ->where(fn ($r) => $r->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', now())))
                    ->orWhere(fn ($q) => $q->where('status', HubWebhookStatus::Received->value)
                        ->where('recebido_em', '<', now()->subMinutes(5)));
            })
            ->where('tentativas', '<', $max)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $ok = 0;

        foreach ($pending as $event) {
            if ($this->option('dry-run')) {
                $this->line("evento {$event->evento_id} ({$event->tipo}) — tentativa ".($event->tentativas + 1));

                continue;
            }

            $result = $inbox->retry($event);

            if ($result === null) {
                continue;
            }

            $this->line("evento {$event->evento_id} ({$event->tipo}) → HTTP {$result[0]}");
            $ok += $result[0] === 200 ? 1 : 0;
        }

        $this->info($this->option('dry-run')
            ? "{$pending->count()} evento(s) seriam reprocessados."
            : "{$pending->count()} evento(s) reprocessado(s), {$ok} com sucesso.");

        return self::SUCCESS;
    }

    /**
     * PROCESSANDO há mais de 15 minutos: a requisição morreu no meio. UPDATE
     * condicional, para não atropelar quem terminou entre a leitura e a escrita.
     */
    private function releaseStuck(int $max): void
    {
        $stuck = fn () => HubWebhookEvent::query()
            ->where('status', HubWebhookStatus::Processing->value)
            ->where('ultima_tentativa_em', '<', now()->subMinutes(15));

        $stuck()->where('tentativas', '>=', $max)->update([
            'status' => HubWebhookStatus::PermanentFailure->value,
            'ultimo_erro' => 'Tentativas esgotadas: processamento interrompido.',
            'proxima_tentativa_em' => null,
        ]);

        $stuck()->where('tentativas', '<', $max)->update([
            'status' => HubWebhookStatus::TransientFailure->value,
            'ultimo_erro' => 'Processamento interrompido.',
            'proxima_tentativa_em' => now(),
        ]);
    }

    private function retryOne(string $id, HubWebhookInbox $inbox): int
    {
        $event = HubWebhookEvent::query()->where('evento_id', $id)->first();

        if ($event === null) {
            $this->error("Evento {$id} não encontrado.");

            return self::FAILURE;
        }

        if (in_array($event->status, [HubWebhookStatus::Processed, HubWebhookStatus::Processing], true)) {
            $this->error("Evento {$id} está {$event->status->value}; nada a reprocessar.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line("Reprocessaria o evento {$id} ({$event->status->value}).");

            return self::SUCCESS;
        }

        $result = $inbox->retry($event, force: true);

        if ($result === null) {
            $this->error("Evento {$id} mudou de estado; tente de novo.");

            return self::FAILURE;
        }

        $this->line("evento {$id} → HTTP {$result[0]} ({$event->refresh()->status->value})");

        return $result[0] === 200 ? self::SUCCESS : self::FAILURE;
    }
}
