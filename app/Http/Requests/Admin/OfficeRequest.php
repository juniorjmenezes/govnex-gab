<?php

namespace App\Http\Requests\Admin;

use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Enums\GabineteModule;
use App\Enums\GabineteType;
use App\Enums\UserRole;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\User;
use App\Rules\MunicipalityInState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class OfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Gabinete
            ? $this->user()->can('update', $office)
            : $this->user()->can('create', Gabinete::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $office = $this->route('office');
        $responsibleId = $office instanceof Gabinete
            ? User::query()
                ->where('gabinete_id', $office->id)
                ->where('role', UserRole::Administrator->value)
                ->value('id')
            : null;
        $requiresPassword = ! $office instanceof Gabinete || $responsibleId === null;
        $creating = ! $office instanceof Gabinete;
        $initialPassword = Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols();

        if (app()->isProduction()) {
            $initialPassword->uncompromised();
        }

        return [
            ...($creating ? [
                'entidade_id' => [
                    'required',
                    'integer',
                    Rule::exists('entidades', 'id')
                        ->whereNull('deleted_at')
                        ->where('status', EntidadeStatus::Active->value),
                ],
                'tipo_gabinete' => ['required', Rule::enum(GabineteType::class)],
            ] : []),
            'nome' => ['required', 'string', 'min:2', 'max:180'],
            'vereador_nome' => ['required', 'string', 'min:2', 'max:180'],
            'numero_eleitoral' => ['nullable', 'string', 'regex:/^\d+$/', 'max:20'],
            'municipio' => $creating
                ? ['nullable']
                : ['required', 'string', 'min:2', 'max:120', new MunicipalityInState((string) $this->input('estado'))],
            'estado' => $creating
                ? ['nullable']
                : ['required', 'string', 'size:2', Rule::in(['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'])],
            'timezone' => $creating ? ['nullable'] : ['required', 'timezone:all'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'endereco' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:120'],
            'cep' => ['nullable', 'regex:/^\\d{5}-?\\d{3}$/'],
            'responsavel_nome' => ['required', 'string', 'min:2', 'max:255'],
            'responsavel_email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($responsibleId),
            ],
            'responsavel_password' => [
                $requiresPassword ? 'required' : 'nullable',
                'confirmed',
                $initialPassword,
            ],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['required', 'string', 'distinct', Rule::enum(GabineteModule::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $prepared = [
            'estado' => Str::upper((string) $this->input('estado')),
            'municipio' => Str::squish((string) $this->input('municipio')),
            'cep' => $this->digits($this->input('cep')),
        ];

        if (! $this->route('office') instanceof Gabinete) {
            $prepared += [
                'entidade_id' => $this->integer('entidade_id') ?: null,
                'tipo_gabinete' => (string) $this->input('tipo_gabinete'),
            ];
        }

        $this->merge($prepared);
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->route('office') instanceof Gabinete) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $gabineteType = GabineteType::tryFrom((string) $this->input('tipo_gabinete'));
            if ($gabineteType === null) {
                return;
            }

            if ($this->input('entidade_id') === null || $this->input('entidade_id') === '') {
                $validator->errors()->add('entidade_id', 'Selecione a entidade que receberá o gabinete.');

                return;
            }

            $entidade = Entidade::query()
                ->whereKey((int) $this->input('entidade_id'))
                ->where('status', EntidadeStatus::Active->value)
                ->first();
            if ($entidade === null) {
                return;
            }

            if ($entidade->tipo === EntidadeType::IndependentOffice
                && $entidade->gabinetes()->withoutGlobalScopes()->exists()) {
                $validator->errors()->add('entidade_id', 'Gabinetes independentes não aceitam gabinetes adicionais.');
            }

            if (! $entidade->tipo->accepts($gabineteType)) {
                $validator->errors()->add('tipo_gabinete', 'O tipo de gabinete não é compatível com a entidade selecionada.');
            }

        });
    }

    private function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\\D+/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }
}
