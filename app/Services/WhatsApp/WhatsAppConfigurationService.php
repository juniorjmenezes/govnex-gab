<?php

namespace App\Services\WhatsApp;

use App\Enums\GabineteModule;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppPurpose;
use App\Models\Gabinete;
use App\Models\WhatsAppConfiguration;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class WhatsAppConfigurationService
{
    public function __construct(
        private readonly GabineteModuleManager $modules,
        private readonly EntidadeWhatsAppConnectionService $connections,
    ) {}

    public function forOffice(int $officeId): WhatsAppConfiguration
    {
        return DB::transaction(function () use ($officeId): WhatsAppConfiguration {
            $office = Gabinete::withoutGlobalScopes()->with('entidade')->findOrFail($officeId);
            $configuration = WhatsAppConfiguration::withoutGlobalScopes()
                ->where('gabinete_id', $officeId)
                ->lockForUpdate()
                ->first();
            if ($configuration) {
                return $configuration;
            }

            $configuration = new WhatsAppConfiguration;
            $connection = $this->connections->activeFor($office->entidade_id);
            $configuration->forceFill([
                'gabinete_id' => $officeId,
                'entidade_id' => $office->entidade_id,
                'entidade_whatsapp_conexao_id' => $connection?->id,
                'modo' => WhatsAppMode::Off,
                'resumo_diario_em' => '08:00:00',
                'finalidades_habilitadas' => [],
            ])->save();

            return $configuration;
        }, 3);
    }

    /** @param array<int, string> $purposes */
    public function update(Gabinete $office, WhatsAppMode $mode, string $digestTime, array $purposes): WhatsAppConfiguration
    {
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $digestTime)) {
            throw new InvalidArgumentException('O horário do resumo diário é inválido.');
        }
        $allowed = array_column(WhatsAppPurpose::operationalUtilityCases(), 'value');
        $purposes = array_values(array_unique(array_map('strval', $purposes)));
        if (array_diff($purposes, $allowed) !== []) {
            throw new InvalidArgumentException('Uma das finalidades informadas é inválida.');
        }
        if ($mode !== WhatsAppMode::Off
            && ! $this->modules->isActive($office, GabineteModule::WhatsApp)) {
            throw ValidationException::withMessages([
                'mode' => 'O módulo WhatsApp não está habilitado para o gabinete.',
            ]);
        }
        foreach ($purposes as $purposeValue) {
            $purpose = WhatsAppPurpose::from($purposeValue);
            if (! $this->modules->isActive($office, $purpose->sourceModule())) {
                throw ValidationException::withMessages([
                    'purposes' => "A finalidade {$purpose->label()} exige o módulo {$purpose->sourceModule()->label()}.",
                ]);
            }
        }

        $connection = $this->connections->activeFor($office->entidade_id);
        if ($mode !== WhatsAppMode::Off && ! $connection?->isReady()) {
            throw ValidationException::withMessages([
                'mode' => 'A organização não possui uma conta WhatsApp explicitamente vinculada e ativa.',
            ]);
        }

        return DB::transaction(function () use ($office, $mode, $digestTime, $purposes, $connection): WhatsAppConfiguration {
            $configuration = WhatsAppConfiguration::withoutGlobalScopes()
                ->where('gabinete_id', $office->id)
                ->lockForUpdate()
                ->first() ?? new WhatsAppConfiguration;
            $configuration->forceFill([
                'gabinete_id' => $office->id,
                'entidade_id' => $office->entidade_id,
                'entidade_whatsapp_conexao_id' => $connection?->id,
                'modo' => $mode,
                'resumo_diario_em' => $digestTime.':00',
                'finalidades_habilitadas' => $purposes,
            ])->save();

            return $configuration->refresh();
        }, 3);
    }
}
