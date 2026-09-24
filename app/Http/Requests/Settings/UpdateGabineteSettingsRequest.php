<?php

namespace App\Http\Requests\Settings;

use App\Rules\DefinidoNoHub;
use App\Rules\MunicipalityInState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateGabineteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->gabinete_id !== null && $this->user()->role->isAdministrator();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $gabinete = $this->user()->gabinete()->first();

        return [
            'nome' => [
                'required', 'string', 'max:255',
                new DefinidoNoHub($gabinete?->hub_unidade_id !== null, $gabinete?->nome),
            ],
            'vereador_nome' => ['required', 'string', 'max:255'],
            'partido' => ['nullable', 'string', 'max:30'],
            'legislatura' => ['nullable', 'string', 'max:50'],
            'municipio' => ['required', 'string', 'max:120', new MunicipalityInState((string) $this->input('estado'))],
            'estado' => [
                'required',
                'string',
                'size:2',
                Rule::in([
                    'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
                    'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
                    'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
                ]),
            ],
            'timezone' => ['required', 'timezone:all'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'endereco' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:120'],
            'cep' => ['nullable', 'regex:/^\\d{5}-?\\d{3}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'cor_principal' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'usar_cor_padrao' => ['required', 'boolean'],
            'remover_logo' => ['required', 'boolean'],
            'formato_protocolo' => ['required', 'string', 'max:100', 'regex:/\{ANO\}.*\{SEQUENCIAL\}/'],
            'cabecalho_relatorios' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'municipio' => Str::squish((string) $this->input('municipio')),
            'estado' => Str::upper((string) $this->input('estado')),
            'cep' => preg_replace('/\\D+/', '', (string) $this->input('cep')) ?: null,
            'usar_cor_padrao' => $this->boolean('usar_cor_padrao'),
            'remover_logo' => $this->boolean('remover_logo'),
        ]);
    }
}
