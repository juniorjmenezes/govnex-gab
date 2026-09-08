export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};
export type Pagination<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};
export type OfficeLocation = {
    estado: string;
    municipio: string;
};
export type Neighborhood = {
    id: number;
    nome: string;
    municipio: string;
    estado: string;
    ativo: boolean;
};
export type CategorySemanticColor =
    'neutra' | 'informativa' | 'sucesso' | 'atencao' | 'critica';
export type Category = {
    id: number;
    nome: string;
    descricao: string | null;
    icone: string | null;
    cor_semantica: CategorySemanticColor;
    ativo: boolean;
};
export type Citizen = {
    id: number;
    nome: string;
    cpf: string | null;
    telefone: string | null;
    whatsapp: string | null;
    email: string | null;
    data_nascimento: string | null;
    bairro_id: number | null;
    bairro?: Neighborhood | null;
    endereco: string | null;
    numero: string | null;
    complemento: string | null;
    ponto_referencia: string | null;
    latitude: string | null;
    longitude: string | null;
    localizacao_origem:
        'endereco' | 'logradouro' | 'municipio' | 'manual' | null;
    observacoes: string | null;
    consentimento_contato: boolean;
    whatsapp_consentimento_operacional?: boolean;
    eleitor: boolean;
    cadastrado_em: string;
};
export type DuplicateMatch = { id: number; nome: string; matches: string[] };
