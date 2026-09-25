<?php

namespace App\Http\Requests\Team;

use App\Enums\AccessRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Pessoas e vínculos são geridos no Govnex Hub; a criação de membro local
 * do gabinete foi desligada (ver docs/INTEGRACAO_GOVNEX_HUB.md).
 */
class StoreTeamMemberRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in($this->allowedRoles())],
            'password' => [
                Rule::requiredIf(fn (): bool => ! User::query()
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $this->input('email'))])
                    ->exists()),
                'nullable',
                'confirmed',
                Password::defaults(),
            ],
        ];
    }

    /** @return list<string> */
    private function allowedRoles(): array
    {
        return array_map(fn (AccessRole $role): string => $role->value, AccessRole::cases());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['role' => UserRole::tryFrom((string) $this->input('role'))?->accessRole()->value ?? $this->input('role')]);
    }
}
