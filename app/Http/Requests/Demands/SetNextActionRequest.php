<?php

namespace App\Http\Requests\Demands;

use App\Models\Demanda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetNextActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $demand = $this->route('demanda');

        return $demand instanceof Demanda && $this->user()->can('update', $demand);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $officeId = $this->user()->gabinete_id;

        return [
            'descricao' => ['required', 'string', 'max:255'],
            'data' => ['nullable', 'date'],
            'responsavel_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('is_active', true)),
            ],
        ];
    }
}
