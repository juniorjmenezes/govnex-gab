<?php

namespace App\Http\Requests\Demands;

use App\Models\Demanda;
use Illuminate\Foundation\Http\FormRequest;

class ReopenDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        $demand = $this->route('demanda');

        return $demand instanceof Demanda
            && $this->user()->can('update', $demand)
            && $demand->status->isCompleted();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
