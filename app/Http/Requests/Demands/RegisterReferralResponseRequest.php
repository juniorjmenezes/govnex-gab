<?php

namespace App\Http\Requests\Demands;

use App\Models\Demand;
use App\Tenancy\OfficeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterReferralResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $demand = $this->route('demanda');

        return $demand instanceof Demand && $this->user()->can('update', $demand);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $demand = $this->route('demanda');
        $officeId = app(OfficeContext::class)->officeIdFor($this->user());

        return [
            'descricao' => ['required', 'string', 'max:10000'],
            'encaminhamento_id' => [
                'nullable',
                'integer',
                Rule::exists('demanda_eventos', 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('demanda_id', $demand instanceof Demand ? $demand->id : 0)
                    ->where('tipo', 'encaminhamento')),
            ],
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
