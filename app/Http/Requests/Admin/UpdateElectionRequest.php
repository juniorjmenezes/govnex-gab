<?php

namespace App\Http\Requests\Admin;

use App\Enums\ElectionType;
use App\Models\Eleicao;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateElectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRoot() === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $election = $this->route('eleicao');
        $electionId = $election instanceof Eleicao ? $election->id : null;

        return [
            'ano' => ['required', 'integer', 'min:1990', 'max:2100'],
            'tipo' => ['required', Rule::enum(ElectionType::class)],
            'nome' => ['required', 'string', 'max:255'],
            'codigo_tse' => [
                'nullable', 'string', 'max:30',
                Rule::unique('eleicoes', 'codigo_tse')->ignore($electionId),
            ],
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
        $validator->after(function (Validator $validator): void {
            $ano = $this->input('ano');
            $tipo = $this->input('tipo');

            if (! $ano || ! $tipo) {
                return;
            }

            $election = $this->route('eleicao');
            $electionId = $election instanceof Eleicao ? $election->id : null;

            $duplicate = Eleicao::query()
                ->where('ano', $ano)
                ->where('tipo', $tipo)
                ->when($electionId !== null, fn ($query) => $query->whereKeyNot($electionId))
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
