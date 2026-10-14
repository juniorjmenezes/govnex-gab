export type CommitteeType = 'permanente' | 'temporaria';
export type CommitteeStatus = 'ativa' | 'extinta';
export type CommitteeRole = 'presidente' | 'relator' | 'membro' | 'suplente';

export type CommitteeOption<T extends string = string> = {
    value: T;
    label: string;
};

export type CommitteeSummary = {
    id: number;
    name: string;
    acronym: string | null;
    type: CommitteeType;
    type_label: string;
    status: CommitteeStatus;
    status_label: string;
};

export type CommitteeListItem = CommitteeSummary & {
    members_count: number;
    documents_count: number;
    president: string | null;
};

export type CommitteeMember = {
    id: number;
    role: CommitteeRole;
    role_label: string;
    office: { id: number; name: string; leader: string | null };
    user: { id: number; name: string } | null;
    joined_at: string;
    left_at: string | null;
};

export type CommitteeDocument = {
    id: number;
    title: string;
    description: string | null;
    pages: number | null;
    link_type: string | null;
    link_type_label: string | null;
    linked_at: string;
};

export type CommitteeOffice = {
    id: number;
    name: string;
    leader: string | null;
    is_member: boolean;
    people: { id: number; name: string }[];
};

export type CommitteeManagementOptions = {
    offices: CommitteeOffice[];
    roles: CommitteeOption<CommitteeRole>[];
    documents: CommitteeOption[];
    link_types: CommitteeOption[];
};

export type CommitteeIndexProps = {
    committees: CommitteeListItem[];
    canManage: boolean;
    options: { types: CommitteeOption<CommitteeType>[] };
};

export type CommitteeShowProps = {
    committee: CommitteeSummary & { closed_at: string | null };
    members: CommitteeMember[];
    documents: CommitteeDocument[];
    canManage: boolean;
    options: CommitteeManagementOptions | null;
};

export type CommitteeEditProps = {
    committee: {
        id: number;
        nome: string;
        sigla: string | null;
        tipo: CommitteeType;
        status: CommitteeStatus;
    };
    options: {
        types: CommitteeOption<CommitteeType>[];
        statuses: CommitteeOption<CommitteeStatus>[];
    };
};
