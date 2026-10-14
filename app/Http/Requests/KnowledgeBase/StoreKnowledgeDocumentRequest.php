<?php

namespace App\Http\Requests\KnowledgeBase;

use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeDocumentRequest extends FormRequest
{
    /** Tamanho máximo do PDF, em kilobytes (30 MB). */
    public const MAX_KILOBYTES = 30720;

    /** A permissão é conferida no controller (gabinete ou plataforma). */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'min:3', 'max:180'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            // Só a Secretaria legislativa pode marcar: o controller exige
            // `createEntidadeWide` quando vem verdadeiro.
            'compartilhar_entidade' => ['sometimes', 'boolean'],
            'arquivo' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                'mimes:pdf',
                'mimetypes:application/pdf',
            ],
        ];
    }

    public function sharesWithEntidade(): bool
    {
        return (bool) $this->validated('compartilhar_entidade', false);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'arquivo.max' => 'O PDF pode ter no máximo 30 MB.',
            'arquivo.mimes' => 'Envie somente arquivos PDF.',
            'arquivo.mimetypes' => 'O conteúdo do arquivo não é um PDF.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['arquivo' => 'arquivo PDF'];
    }
}
