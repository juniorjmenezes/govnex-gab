<?php

namespace App\Http\Requests\Team;

use App\Enums\GabineteRole;
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
        return $this->user()->gabineteRole((int) $this->user()->gabinete_id) === GabineteRole::Manager
            ? [GabineteRole::Member->value]
            : [GabineteRole::Manager->value, GabineteRole::Member->value];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['role' => match ($this->input('role')) {
            UserRole::ChiefOfStaff->value => GabineteRole::Manager->value,
            UserRole::Advisor->value => GabineteRole::Member->value,
            default => $this->input('role'),
        }]);
    }
}
