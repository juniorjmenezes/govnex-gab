<?php

namespace App\Http\Requests\Admin;

use App\Enums\GabineteModule;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncFromGovnexApiRequest extends FormRequest
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
            'dataset' => ['required', Rule::in(TsePoliticalDataSyncService::DATASETS)],
            // A base de municípios não é publicada por ano (ver
            // YEARLESS_DATASETS): exigir ano ali barraria a sincronização.
            'ano' => [
                Rule::requiredIf(fn (): bool => TsePoliticalDataSyncService::datasetRequiresYear(
                    (string) $this->input('dataset'),
                )),
                'nullable',
                'integer',
                'min:2000',
                'max:2100',
            ],
        ];
    }
}
