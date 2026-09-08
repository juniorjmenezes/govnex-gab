<?php

namespace App\Http\Requests\Admin;

use App\Models\Gabinete;
use Illuminate\Foundation\Http\FormRequest;

class CancelOfficePoliticalDataSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Gabinete
            && $this->user()->isRoot()
            && $this->user()->can('view', $office);
    }
}
