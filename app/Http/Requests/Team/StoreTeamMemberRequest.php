<?php

namespace App\Http\Requests\Team;

use App\Enums\AccessRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $gabineteId = $this->user()?->gabinete_id;

        return $gabineteId !== null && $this->user()->canManageGabinete($gabineteId);
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
