<?php

namespace App\Services\Hub;

use App\Enums\HubWebhookStatus;
use App\Models\HubWebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Caixa de entrada durável dos webhooks do Hub (PLATFORM.md §5).
 *
 * O evento é gravado antes de ser processado, e o UNIQUE de `evento_id`
 * garante a idempotência mesmo com duas entregas simultâneas (sem cache). O
 * processamento continua síncrono pelo `HubEventProcessor`, e as respostas
 * ao Hub são as de antes:
 *
 * - 200 com o resultado — processado (inclui os avisos ignorados);
 * - 200 `duplicado` — reentrega de evento já encerrado (PROCESSADO ou
 *   FALHA_PERMANENTE) ou em processamento por outra entrega;
 * - 409 — payload inaplicável (`RuntimeException`): FALHA_PERMANENTE, sem
 *   reprocesso automático, porque repetir daria a mesma resposta;
 * - 500 — falha nossa: FALHA_TRANSITORIA, reprocessável pela reentrega do
 *   Hub e por `hub:reprocessar-webhooks`, até o teto de tentativas.
 */
class HubWebhookInbox
{
    public function __construct(private readonly HubEventProcessor $processor) {}

    /**
     * @param  array<string, mixed>  $event  evento já validado (id, tipo, sistema, ocorrido_em, dados)
     * @return array{0: int, 1: array<string, mixed>} código HTTP e corpo
     */
    public function receive(array $event): array
    {
        $now = now();
        $inserted = HubWebhookEvent::query()->insertOrIgnore([
            'evento_id' => (string) $event['id'],
            'tipo' => (string) $event['tipo'],
            'sistema' => (string) $event['sistema'],
            'status' => HubWebhookStatus::Processing->value,
            'tentativas' => 1,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'ocorrido_em' => isset($event['ocorrido_em']) ? Carbon::parse((string) $event['ocorrido_em']) : null,
            'recebido_em' => $now,
            'ultima_tentativa_em' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = HubWebhookEvent::query()->where('evento_id', (string) $event['id'])->firstOrFail();

        if ($inserted === 1) {
            return $this->run($row);
        }

        // Reentrega. Só tenta de novo o que ficou pendente ou com falha nossa,
        // e só se conseguir "pegar" a linha (outra entrega pode estar nela).
        if (! $this->claim($row, [HubWebhookStatus::Received, HubWebhookStatus::TransientFailure])) {
            return [200, ['ok' => true, 'duplicado' => true]];
        }

        return $this->run($row->refresh());
    }

    /**
     * Reprocessa um evento já gravado (comando). `$force` inclui
     * FALHA_PERMANENTE (reprocesso explícito de operador).
     *
     * @return array{0: int, 1: array<string, mixed>}|null null quando o evento não está reprocessável
     */
    public function retry(HubWebhookEvent $row, bool $force = false): ?array
    {
        $statuses = [HubWebhookStatus::Received, HubWebhookStatus::TransientFailure];

        if ($force) {
            $statuses[] = HubWebhookStatus::PermanentFailure;
        }

        return $this->claim($row, $statuses) ? $this->run($row->refresh()) : null;
    }

    /**
     * Passa a linha para PROCESSANDO só se ela ainda estiver num dos estados
     * esperados — UPDATE condicional, seguro com entregas concorrentes.
     *
     * @param  list<HubWebhookStatus>  $from
     */
    private function claim(HubWebhookEvent $row, array $from): bool
    {
        return HubWebhookEvent::query()
            ->whereKey($row->id)
            ->whereIn('status', array_map(fn (HubWebhookStatus $s): string => $s->value, $from))
            ->update([
                'status' => HubWebhookStatus::Processing->value,
                'tentativas' => $row->tentativas + 1,
                'ultima_tentativa_em' => now(),
                'proxima_tentativa_em' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function run(HubWebhookEvent $row): array
    {
        try {
            $result = $this->processor->processar($row->payload);
        } catch (RuntimeException $exception) {
            // Payload coerente com o contrato mas que não dá para aplicar aqui
            // (pessoa duplicada por e-mail, bloco faltando).
            $row->forceFill([
                'status' => HubWebhookStatus::PermanentFailure,
                'ultimo_erro' => Str::limit($exception->getMessage(), 490),
                'proxima_tentativa_em' => null,
            ])->save();

            Log::warning('Webhook do Hub recusado na aplicação.', [
                'evento' => $row->evento_id,
                'tipo' => $row->tipo,
                'erro' => $exception->getMessage(),
            ]);

            return [409, ['ok' => false, 'message' => $exception->getMessage()]];
        } catch (Throwable $exception) {
            report($exception);

            // Só a classe: a mensagem de uma falha de infraestrutura pode
            // carregar dados do evento (ex.: valores de SQL). O detalhe vai
            // para o `report()`.
            $exhausted = $row->tentativas >= (int) config('services.hub.webhook_max_attempts', 5);
            $row->forceFill([
                'status' => $exhausted ? HubWebhookStatus::PermanentFailure : HubWebhookStatus::TransientFailure,
                'ultimo_erro' => ($exhausted ? 'Tentativas esgotadas: ' : 'Falha temporária: ').$exception::class,
                'proxima_tentativa_em' => $exhausted ? null : now()->addMinutes(min(2 ** $row->tentativas, 60)),
            ])->save();

            return [500, ['ok' => false, 'message' => 'Falha temporária ao processar o webhook.']];
        }

        $row->forceFill([
            'status' => HubWebhookStatus::Processed,
            'resultado' => isset($result['acao']) ? Str::limit((string) $result['acao'], 60, '') : null,
            'ultimo_erro' => null,
            'processado_em' => now(),
        ])->save();

        return [200, ['ok' => true] + $result];
    }
}
