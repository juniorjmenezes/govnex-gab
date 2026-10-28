<?php

namespace App\Enums;

enum WhatsAppPurpose: string
{
    case DemandAssigned = 'DEMANDA_ATRIBUIDA';
    case DemandStatusChanged = 'DEMANDA_STATUS_ALTERADO';
    case DemandObservationAdded = 'DEMANDA_OBSERVACAO_ADICIONADA';
    case DemandDeadlineDigest = 'DEMANDAS_PRAZO_RESUMO';
    case AppointmentStaffReminder = 'AGENDA_LEMBRETE_EQUIPE';
    case AppointmentCitizenReminder = 'AGENDA_LEMBRETE_CIDADAO';
    case AppointmentChanged = 'AGENDA_ALTERADA';
    case AppointmentCancelled = 'AGENDA_CANCELADA';
    case PoliticalPollPublished = 'PESQUISA_ELEITORAL_PUBLICADA';
    case InstitutionalConvocation = 'SECRETARIA_CONVOCACAO';

    public function label(): string
    {
        return match ($this) {
            self::DemandAssigned => 'Demanda atribuída',
            self::DemandStatusChanged => 'Status de demanda alterado',
            self::DemandObservationAdded => 'Observação adicionada à demanda',
            self::DemandDeadlineDigest => 'Resumo diário de prazos',
            self::AppointmentStaffReminder => 'Lembrete de agenda para a equipe',
            self::AppointmentCitizenReminder => 'Lembrete de agenda para o cidadão',
            self::AppointmentChanged => 'Agenda alterada',
            self::AppointmentCancelled => 'Agenda cancelada',
            self::PoliticalPollPublished => 'Pesquisa eleitoral publicada',
            self::InstitutionalConvocation => 'Convocação institucional da Câmara',
        };
    }

    public function metaName(): string
    {
        return 'gabnex_'.strtolower($this->value).'_v1';
    }

    public function expirationHours(): int
    {
        return match ($this) {
            self::DemandDeadlineDigest => 12,
            self::AppointmentStaffReminder,
            self::AppointmentCitizenReminder,
            self::InstitutionalConvocation => 24,
            default => 24,
        };
    }

    public function sourceModule(): OfficeModule
    {
        return match ($this) {
            self::DemandAssigned,
            self::DemandStatusChanged,
            self::DemandObservationAdded,
            self::DemandDeadlineDigest => OfficeModule::Demands,
            self::AppointmentStaffReminder,
            self::AppointmentCitizenReminder,
            self::AppointmentChanged,
            self::AppointmentCancelled => OfficeModule::Schedule,
            self::PoliticalPollPublished => OfficeModule::Politics,
            // O módulo de origem é conferido no gabinete de quem RECEBE (o de
            // vereador), e lá o compromisso institucional aparece pela Agenda.
            // `LegislativeSecretariat` só existe na própria Secretaria: usá-lo
            // aqui recusaria todo destinatário.
            self::InstitutionalConvocation => OfficeModule::Schedule,
        };
    }

    public function isOperationalUtility(): bool
    {
        return $this !== self::PoliticalPollPublished;
    }

    /** @return list<self> */
    public static function operationalUtilityCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $purpose): bool => $purpose->isOperationalUtility(),
        ));
    }
}
