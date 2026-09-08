<?php

namespace App\Http\Requests\Admin;

use App\Enums\GabineteModule;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\Tse\TseDatasetUrlBuilder;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadTseManualDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isRoot()
            && app(GabineteModuleManager::class)->anyActiveOffice(GabineteModule::Politics);
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('uf')) {
            $this->merge(['uf' => mb_strtoupper((string) $this->input('uf'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $dataset = $this->string('dataset')->toString();
        $maximumKilobytes = max(
            1,
            (int) config('services.tse.manual_upload_max_megabytes', 500),
        ) * 1024;
        $requiresUf = app(TseDatasetUrlBuilder::class)->requiresUf($dataset);

        return [
            'dataset' => [
                'required',
                'string',
                Rule::in(TsePoliticalDataSyncService::fileBasedDatasets()),
            ],
            'ano' => [
                Rule::requiredIf(TsePoliticalDataSyncService::datasetRequiresYear($dataset)),
                Rule::prohibitedIf(! TsePoliticalDataSyncService::datasetRequiresYear($dataset)),
                'nullable',
                'integer',
                'min:2000',
                'max:2100',
            ],
            'uf' => [
                Rule::requiredIf($requiresUf),
                Rule::prohibitedIf(! $requiresUf),
                'nullable',
                'string',
                'size:2',
                Rule::in(app(TsePoliticalDataSyncService::class)->registeredUfs()),
            ],
            'arquivo' => ['required', 'file', 'mimes:zip', "max:{$maximumKilobytes}"],
        ];
    }
}
