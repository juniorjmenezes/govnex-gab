<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppNotificationStatus;
use App\Enums\WhatsAppPurpose;
use App\Jobs\SendWhatsAppNotification;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WhatsAppOutboxService
{
    public function __construct(private readonly WhatsAppEligibilityService $eligibility) {}

    /**
     * @param  list<string>  $bodyParameters
     * @param  list<array<string, mixed>>  $buttonParameters
     */
    public function enqueue(
        WhatsAppContact $contact,
        WhatsAppPurpose $purpose,
        string $eventKey,
        array $bodyParameters,
        array $buttonParameters = [],
        ?Model $origin = null,
    ): ?WhatsAppNotification {
        $eligibility = $this->eligibility->evaluate($contact, $purpose);
        if (! $eligibility['eligible'] || ! $eligibility['template'] || ! $eligibility['connection']) {
            return null;
        }
        $idempotencyKey = hash('sha256', implode('|', [
            'govnexgab-whatsapp-v2',
            $eligibility['connection']->id,
            $contact->gabinete_id,
            $contact->id,
            $purpose->value,
            $eventKey,
        ]));

        try {
            $notification = DB::transaction(function () use (
                $contact,
                $purpose,
                $idempotencyKey,
                $bodyParameters,
                $buttonParameters,
                $origin,
                $eligibility,
            ): WhatsAppNotification {
                $notification = new WhatsAppNotification;
                $notification->forceFill([
                    'gabinete_id' => $contact->gabinete_id,
                    'entidade_id' => $eligibility['connection']->entidade_id,
                    'entidade_whatsapp_conexao_id' => $eligibility['connection']->id,
                    'whatsapp_contato_id' => $contact->id,
                    'whatsapp_template_finalidade_id' => $eligibility['template']->id,
                    'client_request_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'finalidade' => $purpose,
                    'origem_type' => $origin?->getMorphClass(),
                    'origem_id' => $origin?->getKey(),
                    'telefone_criptografado' => $contact->telefone_criptografado,
                    'telefone_hash' => $contact->telefone_hash,
                    'telefone_final' => $contact->telefone_final,
                    'parametros_corpo_criptografados' => array_map('strval', $bodyParameters),
                    'parametros_botoes_criptografados' => $buttonParameters,
                    'status' => WhatsAppNotificationStatus::Pending,
                    'proxima_tentativa_em' => now(),
                    'expira_em' => now()->addHours($purpose->expirationHours()),
                    'expurgar_sensiveis_em' => now()->addDays((int) config('whatsapp.retention_days', 90)),
                ])->save();

                SendWhatsAppNotification::dispatch($notification->id)->onQueue('whatsapp')->afterCommit();

                return $notification;
            }, 3);
        } catch (QueryException $exception) {
            $notification = WhatsAppNotification::withoutGlobalScopes()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if (! $notification) {
                throw $exception;
            }
        }

        return $notification;
    }
}
