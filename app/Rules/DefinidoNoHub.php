<?php

namespace App\Rules;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Recusa alterar localmente um campo cuja fonte da verdade é o Govnex Hub.
 *
 * Vale só para item ligado (`hub_entidade_id`/`hub_unidade_id` preenchido);
 * item não ligado continua editável. Reenviar o valor atual passa — os
 * formulários mandam o registro inteiro, com o campo desabilitado.
 */
class DefinidoNoHub implements ValidationRule
{
    public const MENSAGEM = 'Definido no Govnex Hub — altere lá.';

    public function __construct(
        private readonly bool $ligado,
        private readonly mixed $atual,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->ligado && $this->normalizar($value) !== $this->normalizar($this->atual)) {
            $fail(self::MENSAGEM);
        }
    }

    private function normalizar(mixed $valor): string
    {
        if ($valor instanceof BackedEnum) {
            $valor = $valor->value;
        }

        return Str::squish(is_scalar($valor) ? (string) $valor : '');
    }
}
