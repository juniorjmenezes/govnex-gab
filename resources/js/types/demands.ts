import type {
    Category,
    Citizen,
    Neighborhood,
    Pagination,
} from './registrations';

export type DemandStatus =
    'nova' | 'em_andamento' | 'aguardando' | 'resolvida' | 'encerrada';

export type DemandPriority = 'baixa' | 'normal' | 'alta' | 'urgente';
export type DemandOrigin =
    | 'whatsapp'
    | 'telefone'
    | 'atendimento_presencial'
    | 'visita_bairro'
    | 'rede_social'
    | 'email'
    | 'outro';

export type DemandResultado =
    | 'atendida'
    | 'parcialmente_atendida'
    | 'nao_atendida'
    | 'orientacao_prestada'
    | 'encaminhada_definitivamente'
    | 'duplicada'
    | 'outra';

export type DemandEventType =
    | 'demanda_criada'
    | 'atualizacao'
    | 'encaminhamento'
    | 'retorno_recebido'
    | 'anexo_adicionado'
    | 'anexo_removido'
    | 'responsavel_alterado'
    | 'prioridade_alterada'
    | 'prazo_alterado'
    | 'cidadao_alterado'
    | 'status_alterado'
    | 'proxima_acao_definida'
    | 'proxima_acao_concluida'
    | 'demanda_resolvida'
    | 'demanda_reaberta'
    | 'demanda_encerrada'
    | 'demanda_atualizada';

export type SelectOption = { value: string; label: string };
export type IdNameOption = { id: number; nome?: string; name?: string };
export type DemandMember = { id: number; name: string; email?: string };
export type DemandCategory = Pick<
    Category,
    'id' | 'nome' | 'icone' | 'cor_semantica'
>;
export type DemandNeighborhood = Pick<Neighborhood, 'id' | 'nome'> &
    Partial<Pick<Neighborhood, 'municipio' | 'estado'>>;

export type Demand = {
    id: number;
    protocolo: string;
    titulo: string;
    descricao: string;
    status: DemandStatus;
    prioridade: DemandPriority;
    origem: DemandOrigin;
    resultado: DemandResultado | null;
    cidadao_id: number;
    categoria_id: number | null;
    bairro_id: number | null;
    responsavel_id: number | null;
    endereco: string | null;
    numero: string | null;
    complemento: string | null;
    ponto_referencia: string | null;
    latitude: string | null;
    longitude: string | null;
    aberta_em: string;
    prazo: string | null;
    concluida_em: string | null;
    encerrada_em: string | null;
    ultima_atividade_em: string | null;
    proxima_acao_descricao: string | null;
    proxima_acao_data: string | null;
    proxima_acao_responsavel_id: number | null;
    proxima_acao_concluida_em: string | null;
    favoritada_em: string | null;
    favoritada_por_id: number | null;
    atrasada: boolean;
    proxima_acao_atrasada: boolean;
    cidadao: Citizen;
    categoria: DemandCategory | null;
    bairro: DemandNeighborhood | null;
    responsavel: DemandMember | null;
    proxima_acao_responsavel?: DemandMember | null;
    favoritada_por?: DemandMember | null;
    criado_por?: DemandMember;
    eventos?: DemandEvent[];
    anexos?: DemandAttachment[];
};

export type DemandEvent = {
    id: number;
    tipo: DemandEventType;
    descricao: string | null;
    dados: Record<string, unknown> | null;
    destino: string | null;
    setor: string | null;
    referencia_externa: string | null;
    prazo_esperado: string | null;
    retorno_recebido_em: string | null;
    retorno_de_evento_id: number | null;
    retorno_de?: { id: number; destino: string | null } | null;
    created_at: string;
    usuario: DemandMember | null;
    anexos?: DemandAttachment[];
};

export type DemandAttachment = {
    id: number;
    demanda_evento_id: number | null;
    nome_original: string;
    mime_type: string;
    extensao: string;
    tamanho: number;
    imagem: boolean;
    created_at: string;
    usuario: DemandMember | null;
};

export type PendingReferral = { id: number; destino: string | null };

export type DemandOptions = {
    statuses: SelectOption[];
    priorities: SelectOption[];
    origins: SelectOption[];
    categories: DemandCategory[];
    neighborhoods: IdNameOption[];
    members: DemandMember[];
    creationPriorities?: SelectOption[];
    citizens?: Array<
        Pick<
            Citizen,
            | 'id'
            | 'nome'
            | 'bairro_id'
            | 'endereco'
            | 'numero'
            | 'complemento'
            | 'ponto_referencia'
        >
    >;
};

export type DemandTab = 'inbox' | 'mine' | 'awaiting' | 'today' | 'overdue';

export type DemandFilters = {
    tab: DemandTab;
    q: string;
    status: string;
    prioridade: string;
    categoria_id: number | null;
    bairro_id: number | null;
    responsavel_id: number | null;
    origem: string;
    aberta_de: string;
    aberta_ate: string;
    sem_responsavel: boolean;
    sort: string;
    direction: string;
    per_page: number;
};

export type DemandsPage = Pagination<Demand>;
