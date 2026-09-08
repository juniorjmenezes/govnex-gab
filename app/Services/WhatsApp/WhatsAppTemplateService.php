<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppPurpose;
use App\Exceptions\WhatsAppGatewayException;
use App\Models\EntidadeWhatsAppConexao;
use App\Models\WhatsAppTemplatePurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WhatsAppTemplateService
{
    public function __construct(
        private readonly WhatsAppGatewayClient $gateway,
        private readonly WhatsAppTemplateCatalog $catalog,
    ) {}

    /** @return array<int, WhatsAppTemplatePurpose> */
    public function catalog(?EntidadeWhatsAppConexao $connection = null): array
    {
        foreach (WhatsAppPurpose::cases() as $purpose) {
            $definition = $this->catalog->definition($purpose);
            WhatsAppTemplatePurpose::query()->firstOrCreate(
                $this->identity($purpose, $connection),
                [
                    'nome_meta' => $definition['meta_name'],
                    'idioma' => 'pt_BR',
                    'categoria' => 'UTILITY',
                    'status' => 'ABSENT',
                    'componentes' => $definition['components'],
                    'contrato_hash' => $definition['contract_hash'],
                    'ativo' => false,
                ],
            );
        }

        return $this->scope(WhatsAppTemplatePurpose::query(), $connection)
            ->orderBy('finalidade')->get()->all();
    }

    public function createDraft(
        WhatsAppPurpose $purpose,
        ?string $body,
        int $adminUserId,
        ?EntidadeWhatsAppConexao $connection = null,
    ): WhatsAppTemplatePurpose {
        $this->assertOperationalUtility($purpose);
        $local = $this->scope(WhatsAppTemplatePurpose::query(), $connection)
            ->where('finalidade', $purpose->value)->first();
        if ($local && $local->gateway_template_id) {
            throw new RuntimeException('A finalidade já possui uma versão no gateway. Sincronize antes de criar outra.');
        }
        $definition = $this->catalog->definition($purpose, $body);
        $accountId = $this->requireConfiguredAccountId($connection);
        $payload = [
            'purpose' => $purpose->value,
            'meta_name' => $definition['meta_name'],
            'language' => 'pt_BR',
            'account_id' => $accountId,
            'components' => $definition['components'],
            'admin_user_id' => $adminUserId,
        ];
        $remote = $this->gateway->createTemplate($payload);

        return $this->storeRemote($purpose, $definition, $remote, $connection);
    }

    public function createReplacementDraft(
        WhatsAppTemplatePurpose $local,
        ?string $body,
        int $adminUserId,
    ): WhatsAppTemplatePurpose {
        if ($local->ativo) {
            throw new RuntimeException('Desative a versão atual antes de criar uma substituta.');
        }
        $connection = $local->entidade_id !== null ? $local->conexao()->first() : null;
        $accountId = $this->requireConfiguredAccountId($connection);
        $purpose = $local->finalidade;
        $this->assertOperationalUtility($purpose);
        $definition = $this->catalog->definition($purpose, $body);
        $targetVersions = collect($this->gateway->templates())
            ->filter(fn (array $remote): bool => (string) ($remote['purpose'] ?? '') === $purpose->value)
            ->filter(fn (array $remote): bool => (int) ($remote['account_id'] ?? 0) === $accountId)
            ->filter(fn (array $remote): bool => empty($remote['archived_at']))
            ->sortByDesc(fn (array $remote): int => (int) ($remote['version'] ?? 0))
            ->values();
        foreach ($targetVersions as $remote) {
            $components = array_values((array) ($remote['components'] ?? []));
            $matches = (string) ($remote['name'] ?? '') === $definition['meta_name']
                && (string) ($remote['language'] ?? '') === 'pt_BR'
                && strtoupper((string) ($remote['category'] ?? '')) === 'UTILITY'
                && $this->catalog->hash($components) === $definition['contract_hash'];
            if ($matches) {
                return $this->storeRemote($purpose, $definition, $remote, $connection);
            }
        }
        if ($targetVersions->isNotEmpty()) {
            throw new RuntimeException('A conta de destino possui uma versão conflitante para esta finalidade.');
        }

        $remote = $this->gateway->createTemplate([
            'purpose' => $purpose->value,
            'meta_name' => $definition['meta_name'],
            'language' => 'pt_BR',
            'account_id' => $accountId,
            'components' => $definition['components'],
            'admin_user_id' => $adminUserId,
        ]);

        return $this->storeRemote($purpose, $definition, $remote, $connection);
    }

    public function updateDraft(WhatsAppTemplatePurpose $local, string $body, int $adminUserId): WhatsAppTemplatePurpose
    {
        if (! $local->gateway_template_id || $local->status !== 'DRAFT') {
            throw new RuntimeException('Somente um rascunho ainda não submetido pode ser alterado.');
        }
        $purpose = $local->finalidade;
        $connection = $local->entidade_id !== null ? $local->conexao()->first() : null;
        $definition = $this->catalog->definition($purpose, $body);
        $remote = $this->gateway->updateTemplate((int) $local->gateway_template_id, [
            'components' => $definition['components'],
            'admin_user_id' => $adminUserId,
        ]);

        return $this->storeRemote($purpose, $definition, $remote, $connection);
    }

    public function submit(WhatsAppTemplatePurpose $local): WhatsAppTemplatePurpose
    {
        if (! $local->gateway_template_id || $local->status !== 'DRAFT') {
            throw new RuntimeException('A finalidade não possui um rascunho elegível para submissão.');
        }
        try {
            $remote = $this->gateway->submitTemplate((int) $local->gateway_template_id);
        } catch (WhatsAppGatewayException $exception) {
            if ($exception->ambiguous) {
                $local->forceFill([
                    'status' => 'SUBMISSION_AMBIGUOUS',
                    'ativo' => false,
                    'sincronizado_em' => now(),
                ])->save();
            }

            throw $exception;
        }

        $connection = $local->entidade_id !== null ? $local->conexao()->first() : null;

        return $this->storeRemote($local->finalidade, [
            'meta_name' => $local->nome_meta,
            'components' => $local->componentes,
            'contract_hash' => $local->contrato_hash,
        ], $remote, $connection);
    }

    /** @return array<int, WhatsAppTemplatePurpose> */
    public function sync(?EntidadeWhatsAppConexao $connection = null): array
    {
        $accountId = $this->requireConfiguredAccountId($connection);
        $remoteTemplates = $this->gateway->syncTemplates();
        foreach (WhatsAppPurpose::cases() as $purpose) {
            $local = WhatsAppTemplatePurpose::query()->firstOrNew($this->identity($purpose, $connection));
            $candidates = collect($remoteTemplates)
                ->filter(fn (array $remote): bool => (string) ($remote['purpose'] ?? '') === $purpose->value)
                ->filter(fn (array $remote): bool => (int) ($remote['account_id'] ?? 0) === $accountId)
                ->filter(fn (array $remote): bool => empty($remote['archived_at']))
                ->sortByDesc(fn (array $remote): string => sprintf(
                    '%010d-%010d',
                    (int) ($remote['version'] ?? 0),
                    (int) ($remote['id'] ?? 0),
                ));
            $remote = $candidates->first();
            if (! is_array($remote)) {
                $definition = $this->catalog->definition($purpose);
                $local->forceFill([
                    'gateway_template_id' => null,
                    'nome_meta' => $definition['meta_name'],
                    'idioma' => 'pt_BR',
                    'categoria' => 'UTILITY',
                    'status' => 'ABSENT',
                    'componentes' => $definition['components'],
                    'contrato_hash' => $definition['contract_hash'],
                    'ativo' => false,
                    'sincronizado_em' => now(),
                ])->save();

                continue;
            }

            $remoteComponents = array_values((array) ($remote['components'] ?? []));
            $definition = $this->catalog->definition($purpose);
            $knownHash = $local->exists
                ? (string) $local->contrato_hash
                : (string) $definition['contract_hash'];
            $remoteHash = $this->catalog->hash($remoteComponents);
            $matchesIdentity = (string) ($remote['name'] ?? '') === $purpose->metaName()
                && (string) ($remote['language'] ?? '') === 'pt_BR'
                && strtoupper((string) ($remote['category'] ?? '')) === 'UTILITY';
            $status = $remoteHash === $knownHash && $matchesIdentity
                ? (string) ($remote['status'] ?? 'UNKNOWN')
                : 'CONFLICT';
            $sameVersion = (int) $local->gateway_template_id === (int) ($remote['id'] ?? 0);
            $local->forceFill([
                'gateway_template_id' => (int) ($remote['id'] ?? 0) ?: null,
                'nome_meta' => (string) ($remote['name'] ?? $purpose->metaName()),
                'idioma' => (string) ($remote['language'] ?? 'pt_BR'),
                'categoria' => (string) ($remote['category'] ?? 'UTILITY'),
                'status' => $status,
                'componentes' => $remoteComponents,
                'contrato_hash' => $knownHash,
                'ativo' => $status === 'APPROVED' && $sameVersion ? (bool) $local->ativo : false,
                'sincronizado_em' => now(),
            ])->save();
        }

        return $this->catalog($connection);
    }

    public function setActive(WhatsAppTemplatePurpose $local, bool $active): WhatsAppTemplatePurpose
    {
        if ($active && (! $local->gateway_template_id || $local->status !== 'APPROVED')) {
            throw new RuntimeException('O template aprovado não está disponível no gateway.');
        }
        if ($active && $local->entidade_id !== null && ! $local->conexao()->first()?->isReady()) {
            throw new RuntimeException('A conexao WhatsApp da entidade nao esta ativa.');
        }
        $local->forceFill(['ativo' => $active])->save();

        return $local->refresh();
    }

    /** @param array<string, mixed> $definition
     * @param  array<string, mixed>  $remote
     */
    private function storeRemote(
        WhatsAppPurpose $purpose,
        array $definition,
        array $remote,
        ?EntidadeWhatsAppConexao $connection = null,
    ): WhatsAppTemplatePurpose {
        if ((int) ($remote['id'] ?? 0) < 1) {
            throw new RuntimeException('O gateway não retornou uma versão de template válida.');
        }
        $accountId = $this->requireConfiguredAccountId($connection);
        if ((int) ($remote['account_id'] ?? 0) !== $accountId) {
            throw new RuntimeException('O gateway retornou um template vinculado a outra conta WhatsApp.');
        }

        return DB::transaction(function () use ($purpose, $definition, $remote, $connection): WhatsAppTemplatePurpose {
            $components = array_values((array) ($remote['components'] ?? $definition['components']));
            $expectedHash = (string) $definition['contract_hash'];
            $matchesIdentity = (string) ($remote['name'] ?? $definition['meta_name']) === $purpose->metaName()
                && (string) ($remote['language'] ?? 'pt_BR') === 'pt_BR'
                && strtoupper((string) ($remote['category'] ?? 'UTILITY')) === 'UTILITY';
            $status = $this->catalog->hash($components) === $expectedHash && $matchesIdentity
                ? (string) ($remote['status'] ?? 'UNKNOWN')
                : 'CONFLICT';

            return WhatsAppTemplatePurpose::query()->updateOrCreate(
                $this->identity($purpose, $connection),
                [
                    'gateway_template_id' => (int) $remote['id'],
                    'nome_meta' => (string) ($remote['name'] ?? $definition['meta_name']),
                    'idioma' => (string) ($remote['language'] ?? 'pt_BR'),
                    'categoria' => (string) ($remote['category'] ?? 'UTILITY'),
                    'status' => $status,
                    'componentes' => $components,
                    'contrato_hash' => $expectedHash,
                    'ativo' => false,
                    'sincronizado_em' => now(),
                ],
            );
        }, 3);
    }

    private function requireConfiguredAccountId(?EntidadeWhatsAppConexao $connection = null): int
    {
        if ($connection !== null && ! $connection->isReady()) {
            throw new RuntimeException('A conexao WhatsApp da entidade nao esta ativa.');
        }
        $accountId = $connection !== null
            ? (int) $connection->gateway_account_id
            : (int) config('whatsapp.gateway_account_id', 0);
        if ($accountId < 1) {
            throw new RuntimeException('A conta de destino do Gateway WhatsApp não está configurada.');
        }

        return $accountId;
    }

    /** @return array{entidade_id:int|null,entidade_whatsapp_conexao_id:int|null,finalidade:string} */
    private function identity(WhatsAppPurpose $purpose, ?EntidadeWhatsAppConexao $connection): array
    {
        return [
            'entidade_id' => $connection?->entidade_id,
            'entidade_whatsapp_conexao_id' => $connection?->id,
            'finalidade' => $purpose->value,
        ];
    }

    /** @param Builder<WhatsAppTemplatePurpose> $query
     * @return Builder<WhatsAppTemplatePurpose>
     */
    private function scope(Builder $query, ?EntidadeWhatsAppConexao $connection): Builder
    {
        return $connection === null
            ? $query->whereNull('entidade_id')->whereNull('entidade_whatsapp_conexao_id')
            : $query->where('entidade_id', $connection->entidade_id)
                ->where('entidade_whatsapp_conexao_id', $connection->id);
    }

    private function assertOperationalUtility(WhatsAppPurpose $purpose): void
    {
        if (! $purpose->isOperationalUtility()) {
            throw new RuntimeException('A finalidade exige consentimento e política de marketing próprios.');
        }
    }
}
