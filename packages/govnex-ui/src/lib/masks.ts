export type MaskType = 'phone' | 'cpf' | 'cnpj' | 'cep' | 'date' | 'time';

const limits: Record<MaskType, number> = {
    phone: 11,
    cpf: 11,
    cnpj: 14,
    cep: 8,
    date: 8,
    time: 4,
};

export function digitsOnly(value: string | null | undefined): string {
    return value?.replace(/\D/g, '') ?? '';
}

export function maskPhone(value: string | null | undefined): string {
    const digits = digitsOnly(value).slice(0, limits.phone);

    if (digits.length === 0) {
        return '';
    }

    if (digits.length <= 2) {
        return `(${digits}`;
    }

    if (digits.length <= 6) {
        return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
    }

    if (digits.length <= 10) {
        return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
    }

    return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
}

export function maskCpf(value: string | null | undefined): string {
    const digits = digitsOnly(value).slice(0, limits.cpf);

    return digits
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1-$2');
}

export function maskCep(value: string | null | undefined): string {
    const digits = digitsOnly(value).slice(0, limits.cep);

    return digits.replace(/^(\d{5})(\d)/, '$1-$2');
}

export function maskCnpj(value: string | null | undefined): string {
    const digits = digitsOnly(value).slice(0, limits.cnpj);

    return digits
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
}

export function maskDate(value: string | null | undefined): string {
    const digits = digitsOnly(value).slice(0, limits.date);

    return digits
        .replace(/^(\d{2})(\d)/, '$1/$2')
        .replace(/^(\d{2})\/(\d{2})(\d)/, '$1/$2/$3');
}

export function maskTime(value: string | null | undefined): string {
    const digits = digitsOnly(value).slice(0, limits.time);

    return digits.replace(/^(\d{2})(\d)/, '$1:$2');
}

export function applyMask(
    value: string | null | undefined,
    mask: MaskType,
): string {
    if (mask === 'cpf') {
        return maskCpf(value);
    }

    if (mask === 'cnpj') {
        return maskCnpj(value);
    }

    if (mask === 'cep') {
        return maskCep(value);
    }

    if (mask === 'date') {
        return maskDate(value);
    }

    if (mask === 'time') {
        return maskTime(value);
    }

    return maskPhone(value);
}

export function maskMaxLength(mask: MaskType): number {
    return {
        phone: 15,
        cpf: 14,
        cnpj: 18,
        cep: 9,
        date: 10,
        time: 5,
    }[mask];
}
