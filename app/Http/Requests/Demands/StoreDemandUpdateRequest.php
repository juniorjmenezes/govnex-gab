<?php

namespace App\Http\Requests\Demands;

use App\Models\Demanda;
use Illuminate\Foundation\Http\FormRequest;

class StoreDemandUpdateRequest extends FormRequest
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
            'texto' => ['required', 'string', 'max:10000'],
            'arquivos' => ['nullable', 'array', 'max:5'],
            'arquivos.*' => [
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,mp3,m4a',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,audio/mpeg,audio/mp4,audio/x-m4a',
            ],
        ];
    }
}
