<?php

namespace App\Http\Requests\Committees;

use App\Enums\CommitteeStatus;
use App\Enums\CommitteeType;
use App\Models\ComissaoLegislativa;
use App\Tenancy\OfficeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Dados da comissão. A entidade vem do gabinete em contexto, nunca do corpo. */
class CommitteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $comissao = $this->route('comissao');

        return $comissao instanceof ComissaoLegislativa
            ? $this->user()->can('update', $comissao)
            : $this->user()->can('create', ComissaoLegislativa::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $comissao = $this->route('comissao');

        return [
            'nome' => [
                'required',
                'string',
                'max:180',
                Rule::unique('comissoes_legislativas', 'nome')
                    ->where(fn ($query) => $query
                        ->where('entidade_id', app(OfficeContext::class)->officeFor($this->user())?->entidade_id)
                        ->whereNull('deleted_at'))
                    ->ignore($comissao instanceof ComissaoLegislativa ? $comissao->id : null),
            ],
            'sigla' => ['nullable', 'string', 'max:20'],
            'tipo' => ['required', Rule::enum(CommitteeType::class)],
            // Só na edição: a comissão nasce ativa.
            'status' => [
                Rule::requiredIf($comissao instanceof ComissaoLegislativa),
                Rule::enum(CommitteeStatus::class),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['nome.unique' => 'Já existe uma comissão com este nome na Câmara.'];
    }

    /** @return array{nome: string, sigla: string|null, tipo: string, status?: string} */
    public function committeeData(): array
    {
        /** @var array{nome: string, sigla: string|null, tipo: string, status?: string} $data */
        $data = $this->safe()->only(['nome', 'sigla', 'tipo', 'status']);
        $sigla = trim((string) ($data['sigla'] ?? ''));
        $data['sigla'] = $sigla === '' ? null : $sigla;

        return $data;
    }
}
