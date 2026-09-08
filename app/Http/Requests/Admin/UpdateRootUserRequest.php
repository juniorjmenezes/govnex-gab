<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRootUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('rootUsuario');

        if (! $target instanceof User || $target->is($this->user()) || $target->role !== UserRole::Root) {
            return false;
        }

        return $this->user()?->isRoot() === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
