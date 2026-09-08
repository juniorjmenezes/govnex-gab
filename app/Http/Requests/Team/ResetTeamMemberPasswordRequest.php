<?php

namespace App\Http\Requests\Team;

use App\Enums\GabineteRole;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetTeamMemberPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('usuario');

        if (! $target instanceof User || $target->is($this->user())) {
            return false;
        }

        $gabineteId = $this->user()?->gabinete_id;
        $allowedRoles = $this->user()->gabineteRole((int) $gabineteId) === GabineteRole::Manager
            ? [GabineteRole::Member->value]
            : [GabineteRole::Manager->value, GabineteRole::Member->value];

        return $gabineteId !== null
            && $this->user()->canManageGabinete($gabineteId)
            && GabineteMembro::query()
                ->where('gabinete_id', $gabineteId)
                ->where('usuario_id', $target->id)
                ->whereIn('papel', $allowedRoles)
                ->exists();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['password' => ['required', 'confirmed', Password::defaults()]];
    }
}
