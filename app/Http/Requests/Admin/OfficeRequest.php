<?php

namespace App\Http\Requests\Admin;

use App\Models\Gabinete;
use App\Rules\DefinidoNoHub;
use App\Rules\MunicipalityInState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Edição de um gabinete pela administração da plataforma.
 *
 * Só edição: entidade e gabinete nascem no Govnex Hub, e a conta do
 * responsável (nome, e-mail, senha) também vem de lá, pelos vínculos — esta
 * tela não cria nem altera usuário.
 */
class OfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Gabinete && $this->user()->can('update', $office);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Gabinete $office */
        $office = $this->route('office');

        return [
            'nome' => [
                'required', 'string', 'min:2', 'max:180',
                new DefinidoNoHub($office->hub_unidade_id !== null, $office->nome),
            ],
            'vereador_nome' => ['required', 'string', 'min:2', 'max:180'],
            'numero_eleitoral' => ['nullable', 'string', 'regex:/^\d+$/', 'max:20'],
            'municipio' => ['required', 'string', 'min:2', 'max:120', new MunicipalityInState((string) $this->input('estado'))],
            'estado' => ['required', 'string', 'size:2', Rule::in(['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'])],
            'timezone' => ['required', 'timezone:all'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'endereco' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:120'],
            'cep' => ['nullable', 'regex:/^\\d{5}-?\\d{3}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'estado' => Str::upper((string) $this->input('estado')),
            'municipio' => Str::squish((string) $this->input('municipio')),
            'cep' => $this->digits($this->input('cep')),
        ]);
    }

    private function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\\D+/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }
}
