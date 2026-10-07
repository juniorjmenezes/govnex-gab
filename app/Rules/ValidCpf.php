<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Confere os dois dígitos verificadores do CPF (só os 11 dígitos, sem
 * máscara — `CitizenRequest::prepareForValidation` já limpa o valor antes
 * daqui). `digits:11` garante o tamanho; esta regra garante que o número é
 * matematicamente válido, não qualquer sequência de 11 dígitos.
 */
class ValidCpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cpf = (string) $value;

        if (! preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            $fail('Informe um CPF válido.');

            return;
        }

        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;

            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $cpf[$i] * (($position + 1) - $i);
            }

            $checkDigit = ($sum * 10) % 11;
            $checkDigit = $checkDigit === 10 ? 0 : $checkDigit;

            if ($checkDigit !== (int) $cpf[$position]) {
                $fail('Informe um CPF válido.');

                return;
            }
        }
    }
}
