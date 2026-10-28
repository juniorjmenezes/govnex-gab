<?php

namespace App\Http\Requests\Neighborhoods;

use App\Models\Neighborhood;
use App\Tenancy\OfficeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

abstract class NeighborhoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bairro = $this->route('bairro');

        return $bairro instanceof Neighborhood
            ? $this->user()->can('update', $bairro)
            : $this->user()->can('create', Neighborhood::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $gabinete = app(OfficeContext::class)->requireOfficeFor($this->user());

        return [
            'nome' => [
                'required',
                'string',
                'max:120',
                Rule::unique('bairros', 'nome')
                    ->where(fn ($query) => $query
                        ->where('gabinete_id', app(OfficeContext::class)->officeIdFor($this->user()))
                        ->where('municipio', $gabinete->municipio)
                        ->where('estado', $gabinete->estado))
                    ->ignore($this->route('bairro')),
            ],
            'ativo' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => Str::squish((string) $this->input('nome')),
            'ativo' => $this->boolean('ativo'),
        ]);
    }
}
