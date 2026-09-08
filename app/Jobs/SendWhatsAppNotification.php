<?php

namespace App\Jobs;

use App\Enums\EntidadeQuota;
use App\Enums\WhatsAppNotificationStatus;
use App\Exceptions\WhatsAppGatewayException;
use App\Models\WhatsAppNotification;
use App\Services\Entidades\EntidadeQuotaService;
use App\Services\WhatsApp\WhatsAppEligibilityService;
use App\Services\WhatsApp\WhatsAppGatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendWhatsAppNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800, 3600];

    public int $timeout = 45;

    public function __construct(public readonly int $notificationId) {}

    public function handle(
        WhatsAppGatewayClient $gateway,
        WhatsAppEligibilityService $eligibility,
        EntidadeQuotaService $quotas,
    ): void {
        $notification = WhatsAppNotification::withoutGlobalScopes()
            ->with(['contact', 'templatePurpose', 'connection'])
            ->find($this->notificationId);
        if (! $notification || $notification->status->isTerminal()) {
            return;
        }
        if ($notification->expira_em->isPast()) {
            $this->finish($notification, WhatsAppNotificationStatus::Expired, 'A notificação expirou antes do envio.', $quotas);

            return;
        }
        $contact = $notification->contact;
        if (! $contact
            || ! hash_equals((string) $notification->telefone_hash, (string) $contact->telefone_hash)) {
            $this->finish($notification, WhatsAppNotificationStatus::Cancelled, 'O contato foi removido ou alterado.', $quotas);

            return;
        }
        $evaluation = $eligibility->evaluate(
            $contact,
            $notification->finalidade,
            $notification->consumo_reservado_em === null,
        );
        if (! $evaluation['eligible'] || ! $evaluation['template'] || ! $evaluation['connection']) {
            $this->finish($notification, WhatsAppNotificationStatus::Suppressed, $evaluation['reason'], $quotas);

            return;
        }
        if ((int) $evaluation['connection']->id !== (int) $notification->entidade_whatsapp_conexao_id
            || (int) $evaluation['template']->id !== (int) $notification->whatsapp_template_finalidade_id
            || (int) $evaluation['connection']->entidade_id !== (int) $notification->entidade_id) {
            $this->finish(
                $notification,
                WhatsAppNotificationStatus::Suppressed,
                'A conta ou o template vinculado à notificação não está mais ativo para a organização.',
                $quotas,
            );

            return;
        }

        if (in_array($notification->status, [
            WhatsAppNotificationStatus::Processing,
            WhatsAppNotificationStatus::Reconciling,
        ], true)) {
            $remote = $gateway->message((string) $notification->client_request_id);
            if ($remote !== null) {
                $this->applyGatewayStatus(
                    $notification,
                    (string) ($remote['status'] ?? $remote['internal_status'] ?? 'SUBMITTED'),
                    $quotas,
                );

                return;
            }
            if ($notification->status === WhatsAppNotificationStatus::Processing) {
                $notification->forceFill([
                    'status' => WhatsAppNotificationStatus::Reconciling,
                    'proxima_tentativa_em' => now()->addSeconds(30),
                    'erro' => 'A tentativa interrompida será reconciliada antes de um novo envio.',
                ])->save();
                $this->release(30);

                return;
            }
        }

        $claimed = WhatsAppNotification::withoutGlobalScopes()
            ->whereKey($notification->id)
            ->whereIn('status', [
                WhatsAppNotificationStatus::Pending->value,
                WhatsAppNotificationStatus::Reconciling->value,
            ])
            ->update([
                'status' => WhatsAppNotificationStatus::Processing->value,
                'tentativas' => DB::raw('tentativas + 1'),
                'proxima_tentativa_em' => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return;
        }
        $notification->refresh();

        if (! $this->reserveQuota($notification, $quotas)) {
            return;
        }

        try {
            $response = $gateway->sendTemplate([
                'client_request_id' => (string) $notification->client_request_id,
                'purpose' => $notification->finalidade->value,
                'origin' => 'GOVNEXGAB',
                'phone' => (string) $notification->telefone_criptografado,
                'template_version_id' => (int) $evaluation['template']->gateway_template_id,
                'body_parameters' => (array) $notification->parametros_corpo_criptografados,
                'button_parameters' => (array) $notification->parametros_botoes_criptografados,
                'expires_at' => $notification->expira_em->toIso8601String(),
            ]);
            DB::transaction(function () use ($notification, $response): void {
                $locked = WhatsAppNotification::withoutGlobalScopes()->lockForUpdate()->findOrFail($notification->id);
                $locked->forceFill([
                    'status' => WhatsAppNotificationStatus::Submitted,
                    'gateway_status' => strtoupper((string) ($response['status'] ?? 'QUEUED')),
                    'submetido_em' => now(),
                    'consumo_registrado_em' => $locked->consumo_registrado_em ?? now(),
                    'erro' => null,
                ])->save();
            }, 3);
        } catch (WhatsAppGatewayException $exception) {
            if ($exception->ambiguous) {
                $notification->forceFill([
                    'status' => WhatsAppNotificationStatus::Reconciling,
                    'proxima_tentativa_em' => now()->addSeconds(30),
                    'erro' => 'A confirmação do gateway está pendente de reconciliação.',
                ])->save();
                $this->release(30);

                return;
            }
            if ($exception->retryable) {
                $notification->forceFill([
                    'status' => WhatsAppNotificationStatus::Pending,
                    'proxima_tentativa_em' => now()->addSeconds(30),
                    'erro' => 'Falha temporária na comunicação com o gateway.',
                ])->save();
                throw $exception;
            }

            $this->finish($notification, WhatsAppNotificationStatus::Failed, $exception->getMessage(), $quotas);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $notification = WhatsAppNotification::withoutGlobalScopes()->find($this->notificationId);
        if ($notification && ! $notification->status->isTerminal()) {
            $this->finish(
                $notification,
                WhatsAppNotificationStatus::Failed,
                'As tentativas de entrega ao gateway foram esgotadas.',
                app(EntidadeQuotaService::class),
            );
        }
    }

    private function reserveQuota(WhatsAppNotification $notification, EntidadeQuotaService $quotas): bool
    {
        try {
            DB::transaction(function () use ($notification, $quotas): void {
                $locked = WhatsAppNotification::withoutGlobalScopes()->lockForUpdate()->findOrFail($notification->id);
                if ($locked->consumo_reservado_em !== null) {
                    return;
                }
                if ($locked->entidade_id === null) {
                    throw ValidationException::withMessages(['entidade' => 'A notificação não pertence a uma organização.']);
                }
                $quotas->reserve((int) $locked->entidade_id, EntidadeQuota::MonthlyWhatsAppMessages);
                $locked->forceFill(['consumo_reservado_em' => now()])->save();
            }, 3);

            $notification->refresh();

            return true;
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();
            $this->finish(
                $notification,
                WhatsAppNotificationStatus::Suppressed,
                $message !== '' ? $message : 'A cota mensal de WhatsApp foi atingida.',
                $quotas,
            );

            return false;
        }
    }

    private function applyGatewayStatus(
        WhatsAppNotification $notification,
        string $status,
        EntidadeQuotaService $quotas,
    ): void {
        $normalizedStatus = strtoupper(trim($status));
        $mapped = match ($normalizedStatus) {
            'READ' => WhatsAppNotificationStatus::Read,
            'DELIVERED' => WhatsAppNotificationStatus::Delivered,
            'SENT' => WhatsAppNotificationStatus::Sent,
            'FAILED' => WhatsAppNotificationStatus::Failed,
            'EXPIRED' => WhatsAppNotificationStatus::Expired,
            'QUEUED', 'SUBMITTED', 'PROCESSING', 'PENDING' => WhatsAppNotificationStatus::Submitted,
            default => WhatsAppNotificationStatus::Reconciling,
        };
        $attributes = [
            'status' => $mapped,
            'gateway_status' => $normalizedStatus,
            'erro' => $mapped === WhatsAppNotificationStatus::Failed ? 'O gateway informou falha na entrega.' : null,
        ];
        $timestampField = match ($mapped) {
            WhatsAppNotificationStatus::Read => 'lido_em',
            WhatsAppNotificationStatus::Delivered => 'entregue_em',
            WhatsAppNotificationStatus::Sent => 'enviado_em',
            WhatsAppNotificationStatus::Failed => 'falhou_em',
            WhatsAppNotificationStatus::Reconciling => 'proxima_tentativa_em',
            default => 'submetido_em',
        };
        $attributes[$timestampField] = $mapped === WhatsAppNotificationStatus::Reconciling
            ? now()->addSeconds(30)
            : now();
        if ($mapped === WhatsAppNotificationStatus::Reconciling) {
            $attributes['erro'] = 'O gateway retornou um estado ainda não reconhecido; a entrega será reconciliada.';
        }
        if (in_array($mapped, [
            WhatsAppNotificationStatus::Submitted,
            WhatsAppNotificationStatus::Sent,
            WhatsAppNotificationStatus::Delivered,
            WhatsAppNotificationStatus::Read,
        ], true)) {
            $attributes['consumo_registrado_em'] = $notification->consumo_registrado_em ?? now();
        }
        $notification->forceFill($attributes)->save();

        if (in_array($mapped, [WhatsAppNotificationStatus::Failed, WhatsAppNotificationStatus::Expired], true)
            && $notification->consumo_registrado_em === null) {
            $this->releaseQuotaReservation($notification, $quotas);
        }
        if ($mapped === WhatsAppNotificationStatus::Reconciling) {
            $this->release(30);
        }
    }

    private function finish(
        WhatsAppNotification $notification,
        WhatsAppNotificationStatus $status,
        string $error,
        EntidadeQuotaService $quotas,
    ): void {
        DB::transaction(function () use ($notification, $status, $error, $quotas): void {
            $locked = WhatsAppNotification::withoutGlobalScopes()->lockForUpdate()->find($notification->id);
            if ($locked === null) {
                return;
            }
            if ($locked->consumo_reservado_em !== null
                && $locked->consumo_registrado_em === null
                && $locked->entidade_id !== null) {
                $quotas->release((int) $locked->entidade_id, EntidadeQuota::MonthlyWhatsAppMessages);
                $locked->consumo_reservado_em = null;
            }
            $locked->forceFill([
                'status' => $status,
                'erro' => mb_substr($error, 0, 500),
                'falhou_em' => $status === WhatsAppNotificationStatus::Failed ? now() : $locked->falhou_em,
                'proxima_tentativa_em' => null,
            ])->save();
        }, 3);
    }

    private function releaseQuotaReservation(
        WhatsAppNotification $notification,
        EntidadeQuotaService $quotas,
    ): void {
        DB::transaction(function () use ($notification, $quotas): void {
            $locked = WhatsAppNotification::withoutGlobalScopes()->lockForUpdate()->find($notification->id);
            if ($locked === null
                || $locked->consumo_reservado_em === null
                || $locked->consumo_registrado_em !== null
                || $locked->entidade_id === null) {
                return;
            }
            $quotas->release((int) $locked->entidade_id, EntidadeQuota::MonthlyWhatsAppMessages);
            $locked->forceFill(['consumo_reservado_em' => null])->save();
        }, 3);
    }
}
