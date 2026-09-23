<?php

namespace App\Http\Requests\Team;

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

        // Administrador redefine a senha de qualquer integrante do gabinete,
        // inclusive outros administradores; root nunca tem vínculo de gabinete.
        $gabineteId = $this->user()?->gabinete_id;

        return $gabineteId !== null
            && $this->user()->canManageGabinete($gabineteId)
            && GabineteMembro::query()
                ->where('gabinete_id', $gabineteId)
                ->where('usuario_id', $target->id)
                ->exists();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['password' => ['required', 'confirmed', Password::defaults()]];
    }
}
