<?php

namespace App\Http\Requests\KnowledgeBase;

use App\Models\ConhecimentoDocumento;
use Illuminate\Foundation\Http\FormRequest;

class RecordKnowledgeReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('documento');

        return $document instanceof ConhecimentoDocumento
            && $this->user()->can('view', $document);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'pagina' => ['required', 'integer', 'min:1', 'lte:total_paginas'],
            'total_paginas' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
