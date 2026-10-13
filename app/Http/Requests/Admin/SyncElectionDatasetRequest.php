<?php

namespace App\Http\Requests\Admin;

use App\Enums\GabineteModule;
use App\Models\Eleicao;
use App\Services\Modules\GabineteModuleManager;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sincronização de um dataset do TSE para uma eleição. O ano vem da própria
 * eleição — o pedido só diz qual dataset, e ele precisa valer para o tipo
 * dela.
 */
class SyncElectionDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRoot() === true
            && app(GabineteModuleManager::class)->anyActiveOffice(GabineteModule::Politics);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $election = $this->route('eleicao');
        $datasets = $election instanceof Eleicao
            ? TsePoliticalDataSyncService::datasetsFor($election->tipo)
            : [];

        return [
            'dataset' => ['required', 'string', Rule::in($datasets)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dataset.in' => 'Este dataset não se aplica ao tipo desta eleição.',
        ];
    }
}
