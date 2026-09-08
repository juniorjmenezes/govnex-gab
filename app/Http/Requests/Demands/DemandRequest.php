<?php

namespace App\Http\Requests\Demands;

use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Models\Demanda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Regras deliberadamente enxutas: "registrar primeiro, organizar durante o
 * atendimento". Só solicitante, assunto e descrição são obrigatórios —
 * categoria, bairro, responsável, origem e prazo podem ser preenchidos
 * depois, na tela de detalhe.
 */
abstract class DemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        $demand = $this->route('demanda');

        return $demand instanceof Demanda
            ? $this->user()->can('update', $demand)
            : $this->user()->can('create', Demanda::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $officeId = $this->user()->gabinete_id;

        return [
            'cidadao_id' => [
                'required',
                'integer',
                Rule::exists('cidadaos', 'id')->where(fn ($query) => $query->where('gabinete_id', $officeId)),
            ],
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['required', 'string', 'max:10000'],
            'categoria_id' => [
                'nullable',
                'integer',
                Rule::exists('categorias', 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('ativo', true)),
            ],
            'bairro_id' => [
                'nullable',
                'integer',
                Rule::exists('bairros', 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('ativo', true)),
            ],
            'estado' => ['nullable', 'string', 'size:2'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'cep' => ['nullable', 'regex:/^\\d{5}-?\\d{3}$/'],
            'responsavel_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('is_active', true)),
            ],
            'endereco' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'ponto_referencia' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'prioridade' => ['required', Rule::enum(DemandPriority::class)],
            'origem' => ['required', Rule::enum(DemandOrigin::class)],
            'prazo' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'estado' => strtoupper((string) $this->input('estado')) ?: null,
            'municipio' => trim((string) $this->input('municipio')) ?: null,
            'cep' => preg_replace('/\\D+/', '', (string) $this->input('cep')) ?: null,
            'prioridade' => $this->input('prioridade') ?: DemandPriority::Normal->value,
            'origem' => $this->input('origem') ?: DemandOrigin::Other->value,
        ]);
    }
}
