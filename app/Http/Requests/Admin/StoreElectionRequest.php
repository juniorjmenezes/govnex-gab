<?php

namespace App\Http\Requests\Admin;

use App\Enums\ElectionType;
use App\Models\Election;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreElectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRoot() === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ano' => ['required', 'integer', 'min:1990', 'max:2100'],
            'tipo' => ['required', Rule::enum(ElectionType::class)],
            'nome' => ['required', 'string', 'max:255'],
            'codigo_tse' => ['nullable', 'string', 'max:30', 'unique:eleicoes,codigo_tse'],
            'primeiro_turno_em' => ['required', 'date'],
            'segundo_turno_em' => ['nullable', 'date', 'after:primeiro_turno_em'],
            'situacao' => ['required', Rule::in(['programada', 'concluida', 'cancelada'])],
        ];
    }

    public function messages(): array
    {
        return [
            'segundo_turno_em.after' => 'O 2º turno deve ser depois do 1º turno.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // A chave única (ano, tipo) já existe no banco — sem essa checagem,
        // a duplicidade estouraria como QueryException crua em vez de um
        // erro de validação legível.
        $validator->after(function (Validator $validator): void {
            $ano = $this->input('ano');
            $tipo = $this->input('tipo');

            if (! $ano || ! $tipo) {
                return;
            }

            $duplicate = Election::query()
                ->where('ano', $ano)
                ->where('tipo', $tipo)
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'ano',
                    'Já existe uma eleição cadastrada com este ano e tipo.',
                );
            }
        });
    }
}
