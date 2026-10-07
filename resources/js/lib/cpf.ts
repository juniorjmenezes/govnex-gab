/**
 * Confere os dois dígitos verificadores do CPF — mesmo algoritmo de
 * `App\Rules\ValidCpf` no backend. Usado para dar feedback imediato no
 * formulário, sem depender do round-trip ao servidor.
 */
export function isValidCpf(value: string): boolean {
    const cpf = value.replace(/\D+/g, '');

    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
        return false;
    }

    for (let position = 9; position <= 10; position++) {
        let sum = 0;

        for (let i = 0; i < position; i++) {
            sum += Number(cpf[i]) * (position + 1 - i);
        }

        const checkDigit = (sum * 10) % 11 === 10 ? 0 : (sum * 10) % 11;

        if (checkDigit !== Number(cpf[position])) {
            return false;
        }
    }

    return true;
}
