<?php

namespace App\Http\Requests\Team;

use App\Enums\AccessRole;
use App\Enums\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Papel e situação do vínculo são geridos no Govnex Hub; a edição local de
 * membro do gabinete foi desligada (ver docs/INTEGRACAO_GOVNEX_HUB.md).
 */
class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Gerenciado no Govnex Hub — altere lá.');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in($this->allowedRoles())],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'role' => UserRole::tryFrom((string) $this->input('role'))?->accessRole()->value ?? $this->input('role'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /** @return list<string> */
    private function allowedRoles(): array
    {
        return array_map(fn (AccessRole $role): string => $role->value, AccessRole::cases());
    }
}
