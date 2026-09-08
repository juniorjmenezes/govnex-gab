<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\PollCurationController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cria, à mão, uma pesquisa eleitoral inteira (metadados + resultado inicial)
 * — o caminho para registrar pesquisas que o PollingData não cobre (ex.:
 * governador, senador, prefeito, ou institutos como AtlasIntel/Ipsos-Ipec),
 * digitadas diretamente do PDF/matéria original.
 *
 * @see PollCurationController::store()
 */
class StorePesquisaManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isRoot();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'eleicao_id' => ['required', 'integer', 'exists:eleicoes,id'],
            'cargo' => ['required', 'string', Rule::in(['presidente', 'governador', 'senador', 'prefeito', 'vereador'])],
            'uf' => ['required', 'string', 'size:2', Rule::in([
                'BR', 'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
                'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
            ])],
            'municipio' => [
                Rule::requiredIf(in_array($this->input('cargo'), ['prefeito', 'vereador'], true)),
                'nullable', 'string', 'max:120',
            ],
            'turno' => ['required', 'integer', Rule::in([1, 2])],
            'cenario' => ['required', 'string', Rule::in([
                'estimulado_1t', 'estimulado_2t', 'espontaneo_1t', 'espontaneo_2t',
            ])],
            'instituto' => ['required', 'string', 'max:150'],
            'publicada_em' => ['required', 'date'],
            'coleta_inicio_em' => ['nullable', 'date', 'before_or_equal:coleta_fim_em'],
            'coleta_fim_em' => ['nullable', 'date'],
            'tamanho_amostra' => ['nullable', 'integer', 'min:1'],
            'margem_erro' => ['nullable', 'numeric', 'between:0,100'],
            'metodologia' => ['nullable', 'string', 'max:30'],
            'abrangencia' => ['nullable', 'string', 'max:80'],
            'tipo' => ['nullable', 'string', 'max:50'],
            'fonte_url' => ['required', 'url', 'max:2048'],

            'provider' => ['required', 'string', 'max:150'],
            'confidence_score' => ['required', 'integer', 'between:1,100'],
            'observacao' => ['nullable', 'string', 'max:2000'],

            'candidatos' => ['required', 'array', 'min:1'],
            'candidatos.*.nome' => ['required', 'string', 'max:150'],
            'candidatos.*.partido' => ['nullable', 'string', 'max:30'],
            'candidatos.*.percentual' => ['required', 'numeric', 'between:0,100'],
            'candidatos.*.candidato_politico_id' => ['nullable', 'integer', 'exists:candidatos_politicos,id'],
        ];
    }
}
