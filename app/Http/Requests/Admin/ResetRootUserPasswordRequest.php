<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetRootUserPasswordRequest extends FormRequest
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
        return ['password' => ['required', 'confirmed', Password::defaults()]];
    }
}
