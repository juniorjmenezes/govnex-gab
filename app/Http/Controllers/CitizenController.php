<?php

namespace App\Http\Controllers;

use App\Enums\DemandStatus;
use App\Http\Requests\Citizens\StoreCitizenRequest;
use App\Http\Requests\Citizens\UpdateCitizenRequest;
use App\Models\Citizen;
use App\Models\Neighborhood;
use App\Services\Citizens\CitizenDuplicateFinder;
use App\Services\WhatsApp\WhatsAppContactService;
use App\Support\PerPage;
use App\Tenancy\OfficeContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class CitizenController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Citizen::class);
        $search = trim((string) $request->string('q'));

        return Inertia::render('citizens/index', [
            'filters' => ['q' => $search],
            'canDelete' => app(OfficeContext::class)->roleFor($request->user())->isAdministrator(),
            'citizens' => Citizen::query()
                ->select(['id', 'nome', 'telefone', 'whatsapp', 'email', 'bairro_id', 'consentimento_contato', 'eleitor', 'cadastrado_em'])
                ->with('bairro:id,nome')
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('nome', 'like', "%{$search}%")
                        ->orWhere('telefone', 'like', "%{$search}%")
                        ->orWhere('whatsapp', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                }))
                ->latest('cadastrado_em')
                ->paginate(PerPage::resolve($request, 15))
                ->withQueryString(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Citizen::class);

        return Inertia::render('citizens/create', [
            'neighborhoods' => $this->neighborhoodOptions(),
            'officeLocation' => $this->officeLocation($request),
            'whatsappConsentText' => (string) config('whatsapp.consent.text'),
        ]);
    }

    public function store(
        StoreCitizenRequest $request,
        CitizenDuplicateFinder $duplicates,
        WhatsAppContactService $whatsappContacts,
    ): RedirectResponse {
        $validated = $request->validated();
        $whatsappConsent = (bool) $validated['whatsapp_consentimento_operacional'];
        unset($validated['whatsapp_consentimento_operacional']);
        $validated = $this->normalizeLocation($validated);
        $matches = $duplicates->find($validated);

        try {
            $citizen = DB::transaction(function () use ($validated, $whatsappConsent, $request, $whatsappContacts): Citizen {
                $citizen = Citizen::query()->create($validated);
                if ($whatsappConsent) {
                    $whatsappContacts->declareForCitizen(
                        $citizen,
                        (string) $citizen->whatsapp,
                        $request->user(),
                    );
                }

                return $citizen;
            });
        } catch (InvalidArgumentException|RuntimeException $exception) {
            // Telefone mal formado ou segredo de hash do WhatsApp não
            // configurado: mensagem já pronta para exibir, nunca uma
            // exceção não tratada — o cadastro inteiro volta (a pessoa não
            // nasce sem o consentimento que pediu explicitamente).
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back()->withInput();
        }

        if ($matches->isNotEmpty()) {
            session()->flash('possible_duplicates', $matches->all());
        }

        Inertia::flash('toast', [
            'type' => $matches->isEmpty() ? 'success' : 'warning',
            'message' => $matches->isEmpty()
                ? 'Cidadão cadastrado com sucesso.'
                : 'Cadastro concluído. Encontramos possíveis registros semelhantes.',
        ]);

        return to_route('citizens.show', $citizen);
    }

    public function show(Citizen $cidadao): Response
    {
        $this->authorize('view', $cidadao);
        $cidadao->load('bairro:id,nome,municipio,estado');

        return Inertia::render('citizens/show', [
            'citizen' => $cidadao,
            'possibleDuplicates' => session('possible_duplicates', []),
            'serviceSummary' => [
                'total' => $cidadao->demandas()->count(),
                'open' => $cidadao->demandas()->whereIn('status', DemandStatus::openValues())->count(),
                'completed' => $cidadao->demandas()->whereIn('status', DemandStatus::completedValues())->count(),
                'last_interaction' => $cidadao->demandas()->max('updated_at'),
            ],
            'attendanceSummary' => [
                'total' => $cidadao->atendimentos()->count(),
                'last_interaction' => $cidadao->atendimentos()->max('atendido_em'),
                'lastVisitedAt' => $cidadao->atendimentos()
                    ->where('visita_domiciliar', true)
                    ->max('atendido_em'),
                'recent' => $cidadao->atendimentos()
                    ->select([
                        'id',
                        'cidadao_id',
                        'atendente_id',
                        'assunto',
                        'atendido_em',
                        'requer_retorno',
                        'retorno_previsto_em',
                        'visita_domiciliar',
                        'compromisso_id',
                    ])
                    ->with('atendente:id,name')
                    ->latest('atendido_em')
                    ->limit(5)
                    ->get(),
            ],
        ]);
    }

    public function edit(Request $request, Citizen $cidadao): Response
    {
        $this->authorize('update', $cidadao);
        $contact = $cidadao->whatsappContact()->first();

        return Inertia::render('citizens/edit', [
            'citizen' => [
                ...$cidadao->withoutRelations()->toArray(),
                // O bairro acompanha o cadastro porque cidadãos antigos, de
                // antes das colunas próprias de estado/município, só têm essa
                // referência para preencher a localização no formulário.
                'bairro' => $cidadao->bairro?->only(['id', 'nome', 'municipio', 'estado']),
                'whatsapp_consentimento_operacional' => $contact?->isEligible() ?? false,
            ],
            'neighborhoods' => $this->neighborhoodOptions(),
            'officeLocation' => $this->officeLocation($request),
            'whatsappConsentText' => (string) config('whatsapp.consent.text'),
        ]);
    }

    public function update(
        UpdateCitizenRequest $request,
        Citizen $cidadao,
        CitizenDuplicateFinder $duplicates,
        WhatsAppContactService $whatsappContacts,
    ): RedirectResponse {
        $validated = $request->validated();
        $whatsappConsent = (bool) $validated['whatsapp_consentimento_operacional'];
        unset($validated['whatsapp_consentimento_operacional']);
        $validated = $this->normalizeLocation($validated);
        $matches = $duplicates->find($validated, $cidadao);

        try {
            DB::transaction(function () use ($cidadao, $validated, $whatsappConsent, $request, $whatsappContacts): void {
                $cidadao->update($validated);
                $contact = $cidadao->whatsappContact()->first();
                if ($whatsappConsent) {
                    $whatsappContacts->declareForCitizen(
                        $cidadao,
                        (string) $cidadao->whatsapp,
                        $request->user(),
                    );
                } elseif ($contact && $contact->isEligible()) {
                    $whatsappContacts->revoke($contact, $request->user(), 'CITIZEN_FORM');
                }
            });
        } catch (InvalidArgumentException|RuntimeException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back()->withInput();
        }

        if ($matches->isNotEmpty()) {
            session()->flash('possible_duplicates', $matches->all());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cadastro atualizado com sucesso.']);

        return to_route('citizens.show', $cidadao);
    }

    /** @return array{estado: string, municipio: string} */
    private function officeLocation(Request $request): array
    {
        $office = app(OfficeContext::class)->requireOfficeFor($request->user());

        return $office->only(['estado', 'municipio']);
    }

    /** @return Collection<int, Neighborhood> */
    private function neighborhoodOptions(): Collection
    {
        return Neighborhood::query()
            ->select(['id', 'nome', 'municipio', 'estado'])
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeLocation(array $data): array
    {
        if (! ($data['eleitor'] ?? false)
            || ($data['latitude'] ?? null) === null
            || ($data['longitude'] ?? null) === null) {
            $data['latitude'] = null;
            $data['longitude'] = null;
            $data['localizacao_origem'] = null;
        }

        return $data;
    }

    public function destroy(Citizen $cidadao): RedirectResponse
    {
        $this->authorize('delete', $cidadao);
        $cidadao->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cidadão excluído.']);

        return to_route('citizens.index');
    }
}
