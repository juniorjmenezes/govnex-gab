<?php

namespace App\Http\Requests\Admin;

use App\Models\Office;
use App\Services\Politics\OfficePoliticalDataSyncDispatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncOfficePoliticalDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office
            && $this->user()->isRoot()
            && $this->user()->can('view', $office);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(OfficePoliticalDataSyncDispatcher::TASKS),
            ],
        ];
    }
}
