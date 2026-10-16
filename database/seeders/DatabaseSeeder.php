<?php

namespace Database\Seeders;

use App\Enums\DemandEventType;
use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandStatus;
use App\Enums\EntidadeModule;
use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Enums\UserRole;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\Cidadao;
use App\Models\Configuracao;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use App\Models\Entidade;
use App\Models\EntidadeBairro;
use App\Models\Gabinete;
use App\Models\TenantModel;
use App\Models\User;
use App\Services\Entidades\EntidadeEntitlementService;
use App\Services\Entidades\EntidadeMembershipService;
use App\Services\Modules\EntidadeModuleManager;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Cores oficiais de partido e a conta root da plataforma não são dado
        // de demonstração — rodam em qualquer ambiente, inclusive produção.
        $this->call(PartidoCorSeeder::class);
        $this->call(RootUserSeeder::class);

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->seed($this->localAccounts(), false);
    }

    /**
     * @param  array<string, array{name: string, email: string, password: string}>  $accounts
     */
    public function runForDeployment(array $accounts): void
    {
        $this->seed($accounts, true);
    }

    /**
     * @param  array<string, array{name: string, email: string, password: string}>  $accounts
     */
    private function seed(array $accounts, bool $deployment): void
    {
        $avulso = $this->upsertIndependentOffice('gabinete-modelo-fortaleza', [
            'nome' => 'Gabinete Santos',
            'status' => GabineteStatus::Active,
            'vereador_nome' => 'Marina Oliveira',
            'municipio' => 'Fortaleza',
            'estado' => 'CE',
            'telefone' => '8532012026',
            'email' => 'contato@gabinetefacil.test',
            'endereco' => 'Rua Dr. Thompson Bulcão, 830',
            'cor_principal' => '#2563EB',
            'formato_protocolo' => '{ANO}-{SEQUENCIAL}',
            'cabecalho_relatorios' => 'Gabinete da Vereadora Marina Oliveira',
        ]);

        $entitlements = app(EntidadeEntitlementService::class);
        $entitlements->provisionLegacyCompatible($avulso->entidade()->firstOrFail());

        $this->upsertUser($accounts['admin'], UserRole::Root, null);
        $this->upsertUser($accounts['councilor'], UserRole::Administrator, $avulso);
        $this->upsertUser($accounts['chief'], UserRole::Administrator, $avulso);
        $this->upsertUser($accounts['advisor'], UserRole::Operator, $avulso);

        $administrator = User::query()->where('email', $accounts['admin']['email'])->firstOrFail();
        $memberships = app(EntidadeMembershipService::class);
        User::query()
            ->where('gabinete_id', $avulso->id)
            ->get()
            ->each(fn (User $user) => $memberships->syncLegacyUser($user, $administrator));

        $moduleManager = app(GabineteModuleManager::class);
        $moduleManager->sync(
            $avulso,
            $moduleManager->allEnabled(),
            $administrator,
            ['source' => 'database-seeder'],
        );

        $this->seedOffice($avulso, ['Aldeota', 'Benfica', 'Centro', 'Cidade 2000', 'Dionísio Torres', 'Jangurussu', 'Messejana', 'Mondubim']);

        if ($deployment) {
            app(AppointmentSeeder::class)->runForDeployment();
        } else {
            // Câmara Municipal de exemplo com usuários de senha fixa — dado
            // de demonstração, nunca criado num bootstrap de produção.
            $this->seedCityCouncil($administrator);
            $this->call(AppointmentSeeder::class);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertIndependentOffice(string $slug, array $attributes): Gabinete
    {
        $entidade = Entidade::withoutGlobalScopes()->updateOrCreate(['slug' => $slug], [
            'tipo' => EntidadeType::IndependentOffice,
            'nome' => $attributes['nome'],
            'status' => $attributes['status'] === GabineteStatus::Active
                ? EntidadeStatus::Active
                : EntidadeStatus::Suspended,
            'municipio' => $attributes['municipio'],
            'estado' => $attributes['estado'],
            'timezone' => $attributes['timezone'] ?? 'America/Sao_Paulo',
            'logo_path' => $attributes['logo_path'] ?? null,
            'cor_principal' => $attributes['cor_principal'] ?? null,
            'interface_simplificada' => true,
            'suspensa_em' => $attributes['suspended_at'] ?? null,
        ]);

        $office = Gabinete::withoutGlobalScopes()->updateOrCreate(['slug' => $slug], [
            ...$attributes,
            'entidade_id' => $entidade->id,
            'tipo_gabinete' => GabineteType::IndependentOffice,
        ]);

        $entidade->forceFill(['gabinete_origem_id' => $office->id])->saveQuietly();

        return $office;
    }

    /**
     * Câmara Municipal de exemplo com os 5 gabinetes de vereador e a
     * Secretaria legislativa compartilhada — o cenário que exercita vários
     * gabinetes numa mesma entidade, o módulo institucional e a visibilidade
     * cruzada entre eles (agenda/documentos da Secretaria, vista pelos
     * vereadores). Cada vereador recebe o conjunto completo de
     * bairros/cidadãos/demandas, como o gabinete avulso; a Secretaria não —
     * ela não atende cidadão nem abre demanda (ver AGENTS.md: "demandas só
     * para os gabinetes").
     */
    private function seedCityCouncil(User $administrator): void
    {
        $entitlements = app(EntidadeEntitlementService::class);
        $memberships = app(EntidadeMembershipService::class);
        $moduleManager = app(GabineteModuleManager::class);
        $entidadeModules = app(EntidadeModuleManager::class);

        $cityCouncil = Entidade::withoutGlobalScopes()->updateOrCreate(
            ['slug' => 'camara-municipal-barra-nova'],
            [
                'tipo' => EntidadeType::CityCouncil,
                'nome' => 'Câmara Municipal de Barra Nova',
                'status' => EntidadeStatus::Active,
                'municipio' => 'Barra Nova',
                'estado' => 'CE',
                'timezone' => 'America/Fortaleza',
                'interface_simplificada' => false,
            ],
        );
        $entitlements->provisionLegacyCompatible($cityCouncil);

        // O módulo Secretaria legislativa nasce contratado, mas desligado —
        // aqui a Câmara "decide ligar", como faria pela tela de módulos.
        $entidadeModules->syncActivation(
            $cityCouncil,
            array_map(fn (EntidadeModule $module): string => $module->value, EntidadeModule::cases()),
            $administrator,
        );

        $vereadores = [
            ['gabinete-horizonte', 'Gabinete Horizonte', 'Ana Beatriz Ferreira', ['Centro', 'Jardim das Flores']],
            ['gabinete-progresso', 'Gabinete Progresso', 'Ricardo Menezes', ['Vila Progresso', 'Bela Vista']],
            ['gabinete-cidadania', 'Gabinete Cidadania', 'Paulo Henrique Souza', ['Novo Horizonte', 'São José']],
            ['gabinete-renovacao', 'Gabinete Renovação', 'Juliana Albuquerque', ['Alto da Serra', 'Boa Esperança']],
            ['gabinete-vanguarda', 'Gabinete Vanguarda', 'Marcos Vinícius Teixeira', ['Industrial', 'Parque das Águas']],
        ];

        $councilorOffices = [];
        foreach ($vereadores as [$slug, $nome, $vereador, $bairros]) {
            $office = Gabinete::withoutGlobalScopes()->updateOrCreate(
                ['slug' => $slug],
                [
                    'nome' => $nome,
                    'status' => GabineteStatus::Active,
                    'vereador_nome' => $vereador,
                    'municipio' => $cityCouncil->municipio,
                    'estado' => $cityCouncil->estado,
                    'entidade_id' => $cityCouncil->id,
                    'tipo_gabinete' => GabineteType::CouncilorOffice,
                ],
            );
            $this->upsertUser([
                'name' => $vereador,
                'email' => $slug.'@gabinetefacil.test',
                'password' => 'password',
            ], UserRole::Administrator, $office);
            $moduleManager->sync($office, $moduleManager->allEnabled(), $administrator, ['source' => 'database-seeder']);
            $councilorOffices[$office->id] = [$office, $bairros];
        }

        $secretariat = Gabinete::withoutGlobalScopes()->updateOrCreate(
            ['slug' => 'secretaria-legislativa-barra-nova'],
            [
                'nome' => 'Secretaria Legislativa',
                'status' => GabineteStatus::Active,
                'municipio' => $cityCouncil->municipio,
                'estado' => $cityCouncil->estado,
                'entidade_id' => $cityCouncil->id,
                'tipo_gabinete' => GabineteType::LegislativeSecretariat,
            ],
        );
        $this->upsertUser([
            'name' => 'Secretária Legislativa',
            'email' => 'secretaria-legislativa.barra-nova@gabinetefacil.test',
            'password' => 'password',
        ], UserRole::Administrator, $secretariat);
        $moduleManager->sync(
            $secretariat,
            $moduleManager->allEnabled(GabineteType::LegislativeSecretariat),
            $administrator,
            ['source' => 'database-seeder'],
        );

        User::withoutGlobalScopes()
            ->whereIn('gabinete_id', [...array_keys($councilorOffices), $secretariat->id])
            ->get()
            ->each(fn (User $user) => $memberships->syncLegacyUser($user, $administrator));

        foreach ($councilorOffices as [$office, $bairros]) {
            $this->seedOffice($office, $bairros);
        }
    }

    /** @param list<string> $neighborhoodNames */
    private function seedOffice(Gabinete $gabinete, array $neighborhoodNames): void
    {
        $categoryData = [
            ['Saúde', 'Atendimento, exames, medicamentos e gabinetes de saúde', 'heart-pulse', 'critica'],
            ['Educação', 'Escolas, creches, matrículas e transporte escolar', 'graduation-cap', 'informativa'],
            ['Infraestrutura', 'Pavimentação, drenagem e equipamentos urbanos', 'hard-hat', 'atencao'],
            ['Iluminação pública', 'Manutenção e expansão da rede de iluminação', 'lamp-desk', 'atencao'],
            ['Limpeza urbana', 'Coleta, entulho e conservação de espaços públicos', 'trash-2', 'sucesso'],
            ['Transporte e trânsito', 'Mobilidade, sinalização e transporte coletivo', 'bus-front', 'informativa'],
            ['Assistência social', 'Benefícios, acolhimento e proteção social', 'hand-heart', 'informativa'],
            ['Segurança', 'Prevenção e segurança comunitária', 'shield-check', 'neutra'],
            ['Meio ambiente', 'Áreas verdes, fiscalização e educação ambiental', 'leaf', 'sucesso'],
            ['Habitação', 'Moradia, regularização e melhorias habitacionais', 'house', 'atencao'],
            ['Emprego e renda', 'Qualificação, trabalho e empreendedorismo', 'briefcase-business', 'informativa'],
            ['Outros', 'Assuntos que não se enquadram nas demais categorias', 'ellipsis', 'neutra'],
        ];

        $categories = [];
        foreach ($categoryData as [$nome, $descricao, $icone, $cor]) {
            $categories[] = $this->upsertTenant(Categoria::class, $gabinete->id, ['nome' => $nome], [
                'descricao' => $descricao,
                'icone' => $icone,
                'cor_semantica' => $cor,
                'ativo' => true,
            ]);
        }

        $neighborhoods = [];
        foreach ($neighborhoodNames as $nome) {
            $neighborhood = $this->upsertTenant(Bairro::class, $gabinete->id, ['nome' => $nome], [
                'municipio' => $gabinete->municipio,
                'estado' => $gabinete->estado,
                'ativo' => true,
            ]);
            $reference = EntidadeBairro::query()->updateOrCreate(
                [
                    'entidade_id' => $gabinete->entidade_id,
                    'nome' => $nome,
                    'municipio' => $gabinete->municipio,
                    'estado' => $gabinete->estado,
                ],
                ['ativo' => true],
            );
            $neighborhood->forceFill(['entidade_bairro_id' => $reference->id])->save();
            $neighborhoods[] = $neighborhood;
        }

        $this->upsertTenant(Configuracao::class, $gabinete->id, [], [
            'partido' => $gabinete->id % 2 === 0 ? 'PSB' : 'PSD',
            'legislatura' => '2025–2028',
        ]);

        $citizens = [];
        $names = ['Ana Souza', 'Bruno Lima', 'Carla Mendes', 'Daniel Rocha', 'Eliane Alves', 'Francisco Nunes', 'Gabriela Costa', 'Henrique Silva'];
        foreach ($names as $index => $nome) {
            $citizens[] = $this->upsertTenant(Cidadao::class, $gabinete->id, ['nome' => $nome], [
                'bairro_id' => $neighborhoods[$index % count($neighborhoods)]->id,
                'telefone' => '85'.str_pad((string) (990000000 + ($gabinete->id * 100) + $index), 9, '0', STR_PAD_LEFT),
                'whatsapp' => '85'.str_pad((string) (980000000 + ($gabinete->id * 100) + $index), 9, '0', STR_PAD_LEFT),
                'email' => 'cidadao'.$gabinete->id.'.'.($index + 1).'@example.test',
                'consentimento_contato' => $index % 3 !== 0,
                'eleitor' => $index % 2 === 0,
                'cadastrado_em' => now()->subDays($index),
            ]);
        }

        $this->seedDemands($gabinete, $citizens, $categories, $neighborhoods);
    }

    /** @param list<TenantModel> $citizens
     * @param  list<TenantModel>  $categories
     * @param  list<TenantModel>  $neighborhoods
     */
    private function seedDemands(Gabinete $gabinete, array $citizens, array $categories, array $neighborhoods): void
    {
        $creator = User::query()->where('gabinete_id', $gabinete->id)->orderByRaw("CASE role WHEN 'operador' THEN 1 WHEN 'administrador' THEN 2 ELSE 3 END")->firstOrFail();
        $members = User::query()->where('gabinete_id', $gabinete->id)->where('is_active', true)->get();
        $statuses = DemandStatus::cases();
        $priorities = DemandPriority::cases();
        $origins = DemandOrigin::cases();
        $titles = [
            'Reparo de iluminação na via',
            'Solicitação de consulta especializada',
            'Limpeza de terreno com descarte irregular',
            'Manutenção de parada de ônibus',
            'Vaga em creche municipal',
            'Drenagem em rua com alagamentos',
            'Orientação sobre benefício social',
            'Sinalização de trânsito danificada',
            'Poda preventiva de árvore',
            'Regularização de imóvel residencial',
        ];

        foreach ($titles as $index => $title) {
            $status = $statuses[$index % count($statuses)];
            $opened = now()->subDays(24 - ($index * 2));
            $deadline = $index % 3 === 0 ? now()->subDays(2) : now()->addDays($index + 2);
            $protocol = now()->year.'-'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT);
            $responsible = $index % 4 === 0 ? null : $members[$index % count($members)];
            $demand = $this->upsertTenant(Demanda::class, $gabinete->id, ['protocolo' => $protocol], [
                'cidadao_id' => $citizens[$index % count($citizens)]->id,
                'categoria_id' => $categories[$index % count($categories)]->id,
                'bairro_id' => $neighborhoods[$index % count($neighborhoods)]->id,
                'responsavel_id' => $responsible?->id,
                'criado_por_id' => $creator->id,
                'titulo' => $title,
                'descricao' => 'Solicitação fictícia cadastrada para demonstrar o acompanhamento completo pelo gabinete.',
                'endereco' => 'Rua de Exemplo',
                'numero' => (string) (100 + $index),
                'ponto_referencia' => 'Próximo à praça principal',
                'prioridade' => $priorities[$index % count($priorities)],
                'status' => $status,
                'origem' => $origins[$index % count($origins)],
                'aberta_em' => $opened,
                'ultima_atividade_em' => $opened,
                'prazo' => $deadline,
                'concluida_em' => $status->isCompleted() ? now()->subDays(1) : null,
                'encerrada_em' => $status === DemandStatus::Closed ? now()->subDays(1) : null,
                'proxima_acao_descricao' => $index % 5 === 0 ? 'Confirmar retorno com o cidadão' : null,
                'proxima_acao_data' => $index % 5 === 0 ? now()->addDays(2) : null,
                'proxima_acao_responsavel_id' => $index % 5 === 0 ? ($responsible !== null ? $responsible->id : $creator->id) : null,
            ]);

            $this->upsertTenant(DemandaEvento::class, $gabinete->id, [
                'demanda_id' => $demand->id,
                'tipo' => DemandEventType::Criada->value,
            ], [
                'usuario_id' => $creator->id,
                'descricao' => "Demanda {$protocol} criada.",
                'created_at' => $opened,
            ]);

            if ($index % 2 === 0) {
                $this->upsertTenant(DemandaEvento::class, $gabinete->id, [
                    'demanda_id' => $demand->id,
                    'tipo' => DemandEventType::Atualizacao->value,
                ], [
                    'usuario_id' => $creator->id,
                    'descricao' => 'Equipe iniciou o acompanhamento e registrou os próximos passos.',
                    'created_at' => $opened->copy()->addHour(),
                ]);
            }

            if ($index % 3 === 0) {
                $sentAt = $opened->copy()->addDays(2);
                $this->upsertTenant(DemandaEvento::class, $gabinete->id, [
                    'demanda_id' => $demand->id,
                    'tipo' => DemandEventType::Encaminhamento->value,
                ], [
                    'usuario_id' => $creator->id,
                    'descricao' => 'Encaminhamento fictício para acompanhamento das providências solicitadas.',
                    'destino' => 'Secretaria Municipal de Infraestrutura',
                    'setor' => 'Coordenação de Atendimento',
                    'referencia_externa' => 'SEINF-'.$protocol,
                    'prazo_esperado' => $sentAt->copy()->addDays(15),
                    'retorno_recebido_em' => $index % 6 === 0 ? $sentAt->copy()->addDays(10) : null,
                    'created_at' => $sentAt,
                ]);
            }
        }

        DB::table('demanda_protocol_sequences')->updateOrInsert(
            ['gabinete_id' => $gabinete->id, 'ano' => now()->year],
            ['proximo_numero' => count($titles) + 1, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** @param class-string<TenantModel> $modelClass
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    private function upsertTenant(string $modelClass, int $gabineteId, array $identity, array $values): TenantModel
    {
        $model = $modelClass::withoutGlobalScopes()
            ->where('gabinete_id', $gabineteId)
            ->where($identity)
            ->first() ?? new $modelClass;

        $model->forceFill(['gabinete_id' => $gabineteId, ...$identity, ...$values])->save();

        return $model;
    }

    /**
     * @param  array{name: string, email: string, password: string}  $account
     */
    private function upsertUser(array $account, UserRole $role, ?Gabinete $gabinete): void
    {
        $user = User::query()->firstOrNew(['email' => $account['email']]);
        $user->forceFill([
            'gabinete_id' => $gabinete?->id,
            'name' => $account['name'],
            'role' => $role,
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => Hash::make($account['password']),
        ])->save();
    }

    /** @return array<string, array{name: string, email: string, password: string}> */
    private function localAccounts(): array
    {
        return [
            'admin' => [
                'name' => 'Administrador da Plataforma',
                'email' => 'admin@gabinetefacil.test',
                'password' => 'password',
            ],
            'councilor' => [
                'name' => 'Vereadora Marina Oliveira',
                'email' => 'vereador@gabinetefacil.test',
                'password' => 'password',
            ],
            'chief' => [
                'name' => 'Chefe de Gabinete',
                'email' => 'chefe@gabinetefacil.test',
                'password' => 'password',
            ],
            'advisor' => [
                'name' => 'Assessor Parlamentar',
                'email' => 'assessor@gabinetefacil.test',
                'password' => 'password',
            ],
        ];
    }
}
