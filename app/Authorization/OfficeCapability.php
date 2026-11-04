<?php

namespace App\Authorization;

/**
 * Capacidades nomeadas que as Policies consultam em `OfficeAccess`, no lugar
 * de ler o papel do vínculo diretamente.
 */
enum OfficeCapability: string
{
    /** Criar e alterar registros do gabinete (demandas, agenda, atendimentos…). */
    case WriteRecords = 'writeRecords';
    /** Excluir registros do gabinete. */
    case DeleteRecords = 'deleteRecords';
    /** Trocar o responsável de uma demanda que já tem responsável. */
    case ReassignDemands = 'reassignDemands';
    /** Relatórios e exportações. */
    case ExportReports = 'exportReports';
    /** Configurações, categorias e bairros do gabinete. */
    case ConfigureOffice = 'configureOffice';
    /** Consultar a equipe do gabinete. */
    case ManageTeam = 'manageTeam';
    /** Criar e alterar no módulo Secretaria legislativa (comissões, agenda institucional, documentos compartilhados). */
    case WriteLegislativeSecretariat = 'writeLegislativeSecretariat';
    /** Excluir no módulo Secretaria legislativa. */
    case DeleteLegislativeSecretariat = 'deleteLegislativeSecretariat';
    /** Remover documento compartilhado com a entidade enviado por outra pessoa. */
    case ModerateLegislativeSecretariat = 'moderateLegislativeSecretariat';
}
