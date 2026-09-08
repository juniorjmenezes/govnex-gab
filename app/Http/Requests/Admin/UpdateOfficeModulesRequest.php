<?php

namespace App\Http\Requests\Admin;

use App\Enums\GabineteModule;
use App\Models\Gabinete;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficeModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Gabinete
            && $this->user()?->isRoot()
            && $this->user()->can('update', $office);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'modules' => ['present', 'array'],
            'modules.*' => ['required', 'string', 'distinct', Rule::enum(GabineteModule::class)],
        ];
    }
}
