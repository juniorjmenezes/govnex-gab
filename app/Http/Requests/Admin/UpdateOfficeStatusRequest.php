<?php

namespace App\Http\Requests\Admin;

use App\Enums\OfficeStatus;
use App\Models\Office;
use App\Rules\DefinidoNoHub;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office && $this->user()->can('suspend', $office);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $office = $this->route('office');

        return [
            'status' => [
                'required',
                Rule::enum(OfficeStatus::class),
                // Situação de gabinete ligado ao Hub é suspensa/reativada lá.
                new DefinidoNoHub(
                    $office instanceof Office && $office->hub_unidade_id !== null,
                    $office instanceof Office ? $office->status : null,
                ),
            ],
        ];
    }
}
