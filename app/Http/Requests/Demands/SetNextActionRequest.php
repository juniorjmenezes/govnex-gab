<?php

namespace App\Http\Requests\Demands;

use App\Models\Demand;
use App\Models\OfficeMember;
use App\Tenancy\OfficeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetNextActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $demand = $this->route('demanda');

        return $demand instanceof Demand && $this->user()->can('update', $demand);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $officeId = app(OfficeContext::class)->officeIdFor($this->user());

        return [
            'descricao' => ['required', 'string', 'max:255'],
            'data' => ['nullable', 'date'],
            'responsavel_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => OfficeMember::constrainActiveUsers($query, $officeId)),
            ],
        ];
    }
}
