<?php

namespace App\Enums;

/**
 * Catálogo dos tipos de evento que compõem a timeline da demanda. A timeline
 * é a única fonte de "o que aconteceu": toda ação relevante grava um evento
 * aqui, em vez de espalhar sub-workflows por outras tabelas.
 */
enum DemandEventType: string
{
    case Criada = 'demanda_criada';
    case Atualizacao = 'atualizacao';
    case Encaminhamento = 'encaminhamento';
    case RetornoRecebido = 'retorno_recebido';
    case AnexoAdicionado = 'anexo_adicionado';
    case AnexoRemovido = 'anexo_removido';
    case ResponsavelAlterado = 'responsavel_alterado';
    case PrioridadeAlterada = 'prioridade_alterada';
    case PrazoAlterado = 'prazo_alterado';
    case CidadaoAlterado = 'cidadao_alterado';
    case StatusAlterado = 'status_alterado';
    case ProximaAcaoDefinida = 'proxima_acao_definida';
    case ProximaAcaoConcluida = 'proxima_acao_concluida';
    case Resolvida = 'demanda_resolvida';
    case Reaberta = 'demanda_reaberta';
    case Encerrada = 'demanda_encerrada';
    case Atualizada = 'demanda_atualizada';

    public function label(): string
    {
        return match ($this) {
            self::Criada => 'Demanda criada',
            self::Atualizacao => 'Atualização',
            self::Encaminhamento => 'Encaminhamento',
            self::RetornoRecebido => 'Retorno recebido',
            self::AnexoAdicionado => 'Arquivo anexado',
            self::AnexoRemovido => 'Arquivo removido',
            self::ResponsavelAlterado => 'Responsável alterado',
            self::PrioridadeAlterada => 'Prioridade alterada',
            self::PrazoAlterado => 'Prazo alterado',
            self::CidadaoAlterado => 'Solicitante alterado',
            self::StatusAlterado => 'Status alterado',
            self::ProximaAcaoDefinida => 'Próxima ação definida',
            self::ProximaAcaoConcluida => 'Próxima ação concluída',
            self::Resolvida => 'Demanda resolvida',
            self::Reaberta => 'Demanda reaberta',
            self::Encerrada => 'Demanda encerrada',
            self::Atualizada => 'Dados atualizados',
        };
    }
}
