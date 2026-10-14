<?php

namespace App\Http\Requests\Committees;

use App\Models\ComissaoLegislativa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Vínculo de um documento já existente da Base de Conhecimento. Só entram
 * documentos compartilhados com a mesma Câmara (`gabinete_id` nulo e
 * `entidade_id` da comissão): um documento privado de gabinete ficaria
 * exposto a toda a Câmara pela comissão, e o da plataforma não é da Câmara.
 */
class CommitteeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $comissao = $this->route('comissao');

        return $comissao instanceof ComissaoLegislativa
            && $this->user()->can('update', $comissao);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var ComissaoLegislativa $comissao */
        $comissao = $this->route('comissao');

        return [
            'documento_id' => [
                'required',
                'integer',
                Rule::exists('conhecimento_documentos', 'id')->where(fn ($query) => $query
                    ->whereNull('gabinete_id')
                    ->where('entidade_id', $comissao->entidade_id)
                    ->whereNull('deleted_at')),
                Rule::unique(ComissaoLegislativa::DOCUMENTS_TABLE, 'documento_id')
                    ->where('comissao_id', $comissao->id),
            ],
            'tipo_vinculo' => ['nullable', 'string', Rule::in(array_keys(ComissaoLegislativa::DOCUMENT_LINK_TYPES))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'documento_id.required' => 'Selecione o documento.',
            'documento_id.exists' => 'Selecione um documento compartilhado com esta Câmara.',
            'documento_id.unique' => 'Este documento já está vinculado à comissão.',
        ];
    }
}
