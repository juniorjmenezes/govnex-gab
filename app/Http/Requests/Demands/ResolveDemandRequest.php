<?php

namespace App\Http\Requests\Demands;

use App\Enums\DemandResultado;
use App\Models\Demanda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDemandRequest extends FormRequest
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
            'resultado' => ['nullable', Rule::enum(DemandResultado::class)],
            'descricao' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
