<?php

namespace App\Http\Requests\Team;

use App\Authorization\OfficeAccess;
use App\Enums\OfficePosition;
use App\Tenancy\OfficeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cargo local de um integrante. Atribui quem `OfficeAccess::canAssignPositions()`
 * permite (root, vereador ou chefe de gabinete). O cargo precisa caber no tipo do gabinete
 * (`OfficePosition::allowedFor()`). `null` retira o cargo.
 */
class UpdateMemberPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(OfficeAccess::class)->canAssignPositions($this->user());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $office = app(OfficeContext::class)->officeFor($this->user());
        $allowed = $office !== null ? OfficePosition::allowedFor($office->tipo_gabinete) : [];

        return [
            'cargo' => ['present', 'nullable', 'string', Rule::in(array_map(
                fn (OfficePosition $position): string => $position->value,
                $allowed,
            ))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cargo.in' => 'Este cargo não está disponível para este tipo de gabinete.',
        ];
    }
}
