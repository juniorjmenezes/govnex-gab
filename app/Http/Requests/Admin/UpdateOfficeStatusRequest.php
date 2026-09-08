<?php

namespace App\Http\Requests\Admin;

use App\Enums\GabineteStatus;
use App\Models\Gabinete;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Gabinete && $this->user()->can('suspend', $office);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(GabineteStatus::class)],
        ];
    }
}
