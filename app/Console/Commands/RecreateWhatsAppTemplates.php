<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\WhatsAppPurpose;
use App\Models\User;
use App\Models\WhatsAppTemplatePurpose;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Console\Command;
use Throwable;

final class RecreateWhatsAppTemplates extends Command
{
    protected $signature = 'govnexgab:whatsapp-recreate-templates
        {--purpose=* : Finalidade operacional específica}
        {--admin-email= : Administrador responsável pela operação}
        {--submit : Submete os rascunhos criados para análise da Meta}
        {--confirm : Confirma chamadas ao Gateway WhatsApp}';

    protected $description = 'Recria templates operacionais na conta WhatsApp configurada, em simulação por padrão';

    public function handle(WhatsAppTemplateService $templates): int
    {
        $accountId = (int) config('whatsapp.gateway_account_id', 0);
        if ($accountId < 1) {
            $this->components->error('WHATSAPP_GATEWAY_ACCOUNT_ID não está configurado.');

            return self::FAILURE;
        }

        try {
            $purposes = $this->selectedPurposes();
            $admin = $this->admin();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Conta', 'Finalidade', 'Ação'], array_map(
            fn (WhatsAppPurpose $purpose): array => [
                (string) $accountId,
                $purpose->value,
                $this->option('submit') ? 'Criar/adotar e submeter' : 'Criar/adotar rascunho',
            ],
            $purposes,
        ));

        if (! $this->option('confirm')) {
            $this->components->info('SIMULAÇÃO: nenhuma chamada ao gateway foi realizada. Use --confirm para executar.');

            return self::SUCCESS;
        }

        try {
            $templates->sync();
        } catch (Throwable $exception) {
            $this->components->error('A sincronização inicial falhou: '.$exception->getMessage());

            return self::FAILURE;
        }

        $failed = false;
        foreach ($purposes as $purpose) {
            try {
                $local = WhatsAppTemplatePurpose::query()
                    ->where('finalidade', $purpose->value)
                    ->firstOrFail();
                $remote = $templates->createReplacementDraft($local, null, $admin->id);
                if ($this->option('submit') && $remote->status === 'DRAFT') {
                    $remote = $templates->submit($remote);
                }
                $this->line($purpose->value.': '.$remote->status.' (versão interna '.$remote->gateway_template_id.').');
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->error($purpose->value.': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<WhatsAppPurpose> */
    private function selectedPurposes(): array
    {
        $requested = array_values(array_filter(array_map(
            fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) $this->option('purpose'),
        )));
        if ($requested === []) {
            return WhatsAppPurpose::operationalUtilityCases();
        }

        $purposes = [];
        foreach ($requested as $value) {
            $purpose = WhatsAppPurpose::tryFrom($value);
            if (! $purpose) {
                throw new \RuntimeException('Finalidade desconhecida: '.$value.'.');
            }
            if ($purpose === WhatsAppPurpose::PoliticalPollPublished) {
                throw new \RuntimeException(
                    'PESQUISA_ELEITORAL_PUBLICADA exige consentimento e política de marketing próprios.',
                );
            }
            $purposes[$purpose->value] = $purpose;
        }

        return array_values($purposes);
    }

    private function admin(): User
    {
        $query = User::query()
            ->where('role', UserRole::Root->value)
            ->where('is_active', true);
        $email = mb_strtolower(trim((string) $this->option('admin-email')));
        if ($email !== '') {
            $query->whereRaw('LOWER(email) = ?', [$email]);
        }

        return $query->orderBy('id')->firstOrFail();
    }
}
