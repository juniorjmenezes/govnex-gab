<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppPurpose;
use App\Models\CompromissoInstitucional;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;
use App\Tenancy\OfficeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Convocação por WhatsApp de um compromisso institucional: a Secretaria
 * legislativa avisa a equipe dos gabinetes de vereador que o compromisso
 * alcança.
 *
 * É uma travessia deliberada do isolamento entre gabinetes, no mesmo sentido
 * de `CompromissoInstitucional`: o compromisso é da Câmara, e o público dele
 * são gabinetes de outros contextos. Por isso os contatos são lidos sem o
 * escopo global de `WhatsAppContact` — mas só dentro do público do próprio
 * compromisso (`audienceOffices()`, que já se limita à entidade dele), nunca
 * por uma lista vinda da requisição.
 *
 * A Secretaria não contorna as regras de cada destinatário: cada contato
 * passa pela elegibilidade normal do gabinete DELE (módulo WhatsApp e Agenda
 * ligados, finalidade habilitada, consentimento vigente, cota da entidade).
 * Quem não passa é ignorado em silêncio e entra só na contagem.
 *
 * Só contatos de equipe (`usuario_id`): cidadãos cadastrados nos gabinetes não
 * recebem convocação da Câmara. A pessoa precisa estar ativa e ainda ligada
 * ao gabinete do contato.
 *
 * Repetir a convocação não duplica mensagens: a chave do evento é o
 * compromisso mais o horário marcado, e a outbox deduplica por contato. Se o
 * horário mudar, a nova convocação sai de novo, com a data nova.
 */
final class InstitutionalWhatsAppBroadcastService
{
    public function __construct(
        private readonly WhatsAppOutboxService $outbox,
        private readonly OfficeContext $context,
    ) {}

    /**
     * `offices` é o público inteiro; `reached_offices`, os gabinetes em que
     * ao menos uma mensagem nova entrou na fila.
     *
     * @return array{offices:int, reached_offices:int, contacts:int, queued:int, already_sent:int, skipped:int}
     */
    public function broadcast(CompromissoInstitucional $compromisso, User $actor): array
    {
        $offices = $compromisso->audienceOffices()->keyBy('id');
        $officeIds = array_values($offices->map(fn (Office $office): int => $office->id)->all());
        $summary = ['offices' => count($officeIds), 'reached_offices' => 0, 'contacts' => 0, 'queued' => 0, 'already_sent' => 0, 'skipped' => 0];
        $reached = [];

        if ($officeIds === []) {
            return $summary;
        }

        $contacts = WhatsAppContact::withoutGlobalScopes()
            ->whereIn('gabinete_id', $officeIds)
            ->whereNotNull('usuario_id')
            ->whereNull('cidadao_id')
            ->with('user')
            ->orderBy('gabinete_id')
            ->orderBy('id')
            ->get();
        $inactiveOverrides = OfficeMember::inactiveOverrides($officeIds);
        $start = CarbonImmutable::instance($compromisso->inicio_em);

        foreach ($contacts as $contact) {
            $user = $contact->user;
            $office = $offices->get($contact->gabinete_id);
            if (! $office instanceof Office || ! $user instanceof User || $user->trashed() || ! $user->is_active
                || ! $this->belongsTo($user, $office->id, $inactiveOverrides)) {
                continue;
            }

            $summary['contacts']++;
            $result = $this->enqueue($compromisso, $contact, $user, $office, $start);
            $summary[$result]++;
            if ($result === 'queued') {
                $reached[(int) $contact->gabinete_id] = true;
            }
        }
        $summary['reached_offices'] = count($reached);

        Log::info('Convocação institucional por WhatsApp registrada.', [
            'compromisso_institucional_id' => $compromisso->id,
            'actor_id' => $actor->id,
            ...$summary,
        ]);

        return $summary;
    }

    /** @return 'queued'|'already_sent'|'skipped' */
    private function enqueue(
        CompromissoInstitucional $compromisso,
        WhatsAppContact $contact,
        User $user,
        Office $office,
        CarbonImmutable $start,
    ): string {
        $local = $start->setTimezone($office->timezone ?? 'America/Sao_Paulo');
        $when = $compromisso->dia_inteiro
            ? $local->format('d/m/Y').', dia inteiro'
            : $local->format('d/m/Y').' às '.$local->format('H:i');

        try {
            // No contexto do gabinete do destinatário: na requisição da
            // Secretaria, o escopo global esconderia o consentimento do
            // contato e a criação gravaria a notificação com o `gabinete_id`
            // da Secretaria.
            $notification = $this->context->runAs($office, fn (): ?WhatsAppNotification => $this->outbox->enqueue(
                $contact,
                WhatsAppPurpose::InstitutionalConvocation,
                // Estável entre repetições (nada de `now()`): só muda se o
                // compromisso for remarcado.
                "institutional-convocation:{$compromisso->id}:{$start->getTimestamp()}:{$contact->gabinete_id}",
                [
                    $user->name,
                    $compromisso->titulo,
                    $when,
                    trim((string) $compromisso->local) !== '' ? (string) $compromisso->local : 'A confirmar',
                ],
                origin: $compromisso,
            ));
        } catch (Throwable $exception) {
            Log::warning('Falha ao registrar convocação institucional na outbox.', [
                'compromisso_institucional_id' => $compromisso->id,
                'contact_id' => $contact->id,
                'exception' => $exception::class,
            ]);

            return 'skipped';
        }

        if ($notification === null) {
            return 'skipped';
        }

        return $notification->wasRecentlyCreated ? 'queued' : 'already_sent';
    }

    /**
     * `users.gabinete_id`/`is_active` continuam a fonte para quem nunca
     * passou pelo Time/convite/Hub (sem linha em `gabinete_membros`); quem
     * tem vínculo EXPLICITAMENTE pausado nesse gabinete não entra, mesmo que
     * `users.gabinete_id` ainda aponte para ele.
     *
     * @param  array<int, array<int, true>>  $inactiveOverrides
     */
    private function belongsTo(User $user, int $officeId, array $inactiveOverrides): bool
    {
        return (int) $user->gabinete_id === $officeId
            && $user->is_active
            && ! isset($inactiveOverrides[$officeId][$user->id]);
    }
}
