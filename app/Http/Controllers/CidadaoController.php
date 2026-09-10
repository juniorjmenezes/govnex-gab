<?php

namespace App\Http\Controllers;

use App\Enums\DemandStatus;
use App\Enums\UserRole;
use App\Http\Requests\Citizens\StoreCitizenRequest;
use App\Http\Requests\Citizens\UpdateCitizenRequest;
use App\Models\Bairro;
use App\Models\Cidadao;
use App\Services\Citizens\CitizenDuplicateFinder;
use App\Services\WhatsApp\WhatsAppContactService;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CidadaoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Cidadao::class);
        $search = trim((string) $request->string('q'));

        return Inertia::render('citizens/index', [
            'filters' => ['q' => $search],
            'canDelete' => in_array($request->user()->role, [UserRole::Councilor, UserRole::ChiefOfStaff], true),
            'citizens' => Cidadao::query()
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
        $this->authorize('create', Cidadao::class);

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
        $citizen = DB::transaction(function () use ($validated, $whatsappConsent, $request, $whatsappContacts): Cidadao {
            $citizen = Cidadao::query()->create($validated);
            if ($whatsappConsent) {
                $whatsappContacts->declareForCitizen(
                    $citizen,
                    (string) $citizen->whatsapp,
                    $request->user(),
                );
            }

            return $citizen;
        });

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

    public function show(Cidadao $cidadao): Response
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
                'recent' => $cidadao->atendimentos()
                    ->select([
                        'id',
                        'cidadao_id',
                        'atendente_id',
                        'assunto',
                        'atendido_em',
                        'requer_retorno',
                        'retorno_previsto_em',
                    ])
                    ->with('atendente:id,name')
                    ->latest('atendido_em')
                    ->limit(5)
                    ->get(),
            ],
        ]);
    }

    public function edit(Request $request, Cidadao $cidadao): Response
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
        Cidadao $cidadao,
        CitizenDuplicateFinder $duplicates,
        WhatsAppContactService $whatsappContacts,
    ): RedirectResponse {
        $validated = $request->validated();
        $whatsappConsent = (bool) $validated['whatsapp_consentimento_operacional'];
        unset($validated['whatsapp_consentimento_operacional']);
        $validated = $this->normalizeLocation($validated);
        $matches = $duplicates->find($validated, $cidadao);
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

        if ($matches->isNotEmpty()) {
            session()->flash('possible_duplicates', $matches->all());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cadastro atualizado com sucesso.']);

        return to_route('citizens.show', $cidadao);
    }

    /** @return array{estado: string, municipio: string} */
    private function officeLocation(Request $request): array
    {
        $office = $request->user()->gabinete()->firstOrFail();

        return $office->only(['estado', 'municipio']);
    }

    /** @return Collection<int, Bairro> */
    private function neighborhoodOptions(): Collection
    {
        return Bairro::query()
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

    public function destroy(Cidadao $cidadao): RedirectResponse
    {
        $this->authorize('delete', $cidadao);
        $cidadao->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cidadão excluído.']);

        return to_route('citizens.index');
    }
}
