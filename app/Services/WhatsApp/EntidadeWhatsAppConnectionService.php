<?php

namespace App\Services\WhatsApp;

use App\Enums\EntidadeModule;
use App\Enums\EntidadeWhatsAppConnectionStatus;
use App\Enums\EntidadeWhatsAppConnectionType;
use App\Enums\WhatsAppMode;
use App\Models\Entidade;
use App\Models\EntidadeWhatsAppConexao;
use App\Models\User;
use App\Models\WhatsAppConfiguration;
use App\Models\WhatsAppTemplatePurpose;
use App\Services\Modules\EntidadeModuleManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EntidadeWhatsAppConnectionService
{
    public function __construct(
        private readonly WhatsAppGatewayClient $gateway,
        private readonly EntidadeModuleManager $modules,
    ) {}

    public function activeFor(Entidade|int $entidade): ?EntidadeWhatsAppConexao
    {
        $entidadeId = $entidade instanceof Entidade ? $entidade->id : $entidade;

        return EntidadeWhatsAppConexao::query()
            ->where('entidade_id', $entidadeId)
            ->where('ativo', true)
            ->where('status', EntidadeWhatsAppConnectionStatus::Active)
            ->latest('atribuido_em')
            ->first();
    }

    /** @return list<array{id:int,name:string,phone_last_four:?string,active:bool,status:string}> */
    public function availableGatewayAccounts(): array
    {
        return array_values(collect($this->gateway->accounts())
            ->map(function (array $account): ?array {
                $id = (int) ($account['id'] ?? 0);
                if ($id < 1) {
                    return null;
                }

                $lastFour = preg_replace('/\D+/', '', (string) ($account['phone_last_four'] ?? ''));

                return [
                    'id' => $id,
                    'name' => trim((string) ($account['name'] ?? 'Conta WhatsApp '.$id)),
                    'phone_last_four' => strlen($lastFour) === 4 ? $lastFour : null,
                    'active' => (bool) ($account['active'] ?? false),
                    'status' => strtoupper(trim((string) ($account['status'] ?? 'UNKNOWN'))),
                ];
            })
            ->filter()
            ->values()
            ->all());
    }

    public function assign(
        Entidade $entidade,
        int $gatewayAccountId,
        EntidadeWhatsAppConnectionType $type,
        User $actor,
    ): EntidadeWhatsAppConexao {
        abort_unless($actor->isRoot(), 403);
        if (! $this->modules->isActive($entidade, EntidadeModule::WhatsApp)) {
            throw ValidationException::withMessages([
                'account_id' => 'O módulo WhatsApp não está contratado e ativo para a organização.',
            ]);
        }

        $account = collect($this->availableGatewayAccounts())->firstWhere('id', $gatewayAccountId);
        if (! is_array($account) || ! $account['active']) {
            throw ValidationException::withMessages([
                'account_id' => 'A conta não pertence ao cliente GABINETE ou não está ativa no gateway.',
            ]);
        }
        if ($type === EntidadeWhatsAppConnectionType::Own
            && EntidadeWhatsAppConexao::query()
                ->where('gateway_account_id', $gatewayAccountId)
                ->where('entidade_id', '<>', $entidade->id)
                ->where('ativo', true)
                ->exists()) {
            throw ValidationException::withMessages([
                'account_id' => 'Uma conta própria não pode ser atribuída simultaneamente a outra organização.',
            ]);
        }

        return DB::transaction(function () use ($entidade, $gatewayAccountId, $type, $actor, $account): EntidadeWhatsAppConexao {
            $current = EntidadeWhatsAppConexao::query()
                ->where('entidade_id', $entidade->id)
                ->lockForUpdate()
                ->get();
            foreach ($current as $connection) {
                if ((int) $connection->gateway_account_id === $gatewayAccountId) {
                    continue;
                }
                $connection->forceFill([
                    'ativo' => false,
                    'desativado_em' => now(),
                ])->save();
            }

            $connection = EntidadeWhatsAppConexao::query()->updateOrCreate(
                [
                    'entidade_id' => $entidade->id,
                    'gateway_account_id' => $gatewayAccountId,
                ],
                [
                    'tipo' => $type,
                    'nome_exibicao' => $account['name'],
                    'telefone_final' => $account['phone_last_four'],
                    'status' => EntidadeWhatsAppConnectionStatus::Active,
                    'ativo' => true,
                    'metadados' => ['gateway_status' => $account['status']],
                    'atribuido_por' => $actor->id,
                    'atribuido_em' => now(),
                    'sincronizado_em' => now(),
                    'desativado_em' => null,
                ],
            );

            WhatsAppConfiguration::withoutGlobalScopes()
                ->where('entidade_id', $entidade->id)
                ->where(function ($query) use ($connection): void {
                    $query->whereNull('entidade_whatsapp_conexao_id')
                        ->orWhere('entidade_whatsapp_conexao_id', '<>', $connection->id);
                })
                ->update([
                    'entidade_whatsapp_conexao_id' => $connection->id,
                    'modo' => WhatsAppMode::Off->value,
                    'updated_at' => now(),
                ]);
            WhatsAppTemplatePurpose::query()
                ->where('entidade_id', $entidade->id)
                ->where('entidade_whatsapp_conexao_id', '<>', $connection->id)
                ->update(['ativo' => false, 'updated_at' => now()]);

            $this->copyLegacyTemplates($entidade, $connection);

            return $connection->refresh();
        }, 3);
    }

    public function provisionConfiguredLegacy(Entidade $entidade): ?EntidadeWhatsAppConexao
    {
        $existing = $this->activeFor($entidade);
        if ($existing !== null) {
            return $existing;
        }
        $accountId = (int) config('whatsapp.gateway_account_id', 0);
        if ($accountId < 1) {
            return null;
        }

        return DB::transaction(function () use ($entidade, $accountId): EntidadeWhatsAppConexao {
            $connection = EntidadeWhatsAppConexao::query()->firstOrCreate(
                ['entidade_id' => $entidade->id, 'gateway_account_id' => $accountId],
                [
                    'tipo' => EntidadeWhatsAppConnectionType::Central,
                    'nome_exibicao' => 'Conta legada configurada',
                    'status' => EntidadeWhatsAppConnectionStatus::Active,
                    'ativo' => true,
                    'metadados' => ['origem' => 'COMPATIBILIDADE_CONFIGURACAO'],
                    'atribuido_em' => now(),
                ],
            );
            $this->copyLegacyTemplates($entidade, $connection);

            return $connection;
        }, 3);
    }

    public function deactivate(Entidade $entidade, User $actor): void
    {
        abort_unless($actor->isRoot(), 403);
        DB::transaction(function () use ($entidade): void {
            EntidadeWhatsAppConexao::query()
                ->where('entidade_id', $entidade->id)
                ->where('ativo', true)
                ->update(['ativo' => false, 'desativado_em' => now(), 'updated_at' => now()]);
            WhatsAppConfiguration::withoutGlobalScopes()
                ->where('entidade_id', $entidade->id)
                ->update(['modo' => WhatsAppMode::Off->value, 'updated_at' => now()]);
            WhatsAppTemplatePurpose::query()
                ->where('entidade_id', $entidade->id)
                ->update(['ativo' => false, 'updated_at' => now()]);
        });
    }

    private function copyLegacyTemplates(Entidade $entidade, EntidadeWhatsAppConexao $connection): void
    {
        $legacy = WhatsAppTemplatePurpose::query()->whereNull('entidade_id')->get();
        foreach ($legacy as $template) {
            WhatsAppTemplatePurpose::query()->firstOrCreate(
                [
                    'entidade_id' => $entidade->id,
                    'entidade_whatsapp_conexao_id' => $connection->id,
                    'finalidade' => $template->finalidade,
                ],
                [
                    'gateway_template_id' => $template->gateway_template_id,
                    'nome_meta' => $template->nome_meta,
                    'idioma' => $template->idioma,
                    'categoria' => $template->categoria,
                    'status' => $template->status,
                    'componentes' => $template->componentes,
                    'contrato_hash' => $template->contrato_hash,
                    'ativo' => $template->ativo,
                    'sincronizado_em' => $template->sincronizado_em,
                ],
            );
        }
    }
}
