<?php

namespace App\Http\Requests\Admin;

use App\Authorization\OfficeAccess;
use App\Enums\OfficePosition;
use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cargo de um integrante pela Administração → Gabinetes: só root
 * (`OfficeAccess::canManagePositionsOnPlatform`), com motivo obrigatório. O
 * cargo precisa caber no tipo do gabinete da rota; `null` retira o cargo.
 */
class UpdateOfficeMemberPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(OfficeAccess::class)->canManagePositionsOnPlatform($this->user());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $office = $this->route('office');
        $allowed = $office instanceof Office ? OfficePosition::allowedFor($office->tipo_gabinete) : [];

        return [
            'cargo' => ['present', 'nullable', 'string', Rule::in(array_map(
                fn (OfficePosition $position): string => $position->value,
                $allowed,
            ))],
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
            // Vereador/chefe já ocupado: retira de quem o tem e atribui a
            // este, numa só operação e numa só auditoria.
            'substituir' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cargo.in' => 'Este cargo não está disponível para este tipo de gabinete.',
            'motivo.required' => 'Informe o motivo da mudança de cargo.',
            'motivo.min' => 'Descreva o motivo em pelo menos 5 caracteres.',
        ];
    }
}
