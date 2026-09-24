<?php

namespace App\Http\Requests\Admin;

use App\Enums\GabineteStatus;
use App\Models\Gabinete;
use App\Rules\DefinidoNoHub;
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
        $office = $this->route('office');

        return [
            'status' => [
                'required',
                Rule::enum(GabineteStatus::class),
                // Situação de gabinete ligado ao Hub é suspensa/reativada lá.
                new DefinidoNoHub(
                    $office instanceof Gabinete && $office->hub_unidade_id !== null,
                    $office instanceof Gabinete ? $office->status : null,
                ),
            ],
        ];
    }
}
