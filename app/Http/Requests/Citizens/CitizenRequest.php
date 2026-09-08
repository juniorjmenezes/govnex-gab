<?php

namespace App\Http\Requests\Citizens;

use App\Models\Cidadao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

abstract class CitizenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cidadao = $this->route('cidadao');

        return $cidadao instanceof Cidadao
            ? $this->user()->can('update', $cidadao)
            : $this->user()->can('create', Cidadao::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $cidadao = $this->route('cidadao');
        $gabineteId = $this->user()->gabinete_id;

        return [
            'nome' => ['required', 'string', 'max:255'],
            'cpf' => [
                'nullable',
                'digits:11',
                Rule::unique('cidadaos', 'cpf')
                    ->where(fn ($query) => $query->where('gabinete_id', $gabineteId))
                    ->ignore($cidadao),
            ],
            'telefone' => ['nullable', 'string', 'min:10', 'max:15'],
            'whatsapp' => [
                Rule::requiredIf(fn (): bool => $this->boolean('whatsapp_consentimento_operacional')),
                'nullable',
                'string',
                'min:10',
                'max:15',
            ],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'data_nascimento' => ['nullable', 'date', 'before_or_equal:today'],
            'bairro_id' => [
                'nullable',
                'integer',
                Rule::exists('bairros', 'id')->where(fn ($query) => $query->where('gabinete_id', $gabineteId)),
            ],
            'estado' => ['nullable', 'string', 'size:2'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'cep' => ['nullable', 'regex:/^\\d{5}-?\\d{3}$/'],
            'endereco' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'ponto_referencia' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'localizacao_origem' => ['nullable', Rule::in(['endereco', 'logradouro', 'municipio', 'manual'])],
            'observacoes' => ['nullable', 'string', 'max:5000'],
            'consentimento_contato' => ['required', 'boolean'],
            'whatsapp_consentimento_operacional' => ['required', 'boolean'],
            'eleitor' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf' => $this->digits($this->input('cpf')),
            'telefone' => $this->digits($this->input('telefone')),
            'whatsapp' => $this->digits($this->input('whatsapp')),
            'estado' => Str::upper((string) $this->input('estado')) ?: null,
            'municipio' => Str::squish((string) $this->input('municipio')) ?: null,
            'cep' => $this->digits($this->input('cep')),
            'email' => $this->input('email') ? Str::lower(trim((string) $this->input('email'))) : null,
            'consentimento_contato' => $this->boolean('consentimento_contato'),
            'whatsapp_consentimento_operacional' => $this->boolean('whatsapp_consentimento_operacional'),
            'eleitor' => $this->boolean('eleitor'),
            'latitude' => $this->nullableCoordinate('latitude'),
            'longitude' => $this->nullableCoordinate('longitude'),
        ]);
    }

    private function nullableCoordinate(string $field): mixed
    {
        $value = $this->input($field);

        return $value === '' ? null : $value;
    }

    private function digits(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits !== '' ? $digits : null;
    }
}
