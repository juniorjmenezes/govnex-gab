<?php

namespace App\Http\Requests\Admin;

use App\Enums\GabineteModule;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Foundation\Http\FormRequest;

class SyncElectorateFromGovnexApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isRoot()
            && app(GabineteModuleManager::class)->anyActiveOffice(GabineteModule::Politics);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ano' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
