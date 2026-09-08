<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EntidadeWhatsAppConnectionType;
use App\Enums\GabineteModule;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppPurpose;
use App\Http\Controllers\Controller;
use App\Models\Entidade;
use App\Models\EntidadeWhatsAppConexao;
use App\Models\Gabinete;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppTemplatePurpose;
use App\Services\Modules\GabineteModuleManager;
use App\Services\WhatsApp\EntidadeWhatsAppConnectionService;
use App\Services\WhatsApp\WhatsAppConfigurationService;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class WhatsAppController extends Controller
{
    public function index(
        Request $request,
        WhatsAppConfigurationService $configurations,
        WhatsAppTemplateService $templates,
        GabineteModuleManager $modules,
        EntidadeWhatsAppConnectionService $connections,
    ): Response {
        $officeModels = Gabinete::withoutGlobalScopes()
            ->with('entidade:id,nome')
            ->orderBy('nome')
            ->get(['id', 'entidade_id', 'nome', 'status']);
        $office = $officeModels->firstWhere('id', $request->integer('gabinete_id')) ?? $officeModels->first();
        $entidade = $office?->entidade;
        $connection = $entidade ? $connections->activeFor($entidade) : null;
        $officeModules = $office ? $modules->activeFor($office) : [];
        $offices = $officeModels->map(fn (Gabinete $item): array => [
            'id' => $item->id,
            'entidade_id' => $item->entidade_id,
            'entidade_name' => $item->entidade?->nome,
            'nome' => $item->nome,
            'status' => $item->status->value,
            'whatsapp_enabled' => $modules->isActive($item, GabineteModule::WhatsApp),
        ]);
        $configuration = $office ? $configurations->forOffice((int) $office->id) : null;
        $contacts = $office
            ? WhatsAppContact::withoutGlobalScopes()
                ->where('gabinete_id', $office->id)
                ->with(['user:id,name', 'citizen:id,nome'])
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (WhatsAppContact $contact): array => [
                    'id' => $contact->id,
                    'kind' => $contact->usuario_id ? 'Equipe' : 'Cidadão',
                    'name' => $contact->usuario_id
                        ? $contact->user->name
                        : $contact->citizen->nome,
                    'last_four' => $contact->telefone_final,
                    'status' => $contact->status->value,
                    'pilot' => $contact->piloto,
                    'eligible' => $contact->isEligible(),
                ])
            : collect();
        $outbox = $office
            ? WhatsAppNotification::withoutGlobalScopes()
                ->where('gabinete_id', $office->id)
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (WhatsAppNotification $notification): array => [
                    'id' => $notification->id,
                    'request_id' => $notification->client_request_id,
                    'purpose' => $notification->finalidade->value,
                    'status' => $notification->status->value,
                    'last_four' => $notification->telefone_final,
                    'attempts' => $notification->tentativas,
                    'error' => $notification->erro,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
            : collect();
        $gatewayAccounts = (int) $request->session()->get('whatsapp_gateway_accounts_entidade_id')
            === (int) $entidade?->id
            ? (array) $request->session()->get('whatsapp_gateway_accounts', [])
            : [];

        return Inertia::render('admin/whatsapp/index', [
            'offices' => $offices,
            'selectedOfficeId' => $office?->id,
            'selectedEntidade' => $entidade ? [
                'id' => $entidade->id,
                'name' => $entidade->nome,
            ] : null,
            'connection' => $connection ? $this->presentConnection($connection) : null,
            'gatewayAccounts' => array_values($gatewayAccounts),
            'configuration' => $configuration ? [
                'mode' => $configuration->modo->value,
                'digest_time' => substr((string) $configuration->resumo_diario_em, 0, 5),
                'module_enabled' => in_array(GabineteModule::WhatsApp->value, $officeModules, true),
                'purposes' => array_values(array_intersect(
                    (array) $configuration->finalidades_habilitadas,
                    array_column(WhatsAppPurpose::operationalUtilityCases(), 'value'),
                )),
            ] : null,
            'purposeOptions' => collect(WhatsAppPurpose::operationalUtilityCases())->map(fn (WhatsAppPurpose $purpose): array => [
                'value' => $purpose->value,
                'label' => $purpose->label(),
                'meta_name' => $purpose->metaName(),
                'source_module' => $purpose->sourceModule()->value,
                'source_module_label' => $purpose->sourceModule()->label(),
                'available' => in_array($purpose->sourceModule()->value, $officeModules, true),
            ]),
            'modeOptions' => collect(WhatsAppMode::cases())->map(fn (WhatsAppMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->value,
            ]),
            'templates' => $connection
                ? collect($templates->catalog($connection))
                    ->filter(fn (WhatsAppTemplatePurpose $template): bool => $template->finalidade->isOperationalUtility())
                    ->map(fn (WhatsAppTemplatePurpose $template): array => [
                        'id' => $template->id,
                        'purpose' => $template->finalidade->value,
                        'name' => $template->nome_meta,
                        'status' => $template->status,
                        'active' => $template->ativo,
                        'gateway_id' => $template->gateway_template_id,
                        'components' => $template->componentes,
                    ])->values()
                : [],
            'contacts' => $contacts,
            'outbox' => $outbox,
            'gateway' => [
                'driver' => (string) config('whatsapp.driver'),
                'real_enabled' => (bool) config('whatsapp.real_enabled'),
                'url_configured' => trim((string) config('whatsapp.gateway_url')) !== '',
                'request_secret_configured' => strlen((string) config('whatsapp.request_secret')) >= 32,
                'callback_secret_configured' => strlen((string) config('whatsapp.callback_secret')) >= 32,
                'callback_url' => url('/api/integrations/whatsapp/callback'),
            ],
        ]);
    }

    public function consultAccounts(
        Request $request,
        Entidade $entidade,
        EntidadeWhatsAppConnectionService $connections,
    ): RedirectResponse {
        $request->session()->flash('whatsapp_gateway_accounts', $connections->availableGatewayAccounts());
        $request->session()->flash('whatsapp_gateway_accounts_entidade_id', $entidade->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contas do cliente GABINETE consultadas com segurança.']);

        return back();
    }

    public function assignConnection(
        Request $request,
        Entidade $entidade,
        EntidadeWhatsAppConnectionService $connections,
    ): RedirectResponse {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::enum(EntidadeWhatsAppConnectionType::class)],
        ]);
        $connections->assign(
            $entidade,
            (int) $validated['account_id'],
            EntidadeWhatsAppConnectionType::from($validated['type']),
            $request->user(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conta WhatsApp atribuída à organização. Os gabinetes permanecem em modo OFF.']);

        return back();
    }

    public function deactivateConnection(
        Request $request,
        Entidade $entidade,
        EntidadeWhatsAppConnectionService $connections,
    ): RedirectResponse {
        $connections->deactivate($entidade, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conexão desativada e novos envios bloqueados.']);

        return back();
    }

    public function updateConfiguration(
        Request $request,
        Gabinete $office,
        WhatsAppConfigurationService $configurations,
    ): RedirectResponse {
        $validated = $request->validate([
            'mode' => ['required', Rule::enum(WhatsAppMode::class)],
            'digest_time' => ['required', 'date_format:H:i'],
            'purposes' => ['array'],
            'purposes.*' => [Rule::enum(WhatsAppPurpose::class)],
        ]);
        $configurations->update(
            $office,
            WhatsAppMode::from($validated['mode']),
            $validated['digest_time'],
            $validated['purposes'] ?? [],
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configuração do WhatsApp atualizada.']);

        return back();
    }

    public function updatePilot(Request $request, WhatsAppContact $contact): RedirectResponse
    {
        $validated = $request->validate(['pilot' => ['required', 'boolean']]);
        if ((bool) $validated['pilot']) {
            abort_unless($contact->isEligible(), 422, 'O contato não possui consentimento vigente.');
            $configuration = app(WhatsAppConfigurationService::class)->forOffice($contact->gabinete_id);
            if ($configuration->modo === WhatsAppMode::Pilot) {
                $column = $contact->usuario_id ? 'usuario_id' : 'cidadao_id';
                $alreadySelected = WhatsAppContact::withoutGlobalScopes()
                    ->where('gabinete_id', $contact->gabinete_id)
                    ->whereKeyNot($contact->id)
                    ->whereNotNull($column)
                    ->where('piloto', true)
                    ->exists();
                abort_if($alreadySelected, 422, 'O piloto permite somente um contato por tipo.');
            }
        }
        $contact->forceFill(['piloto' => (bool) $validated['pilot']])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Participação no piloto atualizada.']);

        return back();
    }

    public function createTemplate(
        Request $request,
        WhatsAppTemplateService $templates,
        EntidadeWhatsAppConnectionService $connections,
    ): RedirectResponse {
        $validated = $request->validate([
            'entidade_id' => ['required', 'integer', 'exists:entidades,id'],
            'purpose' => ['required', Rule::enum(WhatsAppPurpose::class)],
            'body' => ['nullable', 'string', 'min:20', 'max:1024'],
        ]);
        $entidade = Entidade::query()->whereKey($validated['entidade_id'])->firstOrFail();
        $connection = $connections->activeFor($entidade);
        if ($connection === null) {
            throw ValidationException::withMessages(['entidade_id' => 'Vincule uma conta WhatsApp antes de criar templates.']);
        }
        $templates->createDraft(
            WhatsAppPurpose::from($validated['purpose']),
            $validated['body'] ?? null,
            $request->user()->id,
            $connection,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rascunho criado na conta vinculada.']);

        return back();
    }

    public function updateTemplate(
        Request $request,
        WhatsAppTemplatePurpose $template,
        WhatsAppTemplateService $templates,
    ): RedirectResponse {
        $this->assertEntidadeTemplate($template);
        $validated = $request->validate(['body' => ['required', 'string', 'min:20', 'max:1024']]);
        $templates->updateDraft($template, $validated['body'], $request->user()->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rascunho atualizado.']);

        return back();
    }

    public function createTemplateVersion(
        Request $request,
        WhatsAppTemplatePurpose $template,
        WhatsAppTemplateService $templates,
    ): RedirectResponse {
        $this->assertEntidadeTemplate($template);
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'min:20', 'max:1024'],
        ]);
        $templates->createReplacementDraft(
            $template,
            $validated['body'] ?? null,
            $request->user()->id,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nova versão criada na conta WhatsApp vinculada.']);

        return back();
    }

    public function submitTemplate(
        WhatsAppTemplatePurpose $template,
        WhatsAppTemplateService $templates,
    ): RedirectResponse {
        $this->assertEntidadeTemplate($template);
        $templates->submit($template);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template submetido para análise.']);

        return back();
    }

    public function syncTemplates(
        Request $request,
        WhatsAppTemplateService $templates,
        EntidadeWhatsAppConnectionService $connections,
    ): RedirectResponse {
        $validated = $request->validate([
            'entidade_id' => ['required', 'integer', 'exists:entidades,id'],
        ]);
        $entidade = Entidade::query()->whereKey($validated['entidade_id'])->firstOrFail();
        $connection = $connections->activeFor($entidade);
        if ($connection === null) {
            throw ValidationException::withMessages(['entidade_id' => 'Vincule uma conta WhatsApp antes de sincronizar templates.']);
        }
        $templates->sync($connection);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Estados dos templates sincronizados.']);

        return back();
    }

    public function updateTemplateStatus(
        Request $request,
        WhatsAppTemplatePurpose $template,
        WhatsAppTemplateService $templates,
    ): RedirectResponse {
        $this->assertEntidadeTemplate($template);
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $templates->setActive($template, (bool) $validated['active']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ativação da finalidade atualizada.']);

        return back();
    }

    /** @return array{id:int,gateway_account_id:int,type:string,name:string,phone_last_four:?string,status:string,active:bool} */
    private function presentConnection(EntidadeWhatsAppConexao $connection): array
    {
        return [
            'id' => $connection->id,
            'gateway_account_id' => $connection->gateway_account_id,
            'type' => $connection->tipo->value,
            'name' => $connection->nome_exibicao,
            'phone_last_four' => $connection->telefone_final,
            'status' => $connection->status->value,
            'active' => $connection->ativo,
        ];
    }

    private function assertEntidadeTemplate(WhatsAppTemplatePurpose $template): void
    {
        abort_if(
            $template->entidade_id === null || $template->entidade_whatsapp_conexao_id === null,
            404,
            'Template organizacional não encontrado.',
        );
    }
}
