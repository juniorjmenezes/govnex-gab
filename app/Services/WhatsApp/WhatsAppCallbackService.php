<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppConsentAction;
use App\Enums\WhatsAppContactStatus;
use App\Enums\WhatsAppNotificationStatus;
use App\Models\WhatsAppCallbackEvent;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class WhatsAppCallbackService
{
    /** @param array<string, mixed> $payload
     * @return array{duplicate:bool}
     */
    public function process(array $payload): array
    {
        $eventId = (string) $payload['event_id'];
        $payloadHash = hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        try {
            $event = WhatsAppCallbackEvent::query()->create([
                'event_id' => $eventId,
                'client_request_id' => isset($payload['data']['client_request_id'])
                    ? (string) $payload['data']['client_request_id']
                    : null,
                'tipo' => (string) $payload['event_type'],
                'payload_hash' => $payloadHash,
                'status' => 'PROCESSING',
                'ocorrido_em' => $payload['occurred_at'] ?? now(),
            ]);
        } catch (QueryException) {
            $existing = WhatsAppCallbackEvent::query()->where('event_id', $eventId)->first();
            if (! $existing || ! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw new RuntimeException('O evento repetido possui conteúdo divergente.');
            }
            if ($existing->status !== 'ERROR') {
                return ['duplicate' => true];
            }
            $existing->forceFill(['status' => 'PROCESSING', 'erro' => null])->save();
            $event = $existing;
        }

        try {
            DB::transaction(function () use ($payload, $event): void {
                $data = (array) $payload['data'];
                match ((string) $payload['event_type']) {
                    'MESSAGE_STATUS' => $this->status($event, $data),
                    'CONTACT_OPTOUT' => $this->optOut($event, $data),
                    'INBOUND_MESSAGE' => $this->inbound($event, $data),
                    default => throw new RuntimeException('Tipo de callback não suportado.'),
                };
                $event->forceFill([
                    'status' => 'PROCESSED',
                    'processado_em' => now(),
                    'erro' => null,
                ])->save();
            }, 3);
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'ERROR',
                'processado_em' => now(),
                'erro' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();
            throw $exception;
        }

        return ['duplicate' => false];
    }

    /** @param array<string, mixed> $data */
    private function status(WhatsAppCallbackEvent $event, array $data): void
    {
        $requestId = trim((string) ($data['client_request_id'] ?? ''));
        $notification = WhatsAppNotification::withoutGlobalScopes()
            ->where('client_request_id', $requestId)
            ->lockForUpdate()
            ->first();
        if (! $notification) {
            return;
        }
        $status = strtoupper(trim((string) ($data['status'] ?? $data['external_status'] ?? '')));
        $incoming = match ($status) {
            'READ' => WhatsAppNotificationStatus::Read,
            'DELIVERED' => WhatsAppNotificationStatus::Delivered,
            'SENT' => WhatsAppNotificationStatus::Sent,
            'FAILED' => WhatsAppNotificationStatus::Failed,
            'EXPIRED' => WhatsAppNotificationStatus::Expired,
            default => throw new RuntimeException('O status de entrega informado pelo gateway não é suportado.'),
        };
        $current = $notification->status;
        if (in_array($incoming, [WhatsAppNotificationStatus::Failed, WhatsAppNotificationStatus::Expired], true)
            && $current->rank() >= WhatsAppNotificationStatus::Sent->rank()) {
            return;
        }
        if ($incoming->rank() <= $current->rank()) {
            return;
        }
        $timestamp = $event->ocorrido_em ?? now();
        $attributes = ['status' => $incoming, 'gateway_status' => $incoming->value, 'erro' => null];
        $attributes[match ($incoming) {
            WhatsAppNotificationStatus::Read => 'lido_em',
            WhatsAppNotificationStatus::Delivered => 'entregue_em',
            WhatsAppNotificationStatus::Sent => 'enviado_em',
            WhatsAppNotificationStatus::Failed => 'falhou_em',
            default => 'submetido_em',
        }] = $timestamp;
        if ($incoming === WhatsAppNotificationStatus::Failed) {
            $attributes['erro'] = 'A Meta informou falha na entrega da mensagem.';
        }
        $notification->forceFill($attributes)->save();
        $event->forceFill([
            'gabinete_id' => $notification->gabinete_id,
            'whatsapp_notificacao_id' => $notification->id,
        ])->save();
    }

    /** @param array<string, mixed> $data */
    private function optOut(WhatsAppCallbackEvent $event, array $data): void
    {
        $hashes = array_filter(array_merge(
            [(string) ($data['phone_hash'] ?? '')],
            array_map('strval', (array) ($data['phone_hash_variants'] ?? [])),
        ), static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/', $hash) === 1);
        if ($hashes === []) {
            throw new RuntimeException('O opt-out não possui hash de telefone válido.');
        }
        $contacts = WhatsAppContact::withoutGlobalScopes()
            ->whereIn('telefone_hash', array_unique($hashes))
            ->lockForUpdate()
            ->get();
        foreach ($contacts as $contact) {
            $contact->forceFill([
                'status' => WhatsAppContactStatus::Revoked,
                'revogado_em' => $event->ocorrido_em ?? now(),
                'piloto' => false,
            ])->save();
            $consent = new WhatsAppConsent;
            $consent->forceFill([
                'gabinete_id' => $contact->gabinete_id,
                'whatsapp_contato_id' => $contact->id,
                'registrado_por_id' => null,
                'acao' => WhatsAppConsentAction::Revoked,
                'versao' => (string) config('whatsapp.consent.version'),
                'texto_hash' => hash('sha256', (string) config('whatsapp.consent.text')),
                'origem' => 'INBOUND_OPTOUT',
                'ocorrido_em' => $event->ocorrido_em ?? now(),
            ])->save();
        }
        if ($contacts->count() === 1) {
            $event->forceFill(['gabinete_id' => $contacts->first()->gabinete_id])->save();
        }
    }

    /** @param array<string, mixed> $data */
    private function inbound(WhatsAppCallbackEvent $event, array $data): void
    {
        $hash = trim((string) ($data['phone_hash'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return;
        }
        $contact = WhatsAppContact::withoutGlobalScopes()->where('telefone_hash', $hash)->first();
        if ($contact) {
            $event->forceFill(['gabinete_id' => $contact->gabinete_id])->save();
        }
    }
}
