<?php

namespace App\Console\Commands;

use App\Enums\HubWebhookStatus;
use App\Models\HubWebhookEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Retenção da caixa de entrada dos webhooks do Hub: o `payload` traz dados
 * pessoais, então eventos encerrados não ficam para sempre.
 *
 * - PROCESSADO há mais de `services.hub.webhook_retention_days` (30);
 * - FALHA_PERMANENTE sem nova tentativa há mais de
 *   `services.hub.webhook_failed_retention_days` (90), tempo para o operador
 *   analisar e reprocessar.
 *
 * Pendentes e falhas temporárias nunca são apagados aqui. Depois de apagado,
 * uma reentrega do mesmo `id` seria processada de novo — o Hub não reenvia
 * avisos tão antigos, e o retrato é idempotente.
 */
#[Signature('hub:limpar-webhooks {--dry-run : Só conta o que seria apagado}')]
#[Description('Apaga da caixa de entrada os webhooks do Hub encerrados além do prazo de retenção.')]
class PurgeHubWebhooks extends Command
{
    public function handle(): int
    {
        $processed = HubWebhookEvent::query()
            ->where('status', HubWebhookStatus::Processed->value)
            ->where('processado_em', '<', now()->subDays(max(1, (int) config('services.hub.webhook_retention_days', 30))));

        $failed = HubWebhookEvent::query()
            ->where('status', HubWebhookStatus::PermanentFailure->value)
            ->where('updated_at', '<', now()->subDays(max(1, (int) config('services.hub.webhook_failed_retention_days', 90))));

        if ($this->option('dry-run')) {
            $this->info("Seriam apagados {$processed->count()} processado(s) e {$failed->count()} com falha permanente.");

            return self::SUCCESS;
        }

        $deletedProcessed = $processed->delete();
        $deletedFailed = $failed->delete();

        $this->info("Apagados {$deletedProcessed} processado(s) e {$deletedFailed} com falha permanente.");

        return self::SUCCESS;
    }
}
