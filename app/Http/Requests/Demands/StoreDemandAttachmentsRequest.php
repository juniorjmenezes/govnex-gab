<?php

namespace App\Http\Requests\Demands;

use App\Models\Demanda;
use Illuminate\Foundation\Http\FormRequest;

class StoreDemandAttachmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $demand = $this->route('demanda');

        return $demand instanceof Demanda && $this->user()->can('update', $demand);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'arquivos' => ['required', 'array', 'min:1', 'max:5'],
            'arquivos.*' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,mp3,m4a',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,audio/mpeg,audio/mp4,audio/x-m4a',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'arquivos.max' => 'Envie no máximo cinco arquivos por vez.',
            'arquivos.*.max' => 'Cada arquivo pode ter no máximo 10 MB.',
            'arquivos.*.mimes' => 'Formato não permitido.',
            'arquivos.*.mimetypes' => 'O conteúdo do arquivo não corresponde a um formato permitido.',
        ];
    }
}
