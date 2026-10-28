<?php

namespace App\Http\Requests\Admin;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;

class RestartOfficePoliticalDataSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office
            && $this->user()->isRoot()
            && $this->user()->can('view', $office);
    }
}
