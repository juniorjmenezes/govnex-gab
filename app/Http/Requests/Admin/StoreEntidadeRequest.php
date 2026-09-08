<?php

namespace App\Http\Requests\Admin;

use App\Enums\EntidadeType;
use App\Rules\MunicipalityInState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreEntidadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRoot() === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(EntidadeType::class)],
            'nome' => ['required', 'string', 'min:2', 'max:180'],
            'municipio' => [
                'required',
                'string',
                'min:2',
                'max:120',
                new MunicipalityInState((string) $this->input('estado')),
            ],
            'estado' => [
                'required',
                'string',
                'size:2',
                Rule::in([
                    'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA',
                    'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN',
                    'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
                ]),
            ],
            'timezone' => ['required', 'timezone:all'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => Str::squish((string) $this->input('nome')),
            'municipio' => Str::squish((string) $this->input('municipio')),
            'estado' => Str::upper((string) $this->input('estado')),
        ]);
    }
}
