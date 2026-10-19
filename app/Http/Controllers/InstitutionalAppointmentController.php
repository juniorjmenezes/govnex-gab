<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\CommitteeStatus;
use App\Enums\EntidadeModule;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Http\Requests\Appointments\InstitutionalAppointmentRequest;
use App\Models\ComissaoLegislativa;
use App\Models\CompromissoInstitucional;
use App\Models\Gabinete;
use App\Models\User;
use App\Services\Appointments\InstitutionalAudienceService;
use App\Services\Modules\EntidadeModuleManager;
use App\Services\WhatsApp\InstitutionalWhatsAppBroadcastService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Escrita da agenda institucional pela Secretaria legislativa. A leitura fica
 * na agenda de cada gabinete (`AppointmentController::index`), que mescla
 * estes compromissos aos do próprio gabinete.
 */
class InstitutionalAppointmentController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorize('create', CompromissoInstitucional::class);
        $user = $this->user($request);
        $timezone = $this->timezone($user);
        $today = CarbonImmutable::now($timezone)->toDateString();
        $date = $this->date($request->string('data')->toString(), $today);

        return Inertia::render('appointments/institutional-form', [
            'appointment' => null,
            'today' => $today,
            'timezone' => $timezone,
            'defaults' => ['date' => max($date, $today)],
            'options' => $this->formOptions($user),
            'broadcast' => null,
        ]);
    }

    public function store(InstitutionalAppointmentRequest $request, InstitutionalAudienceService $audience): RedirectResponse
    {
        $this->authorize('create', CompromissoInstitucional::class);
        $user = $this->user($request);
        $gabinete = $user->gabinete;
        abort_unless($gabinete instanceof Gabinete, 403);

        $compromisso = DB::transaction(function () use ($request, $user, $gabinete, $audience): CompromissoInstitucional {
            $compromisso = new CompromissoInstitucional($request->appointmentData());
            $compromisso->forceFill([
                'entidade_id' => $gabinete->entidade_id,
                'criado_por_gabinete_id' => $gabinete->id,
                'criado_por_id' => $user->id,
            ])->save();
            $audience->apply($compromisso, $request->committeeId(), $request->targets());

            return $compromisso;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Compromisso institucional publicado.']);

        return $this->toAgenda($compromisso);
    }

    public function edit(
        Request $request,
        CompromissoInstitucional $compromisso,
        EntidadeModuleManager $entidadeModules,
    ): Response {
        $this->authorize('update', $compromisso);
        $user = $this->user($request);
        $timezone = $this->timezone($user);
        $start = CarbonImmutable::instance($compromisso->inicio_em)->setTimezone($timezone);
        $end = CarbonImmutable::instance($compromisso->fim_em)->setTimezone($timezone);
        $today = CarbonImmutable::now($timezone)->toDateString();

        return Inertia::render('appointments/institutional-form', [
            'appointment' => [
                'id' => $compromisso->id,
                'titulo' => $compromisso->titulo,
                'descricao' => $compromisso->descricao,
                // Já no fuso do gabinete, como o formulário envia de volta.
                'inicio_em' => $start->format('Y-m-d\TH:i'),
                'fim_em' => $end->format('Y-m-d\TH:i'),
                'dia_inteiro' => $compromisso->dia_inteiro,
                'local' => $compromisso->local,
                'tipo' => $compromisso->tipo,
                'status' => $compromisso->status->value,
                // Comissão excluída sai da relação: o formulário abre na
                // lista manual, já com o público que foi gravado.
                'comissao_id' => $compromisso->comissao?->id,
                'gabinetes' => $compromisso->gabinetesAlvo()->pluck('gabinetes.id')->map(fn ($id): int => (int) $id)->all(),
                'is_past' => $start->toDateString() < $today,
            ],
            'today' => $today,
            'timezone' => $timezone,
            'defaults' => ['date' => $start->toDateString()],
            'options' => $this->formOptions($user, $compromisso->comissao_id),
            // Convocação por WhatsApp: só com o canal ligado na Câmara e para
            // compromisso que ainda vai acontecer. `offices` é o público atual.
            'broadcast' => [
                'available' => $user->can('broadcastWhatsApp', $compromisso)
                    && $this->convocable($compromisso)
                    && $entidadeModules->isActive($compromisso->entidade_id, EntidadeModule::WhatsApp),
                'offices' => $compromisso->audienceOffices()->count(),
            ],
        ]);
    }

    public function update(
        InstitutionalAppointmentRequest $request,
        CompromissoInstitucional $compromisso,
        InstitutionalAudienceService $audience,
    ): RedirectResponse {
        $this->authorize('update', $compromisso);

        DB::transaction(function () use ($request, $compromisso, $audience): void {
            $compromisso->update($request->appointmentData());
            $audience->apply($compromisso, $request->committeeId(), $request->targets());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Compromisso institucional atualizado.']);

        return $this->toAgenda($compromisso);
    }

    /**
     * Convoca por WhatsApp a equipe dos gabinetes do público do compromisso.
     * O público é o já gravado no compromisso (o mesmo de quem o vê na
     * agenda), nunca uma lista enviada na requisição.
     */
    public function broadcast(
        Request $request,
        CompromissoInstitucional $compromisso,
        InstitutionalWhatsAppBroadcastService $broadcasts,
    ): RedirectResponse {
        $this->authorize('broadcastWhatsApp', $compromisso);

        if (! $this->convocable($compromisso)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Compromisso cancelado ou já encerrado não recebe convocação.',
            ]);

            return back();
        }

        $summary = $broadcasts->broadcast($compromisso, $this->user($request));
        Inertia::flash('toast', $this->broadcastToast($summary));

        return back();
    }

    public function destroy(CompromissoInstitucional $compromisso): RedirectResponse
    {
        $this->authorize('delete', $compromisso);
        $compromisso->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Compromisso institucional excluído.']);

        return to_route('appointments.index');
    }

    /**
     * Comissões oferecidas: as ativas da Câmara e, na edição, a já vinculada
     * mesmo que extinta (para o seletor mostrar o nome). `offices` é a
     * composição atual — a prévia de quem seria convocado ao vincular.
     *
     * @return array<string, mixed>
     */
    private function formOptions(User $user, ?int $linkedCommitteeId = null): array
    {
        $entidadeId = (int) $user->gabinete?->entidade_id;
        $committees = ComissaoLegislativa::query()
            ->ofEntidade($entidadeId)
            ->where(fn ($query) => $query
                ->where('status', CommitteeStatus::Active->value)
                ->when($linkedCommitteeId !== null, fn ($linked) => $linked->orWhereKey($linkedCommitteeId)))
            ->orderBy('nome')
            ->limit(500)
            ->get(['id', 'nome', 'sigla', 'status']);
        $members = app(InstitutionalAudienceService::class)
            ->activeMemberOfficesByCommittee(
                $committees->map(fn (ComissaoLegislativa $comissao): int => $comissao->id)->all(),
                $entidadeId,
            );

        return [
            'statuses' => array_map(fn (AppointmentStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ], AppointmentStatus::cases()),
            'committees' => $committees
                ->map(fn (ComissaoLegislativa $comissao): array => [
                    'id' => $comissao->id,
                    'name' => $comissao->sigla ? "{$comissao->nome} ({$comissao->sigla})" : $comissao->nome,
                    'is_active' => $comissao->isActive(),
                    'offices' => $members[$comissao->id] ?? [],
                ])
                ->values()
                ->all(),
            // Fora do escopo global de `Gabinete`, que no contexto da
            // Secretaria só enxerga ela mesma; o recorte é a entidade dela.
            'offices' => Gabinete::withoutGlobalScopes()
                ->where('entidade_id', $user->gabinete?->entidade_id)
                ->where('tipo_gabinete', GabineteType::CouncilorOffice)
                ->where('status', GabineteStatus::Active)
                ->orderBy('nome')
                ->get(['id', 'nome', 'vereador_nome'])
                ->map(fn (Gabinete $office): array => [
                    'id' => $office->id,
                    'name' => $office->nome,
                    'leader' => $office->vereador_nome,
                ])
                ->values()
                ->all(),
        ];
    }

    /** Convocação só faz sentido para o que ainda vai acontecer. */
    private function convocable(CompromissoInstitucional $compromisso): bool
    {
        return $compromisso->status !== AppointmentStatus::Cancelled
            && $compromisso->fim_em->isFuture();
    }

    /**
     * @param  array{offices:int, reached_offices:int, contacts:int, queued:int, already_sent:int, skipped:int}  $summary
     * @return array{type:string, message:string}
     */
    private function broadcastToast(array $summary): array
    {
        $plural = fn (int $count, string $one, string $many): string => $count.' '.($count === 1 ? $one : $many);

        if ($summary['queued'] === 0) {
            return [
                'type' => 'info',
                'message' => $summary['already_sent'] > 0
                    ? 'Nenhuma convocação nova: '.$plural($summary['already_sent'], 'pessoa já tinha', 'pessoas já tinham').' recebido esta convocação.'
                    : 'Nenhuma convocação enviada: ninguém nos gabinetes convocados está habilitado a receber por WhatsApp.',
            ];
        }

        $message = 'Convocação enviada a '.$plural($summary['queued'], 'pessoa', 'pessoas')
            .' em '.$plural($summary['reached_offices'], 'gabinete', 'gabinetes').'.';
        if ($summary['skipped'] > 0) {
            $message .= ' '.$plural($summary['skipped'], 'contato ficou', 'contatos ficaram').' de fora por consentimento ou configuração do gabinete.';
        }

        return ['type' => 'success', 'message' => $message];
    }

    private function toAgenda(CompromissoInstitucional $compromisso): RedirectResponse
    {
        return to_route('appointments.index', [
            'view' => 'dia',
            'date' => CarbonImmutable::instance($compromisso->inicio_em)
                ->setTimezone($this->timezone($compromisso->criadoPorGabinete))
                ->toDateString(),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->gabinete_id !== null, 403);

        return $user;
    }

    private function timezone(User|Gabinete|null $owner): string
    {
        $gabinete = $owner instanceof User ? $owner->gabinete : $owner;

        return $gabinete->timezone ?? 'America/Sao_Paulo';
    }

    private function date(string $value, string $fallback): string
    {
        try {
            return $value !== '' ? CarbonImmutable::parse($value)->toDateString() : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
