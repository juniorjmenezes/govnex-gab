<?php

namespace App\Http\Requests\Reports;

use App\Enums\ReportExportFormat;
use Illuminate\Validation\Rule;

class StoreReportExportRequest extends ReportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'formato' => ['required', Rule::enum(ReportExportFormat::class)],
        ];
    }
}
