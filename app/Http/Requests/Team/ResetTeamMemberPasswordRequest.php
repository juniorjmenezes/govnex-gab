<?php

namespace App\Http\Requests\Team;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Contas e senhas são geridas no Govnex Hub; a redefinição local de senha
 * de membro do gabinete foi desligada (ver docs/INTEGRACAO_GOVNEX_HUB.md).
 */
class ResetTeamMemberPasswordRequest extends FormRequest
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
        return ['password' => ['required', 'confirmed', Password::defaults()]];
    }
}
