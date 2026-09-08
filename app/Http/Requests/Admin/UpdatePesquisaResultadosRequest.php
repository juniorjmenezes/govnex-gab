<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\PollCurationController;
use App\Services\Politics\Polls\ResultResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Substitui, à mão, o conjunto de resultados de uma pesquisa eleitoral já
 * existente (tipicamente sincronizada pelo PollingData) por uma curadoria
 * manual — ex.: corrigir um erro, complementar uma pesquisa de presidente
 * sem detalhamento por candidato, ou registrar uma leitura mais confiável da
 * mesma pesquisa. Passa pelo {@see ResultResolver},
 * que só aplica o resultado se a confiança informada for suficiente.
 *
 * @see PollCurationController::updateResultados()
 */
class UpdatePesquisaResultadosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isRoot();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:150'],
            'confidence_score' => ['required', 'integer', 'between:1,100'],
            'url' => ['nullable', 'url', 'max:2048'],
            'observacao' => ['nullable', 'string', 'max:2000'],

            'candidatos' => ['required', 'array', 'min:1'],
            'candidatos.*.nome' => ['required', 'string', 'max:150'],
            'candidatos.*.partido' => ['nullable', 'string', 'max:30'],
            'candidatos.*.percentual' => ['required', 'numeric', 'between:0,100'],
            'candidatos.*.candidato_politico_id' => ['nullable', 'integer', 'exists:candidatos_politicos,id'],
        ];
    }
}
