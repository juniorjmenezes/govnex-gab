<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppPurpose;
use App\Models\Appointment;
use App\Models\Citizen;
use App\Models\Demand;
use App\Models\ElectoralPoll;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Tenancy\OfficeContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WhatsAppEventNotificationService
{
    public function __construct(
        private readonly WhatsAppOutboxService $outbox,
        private readonly OfficeContext $context,
    ) {}

    public function demandAssigned(Demand $demand, User $recipient): void
    {
        $this->enqueueForUser(
            $recipient,
            WhatsAppPurpose::DemandAssigned,
            "demand:{$demand->id}:assigned:{$recipient->id}",
            [
                $recipient->name,
                $demand->protocolo,
                $demand->prazo?->format('d/m/Y') ?? 'Sem prazo definido',
            ],
            $demand,
        );
    }

    public function demandStatusChanged(Demand $demand): void
    {
        $eventKey = "demand:{$demand->id}:status:{$demand->status->value}:{$demand->updated_at?->timestamp}";
        if ($demand->responsavel_id) {
            $recipient = User::query()->find($demand->responsavel_id);
            if ($recipient) {
                $this->enqueueForUser($recipient, WhatsAppPurpose::DemandStatusChanged, $eventKey, [
                    $recipient->name,
                    $demand->protocolo,
                    $demand->status->label(),
                ], $demand);
            }
        }
        $citizen = $demand->cidadao()->first();
        if ($citizen) {
            $this->enqueueForCitizen($citizen, WhatsAppPurpose::DemandStatusChanged, $eventKey, [
                $citizen->nome,
                $demand->protocolo,
                $demand->status->label(),
            ], $demand);
        }
    }

    public function demandUpdateAdded(Demand $demand, User $author): void
    {
        if (! $demand->responsavel_id || $demand->responsavel_id === $author->id) {
            return;
        }
        $recipient = User::query()->find($demand->responsavel_id);
        if ($recipient) {
            $this->enqueueForUser(
                $recipient,
                WhatsAppPurpose::DemandObservationAdded,
                "demand:{$demand->id}:update:{$demand->updated_at?->timestamp}:{$author->id}",
                [$recipient->name, $demand->protocolo, $author->name],
                $demand,
            );
        }
    }

    public function appointmentChanged(Appointment $appointment, string $eventKey): void
    {
        $this->appointmentEvent($appointment, WhatsAppPurpose::AppointmentChanged, $eventKey);
    }

    public function appointmentCancelled(Appointment $appointment, string $eventKey): void
    {
        $this->appointmentEvent($appointment, WhatsAppPurpose::AppointmentCancelled, $eventKey);
    }

    public function politicalPollPublished(ElectoralPoll $poll, User $recipient): void
    {
        $title = trim(implode(' ', array_filter([
            $poll->instituto,
            $poll->municipio,
            (string) $poll->ano,
        ]))) ?: 'Pesquisa eleitoral';
        $coverage = trim((string) ($poll->abrangencia ?: $poll->municipio ?: $poll->uf));
        $this->enqueueForUser(
            $recipient,
            WhatsAppPurpose::PoliticalPollPublished,
            "political-poll:{$poll->external_id}:{$recipient->id}",
            [$recipient->name, $title, $coverage !== '' ? $coverage : 'Não informada'],
            $poll,
        );
    }

    /** @param list<string> $parameters */
    public function enqueueForUser(
        User $user,
        WhatsAppPurpose $purpose,
        string $eventKey,
        array $parameters,
        ?Model $origin = null,
    ): void {
        // `is_active` sozinho não reflete um membro pausado no próprio
        // gabinete (toggle do Time): esse estado só existe em
        // `gabinete_membros`, nunca em `users`.
        if (! $user->gabinete_id || ! OfficeMember::isActiveMember($user, (int) $user->gabinete_id)) {
            return;
        }
        $contact = WhatsAppContact::withoutGlobalScopes()
            ->where('gabinete_id', $user->gabinete_id)
            ->where('usuario_id', $user->id)
            ->first();
        $this->safeEnqueue($contact, $purpose, $eventKey, $parameters, $origin);
    }

    /** @param list<string> $parameters */
    public function enqueueForCitizen(
        Citizen $citizen,
        WhatsAppPurpose $purpose,
        string $eventKey,
        array $parameters,
        ?Model $origin = null,
    ): void {
        $contact = WhatsAppContact::withoutGlobalScopes()
            ->where('gabinete_id', $citizen->gabinete_id)
            ->where('cidadao_id', $citizen->id)
            ->first();
        $this->safeEnqueue($contact, $purpose, $eventKey, $parameters, $origin);
    }

    private function appointmentEvent(Appointment $appointment, WhatsAppPurpose $purpose, string $eventKey): void
    {
        $appointment->loadMissing(['gabinete', 'responsavel', 'participantes', 'cidadao']);
        $timezone = $appointment->gabinete->timezone ?? 'America/Fortaleza';
        $startsAt = CarbonImmutable::instance($appointment->inicio_em)->setTimezone($timezone);
        $team = collect([$appointment->responsavel])
            ->merge($appointment->participantes)
            ->filter()
            ->unique('id');
        foreach ($team as $recipient) {
            $this->enqueueForUser($recipient, $purpose, $eventKey, [
                $recipient->name,
                $startsAt->format('d/m/Y'),
                $startsAt->format('H:i'),
            ], $appointment);
        }
        if ($appointment->cidadao) {
            $this->enqueueForCitizen($appointment->cidadao, $purpose, $eventKey, [
                $appointment->cidadao->nome,
                $startsAt->format('d/m/Y'),
                $startsAt->format('H:i'),
            ], $appointment);
        }
    }

    /** @param list<string> $parameters */
    private function safeEnqueue(
        ?WhatsAppContact $contact,
        WhatsAppPurpose $purpose,
        string $eventKey,
        array $parameters,
        ?Model $origin,
    ): void {
        if (! $contact) {
            return;
        }
        // O contato pode ser de um gabinete diferente do contexto atual da
        // requisição (ex.: aviso de pesquisa eleitoral para favoritos de
        // várias câmaras). Sem isto, `BelongsToOffice::creating` grava a
        // notificação com o `gabinete_id` do contexto, não o do contato —
        // vazando o registro para o gabinete errado.
        $office = Office::query()->withoutGlobalScopes()->find($contact->gabinete_id);
        if (! $office) {
            Log::warning('Falha ao registrar notificação WhatsApp: gabinete do contato não encontrado.', [
                'purpose' => $purpose->value,
                'contact_id' => $contact->id,
            ]);

            return;
        }
        try {
            $this->context->runAs(
                $office,
                fn () => $this->outbox->enqueue($contact, $purpose, $eventKey, $parameters, origin: $origin),
            );
        } catch (Throwable $exception) {
            Log::warning('Falha ao registrar notificação WhatsApp na outbox.', [
                'purpose' => $purpose->value,
                'contact_id' => $contact->id,
                'exception' => $exception::class,
            ]);
        }
    }
}
