<?php

namespace App\Http\Requests\Admin;

use App\Models\PartidoCor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePartyColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRoot() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sigla' => mb_strtoupper(trim((string) $this->input('sigla'))),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'sigla' => ['required', 'string', 'max:30'],
            'cor' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // A mesma sigla pode chegar com e sem acento a depender da fonte
        // (ex.: "MISSÃO" vs. "MISSAO" do TSE) — sem essa checagem, o root
        // poderia cadastrar as duas variações como partidos diferentes.
        $validator->after(function (Validator $validator): void {
            $sigla = (string) $this->input('sigla');

            if ($sigla === '') {
                return;
            }

            $normalized = PartidoCor::normalizeSigla($sigla);
            $duplicate = PartidoCor::query()
                ->get(['sigla'])
                ->contains(fn (PartidoCor $partidoCor): bool => PartidoCor::normalizeSigla($partidoCor->sigla) === $normalized);

            if ($duplicate) {
                $validator->errors()->add(
                    'sigla',
                    'Já existe uma cor cadastrada para este partido (considerando variações de acentuação).',
                );
            }
        });
    }
}
