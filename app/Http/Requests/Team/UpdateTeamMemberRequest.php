<?php

namespace App\Http\Requests\Team;

use App\Enums\AccessRole;
use App\Enums\UserRole;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('usuario');

        if (! $target instanceof User || $target->is($this->user())) {
            return false;
        }

        $gabineteId = $this->user()?->gabinete_id;
        if ($gabineteId === null || ! $this->user()->canManageGabinete($gabineteId)) {
            return false;
        }

        return GabineteMembro::query()
            ->where('gabinete_id', $gabineteId)
            ->where('usuario_id', $target->id)
            ->whereIn('papel', $this->allowedRoles())
            ->exists();
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
