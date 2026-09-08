<?php

namespace App\Console\Commands;

use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppPurpose;
use App\Models\Gabinete;
use App\Models\WhatsAppConfiguration;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppTemplatePurpose;
use Illuminate\Console\Command;

final class AuditWhatsAppReadiness extends Command
{
    protected $signature = 'govnexgab:whatsapp-audit
        {--strict : Retorna falha quando o canal real não estiver integralmente pronto}';

    protected $description = 'Audita a prontidão local do GOVNEX GAB para o Gateway WhatsApp sem enviar mensagens';

    public function handle(): int
    {
        $driver = (string) config('whatsapp.driver');
        $realEnabled = (bool) config('whatsapp.real_enabled');
        $operationalPurposes = array_column(WhatsAppPurpose::operationalUtilityCases(), 'value');
        $expectedPurposes = count($operationalPurposes);
        $templates = WhatsAppTemplatePurpose::query()->whereIn('finalidade', $operationalPurposes)->get();
        $readyTemplates = $templates->filter->isReady()->count();
        $contacts = WhatsAppContact::withoutGlobalScopes()->with('consents')->get();
        $eligibleContacts = $contacts->filter->isEligible()->count();
        $pilotViolations = $this->pilotViolations();
        $configurationCount = WhatsAppConfiguration::withoutGlobalScopes()->count();
        $officeCount = Gabinete::withoutGlobalScopes()->count();
        $pendingOutbox = WhatsAppNotification::withoutGlobalScopes()
            ->whereIn('status', ['PENDING', 'PROCESSING', 'RECONCILING'])
            ->count();

        $secretsReady = strlen((string) config('whatsapp.request_secret')) >= 32
            && strlen((string) config('whatsapp.callback_secret')) >= 32
            && strlen((string) config('whatsapp.phone_hash_secret')) >= 32;
        $gatewayReady = $driver === 'gateway'
            && filter_var((string) config('whatsapp.gateway_url'), FILTER_VALIDATE_URL) !== false
            && $secretsReady;

        $this->table(['Verificação', 'Resultado'], [
            ['Driver', $driver],
            ['Envio real', $realEnabled ? 'habilitado' : 'desabilitado'],
            ['Configurações de gabinete', $configurationCount.'/'.$officeCount],
            ['Templates ativos', $readyTemplates.'/'.$expectedPurposes],
            ['Contatos elegíveis', (string) $eligibleContacts],
            ['Violações do piloto', (string) $pilotViolations],
            ['Outbox pendente', (string) $pendingOutbox],
            ['Gateway e segredos', $gatewayReady ? 'configurados' : 'incompletos'],
            ['Callback privado', url('/api/integrations/whatsapp/callback')],
        ]);

        $ready = $gatewayReady
            && $configurationCount === $officeCount
            && $readyTemplates === $expectedPurposes
            && $eligibleContacts >= 2
            && $pilotViolations === 0;
        if ($this->option('strict') && ! $ready) {
            $this->components->error('O canal real ainda não está pronto. Nenhuma mensagem foi enviada.');

            return self::FAILURE;
        }

        $this->components->info($ready
            ? 'Prontidão local confirmada sem envio externo.'
            : 'Auditoria concluída; o canal permanece incompleto ou desativado.');

        return self::SUCCESS;
    }

    private function pilotViolations(): int
    {
        $violations = 0;
        $pilotOffices = WhatsAppConfiguration::withoutGlobalScopes()
            ->where('modo', WhatsAppMode::Pilot->value)
            ->pluck('gabinete_id');
        foreach ($pilotOffices as $officeId) {
            $base = WhatsAppContact::withoutGlobalScopes()
                ->where('gabinete_id', $officeId)
                ->where('piloto', true);
            if ((clone $base)->whereNotNull('usuario_id')->count() !== 1) {
                $violations++;
            }
            if ((clone $base)->whereNotNull('cidadao_id')->count() !== 1) {
                $violations++;
            }
        }

        return $violations;
    }
}
