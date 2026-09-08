<?php

namespace App\Http\Requests\Demands;

use App\Models\Demanda;
use Illuminate\Foundation\Http\FormRequest;

class CloseDemandRequest extends FormRequest
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
            'descricao' => ['required', 'string', 'max:10000'],
        ];
    }
}
