<?php

namespace App\Services\WhatsApp;

use App\Enums\EntidadeModule;
use App\Enums\EntidadeQuota;
use App\Enums\GabineteModule;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppPurpose;
use App\Models\EntidadeWhatsAppConexao;
use App\Models\Gabinete;
use App\Models\WhatsAppConfiguration;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppTemplatePurpose;
use App\Services\Entidades\EntidadeQuotaService;
use App\Services\Modules\EntidadeModuleManager;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Validation\ValidationException;

final class WhatsAppEligibilityService
{
    public function __construct(
        private readonly GabineteModuleManager $modules,
        private readonly EntidadeModuleManager $entidadeModules,
        private readonly EntidadeQuotaService $quotas,
    ) {}

    /** @return array{eligible:bool,reason:string,template:?WhatsAppTemplatePurpose,connection:?EntidadeWhatsAppConexao} */
    public function evaluate(
        WhatsAppContact $contact,
        WhatsAppPurpose $purpose,
        bool $checkQuota = true,
    ): array {
        $office = Gabinete::withoutGlobalScopes()->find($contact->gabinete_id);
        if ($office === null) {
            return $this->denied('O contato não pertence a uma organização válida.');
        }
        if (! $this->entidadeModules->isActive($office->entidade_id, EntidadeModule::WhatsApp)) {
            return $this->denied('O módulo WhatsApp não está habilitado para a organização.');
        }
        if (! $this->modules->isActive($contact->gabinete_id, GabineteModule::WhatsApp)) {
            return $this->denied('O módulo WhatsApp não está habilitado para o gabinete.');
        }
        if (! $this->modules->isActive($contact->gabinete_id, $purpose->sourceModule())) {
            return $this->denied('O módulo de origem desta finalidade está desativado.');
        }
        if (! $purpose->isOperationalUtility()) {
            return $this->denied('A finalidade exige uma política de consentimento própria.');
        }
        if (config('whatsapp.driver') !== 'gateway' || ! config('whatsapp.real_enabled')) {
            return $this->denied('O envio real está desativado.');
        }
        if (! $contact->isEligible()) {
            return $this->denied('O contato não possui consentimento vigente.');
        }

        $configuration = WhatsAppConfiguration::withoutGlobalScopes()
            ->where('gabinete_id', $contact->gabinete_id)
            ->first();
        if (! $configuration || $configuration->modo === WhatsAppMode::Off) {
            return $this->denied('O WhatsApp está desativado para o gabinete.');
        }
        if ($configuration->modo === WhatsAppMode::Pilot && ! $contact->piloto) {
            return $this->denied('O contato não participa do piloto.');
        }
        if (! in_array($purpose->value, (array) $configuration->finalidades_habilitadas, true)) {
            return $this->denied('A finalidade está desativada para o gabinete.');
        }
        if ((int) $configuration->entidade_id !== (int) $office->entidade_id) {
            return $this->denied('A configuração do canal não pertence à organização do gabinete.');
        }

        $connection = $configuration->conexao()->first();
        if (! $connection?->isReady() || (int) $connection->entidade_id !== (int) $office->entidade_id) {
            return $this->denied('A organização não possui uma conexão WhatsApp explícita e ativa.');
        }
        $template = WhatsAppTemplatePurpose::query()
            ->where('entidade_id', $office->entidade_id)
            ->where('entidade_whatsapp_conexao_id', $connection->id)
            ->where('finalidade', $purpose->value)
            ->first();
        if (! $template?->isReady()) {
            return $this->denied('A finalidade não possui template aprovado e ativo para esta conta.');
        }

        if ($checkQuota) {
            try {
                $this->quotas->assertAvailable(
                    $office->entidade_id,
                    EntidadeQuota::MonthlyWhatsAppMessages,
                );
            } catch (ValidationException $exception) {
                return $this->denied((string) collect($exception->errors())->flatten()->first());
            }
        }

        return [
            'eligible' => true,
            'reason' => '',
            'template' => $template,
            'connection' => $connection,
        ];
    }

    /** @return array{eligible:false,reason:string,template:null,connection:null} */
    private function denied(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'template' => null, 'connection' => null];
    }
}
